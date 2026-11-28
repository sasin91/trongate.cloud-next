<?php
declare(strict_types=1);

require_once APPPATH . 'modules/security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/harbour/services/Module_spec.php';
require_once APPPATH . 'modules/harbour/services/Registry_embeddings.php';

/**
 * Persistence for the ZFS Module Registry:
 *  - Modules & immutable versions stored in `records` (`registry-module`, `registry-version`)
 *  - Author-scoped Smart HTTP Git push tokens in `registry_tokens` (SHA-256 hashed)
 *  - EmbeddingGemma 2 module vectors & query cache in `registry_embeddings` / `registry_query_cache`
 *  - Hybrid keyword + semantic search with Reciprocal Rank Fusion
 */
class Harbour_model extends Model {
    use Encrypted_credentials, Job_queue;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    public function _all_modules(): array {
        $modules = $this->records->_all('registry-module');
        usort($modules, static fn(array $a, array $b): int => ((int) ($b['updated_at'] ?? 0)) <=> ((int) ($a['updated_at'] ?? 0)));
        return $modules;
    }

    public function _find_module(string $namespace, string $name): ?array {
        $slug = Module_spec::slug($namespace, $name);
        return $this->records->_find(['kind' => 'registry-module', 'id' => $slug]);
    }

    public function _versions_for(string $namespace, string $name): array {
        $prefix = Module_spec::slug($namespace, $name) . '@';
        $out = [];
        foreach ($this->records->_all('registry-version') as $row) {
            if (str_starts_with((string) ($row['id'] ?? ''), $prefix)) {
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => version_compare((string) ($b['version'] ?? '0.0.0'), (string) ($a['version'] ?? '0.0.0')));
        return $out;
    }

    public function _find_version(string $namespace, string $name, string $version): ?array {
        $id = Module_spec::slug($namespace, $name) . '@' . Module_spec::validate_version($version);
        return $this->records->_find(['kind' => 'registry-version', 'id' => $id]);
    }

    /**
     * Recognises Harbour crates in a release from the `modules/<name>` fingerprints
     * its build recorded. A digest that equals a published version is that exact
     * version; a folder named like a Harbour module with an unknown digest has been
     * changed locally; anything else is the app's own module.
     *
     * @return array{installed: list<array<string, mixed>>, own: list<string>}
     */
    public function _identify(array $fingerprints): array {
        $by_digest = [];
        foreach ($this->records->_all('registry-version') as $ver) {
            $digest = (string) ($ver['digest'] ?? '');
            if ($digest !== '') {
                $by_digest[$digest] = $ver;
            }
        }
        $by_name = [];
        foreach ($this->_all_modules() as $mod) {
            $by_name[(string) ($mod['name'] ?? '')][] = $mod;
        }

        $installed = [];
        $own = [];
        foreach (Module_spec::fingerprints($fingerprints) as $fp) {
            $ver = $by_digest[$fp['digest']] ?? null;
            $module = $ver !== null
                ? $this->_find_module((string) $ver['namespace'], (string) $ver['name'])
                : ($by_name[$fp['name']][0] ?? null);
            if ($module === null) {
                $own[] = $fp['name'];
                continue;
            }

            $latest = (string) ($module['latest_version'] ?? '');
            $version = $ver !== null ? (string) $ver['version'] : '';
            $status = match (true) {
                $ver === null => 'modified',
                $latest !== '' && version_compare($latest, $version) > 0 => 'outdated',
                default => 'current',
            };
            $installed[] = [
                'folder' => $fp['name'],
                'namespace' => (string) $module['namespace'],
                'name' => (string) $module['name'],
                'title' => (string) ($module['title'] ?? $module['name']),
                'version' => $version,
                'latest_version' => $latest,
                'status' => $status,
                'repo_ready' => !empty($module['repo_ready']),
            ];
        }
        return ['installed' => $installed, 'own' => $own];
    }

    /**
     * Registers or updates a namespaced module repository record (owned by its author namespace).
     */
    public function _save_module_repo(array $input): array {
        $namespace = Module_spec::validate_namespace((string) ($input['namespace'] ?? ''));
        $name = Module_spec::validate_name((string) ($input['name'] ?? ''));
        $slug = $namespace . '/' . $name;
        $existing = $this->records->_find(['kind' => 'registry-module', 'id' => $slug]);
        $now = time();

        $title = trim((string) ($input['title'] ?? ($existing['title'] ?? ucwords(str_replace('_', ' ', $name)))));
        $summary = substr(trim((string) ($input['summary'] ?? ($existing['summary'] ?? ''))), 0, 280);
        $description = substr(trim((string) ($input['description'] ?? ($existing['description'] ?? ''))), 0, 4000);
        $license = substr(trim((string) ($input['license'] ?? ($existing['license'] ?? 'MIT'))), 0, 64);
        $author = substr(trim((string) ($input['author'] ?? ($existing['author'] ?? $namespace))), 0, 120);
        $category = Module_spec::normalize_category((string) ($input['category'] ?? ($existing['category'] ?? 'general')));
        $tags = isset($input['tags']) ? Module_spec::parse_tags($input['tags']) : (is_array($existing['tags'] ?? null) ? $existing['tags'] : []);
        $server = trim((string) ($input['server'] ?? ($existing['server'] ?? $this->_default_registry_server())));

        $data = [
            'namespace' => $namespace,
            'name' => $name,
            'slug' => $slug,
            'title' => $title !== '' ? $title : $name,
            'summary' => $summary,
            'description' => $description,
            'license' => $license !== '' ? $license : 'MIT',
            'author' => $author !== '' ? $author : $namespace,
            'category' => $category,
            'tags' => $tags,
            'server' => $server,
            'latest_version' => (string) ($existing['latest_version'] ?? ''),
            'readme' => (string) ($existing['readme'] ?? ''),
            'repo_ready' => (bool) ($input['repo_ready'] ?? ($existing['repo_ready'] ?? false)),
            'created_at' => (int) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];

        $this->records->_put(['kind' => 'registry-module', 'id' => $slug, 'data' => $data]);
        return ['id' => $slug] + $data;
    }

    /**
     * Records an immutable version materialized on ZFS by tc-runtime.
     */
    public function _record_published(array $result, string $server): array {
        $namespace = Module_spec::validate_namespace((string) ($result['namespace'] ?? ''));
        $name = Module_spec::validate_name((string) ($result['name'] ?? ''));
        $version = Module_spec::validate_version((string) ($result['version'] ?? ''));
        $slug = $namespace . '/' . $name;
        $ver_id = $slug . '@' . $version;
        $meta = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $now = time();

        $existing = $this->records->_find(['kind' => 'registry-module', 'id' => $slug]);
        $latest = (string) ($existing['latest_version'] ?? '');
        if ($latest === '' || version_compare($version, $latest) >= 0) {
            $latest = $version;
        }

        $module_data = [
            'namespace' => $namespace,
            'name' => $name,
            'slug' => $slug,
            'title' => (string) ($meta['title'] ?? ($existing['title'] ?? ucwords(str_replace('_', ' ', $name)))),
            'summary' => (string) (($meta['summary'] ?? '') !== '' ? $meta['summary'] : ($existing['summary'] ?? '')),
            'description' => (string) (($meta['description'] ?? '') !== '' ? $meta['description'] : ($existing['description'] ?? '')),
            'license' => (string) (($meta['license'] ?? '') !== '' ? $meta['license'] : ($existing['license'] ?? 'MIT')),
            'author' => (string) (($meta['author'] ?? '') !== '' ? $meta['author'] : ($existing['author'] ?? $namespace)),
            'category' => Module_spec::normalize_category((string) (($meta['category'] ?? '') !== '' ? $meta['category'] : ($existing['category'] ?? 'general'))),
            'tags' => !empty($meta['tags']) ? Module_spec::parse_tags($meta['tags']) : (is_array($existing['tags'] ?? null) ? $existing['tags'] : []),
            'server' => $server !== '' ? $server : (string) ($existing['server'] ?? ''),
            'latest_version' => $latest,
            'readme' => (string) (($meta['readme'] ?? '') !== '' ? $meta['readme'] : ($existing['readme'] ?? '')),
            'repo_ready' => !empty($result['repo_ready']) || !empty($existing['repo_ready']),
            'created_at' => (int) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];

        $version_data = [
            'namespace' => $namespace,
            'name' => $name,
            'slug' => $slug,
            'version' => $version,
            'ref' => (string) ($result['ref'] ?? ('v' . $version)),
            'commit' => (string) ($result['commit'] ?? ''),
            'digest' => (string) ($result['digest'] ?? ''),
            'dataset' => (string) ($result['dataset'] ?? ''),
            'snapshot' => (string) ($result['snapshot'] ?? ''),
            'archive_path' => (string) ($result['archive_path'] ?? ''),
            'archive_sha256' => (string) ($result['archive_sha256'] ?? ''),
            'archive_bytes' => (int) ($result['archive_bytes'] ?? 0),
            'file_count' => (int) ($result['file_count'] ?? 0),
            'total_bytes' => (int) ($result['total_bytes'] ?? 0),
            'server' => $server,
            'published_at' => (int) ($result['published_at'] ?? $now),
        ];

        $this->db->connection()->beginTransaction();
        try {
            $this->records->_put(['kind' => 'registry-module', 'id' => $slug, 'data' => $module_data]);
            $this->records->_put(['kind' => 'registry-version', 'id' => $ver_id, 'data' => $version_data]);
            $this->db->connection()->commit();
        } catch (Throwable $e) {
            $this->db->connection()->rollBack();
            throw $e;
        }

        return ['module' => ['id' => $slug] + $module_data, 'version' => ['id' => $ver_id] + $version_data];
    }

    public function _remove_version(string $namespace, string $name, string $version): void {
        $slug = Module_spec::slug($namespace, $name);
        $ver_id = $slug . '@' . Module_spec::validate_version($version);
        $this->records->_delete(['kind' => 'registry-version', 'id' => $ver_id]);

        $module = $this->records->_find(['kind' => 'registry-module', 'id' => $slug]);
        if ($module !== null) {
            $remaining = $this->_versions_for($namespace, $name);
            unset($module['id']);
            $module['latest_version'] = $remaining !== [] ? (string) ($remaining[0]['version'] ?? '') : '';
            $module['updated_at'] = time();
            $this->records->_put(['kind' => 'registry-module', 'id' => $slug, 'data' => $module]);
        }
    }

    // -----------------------------------------------------------------------
    // Author-Scoped Push Tokens (SHA-256 hashed at rest)
    // -----------------------------------------------------------------------

    /**
     * Mints a new author-scoped Git push token. The plaintext secret is returned once.
     *
     * @return array{id: string, namespace: string, label: string, token: string, token_prefix: string}
     */
    public function _create_token(string $namespace, string $label, string $created_by = ''): array {
        $namespace = Module_spec::validate_namespace($namespace);
        $label = substr(trim($label), 0, 120);
        if ($label === '') {
            $label = 'CLI publish token';
        }
        $id = bin2hex(random_bytes(16));
        $secret = 'tcreg_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $secret);
        $prefix = substr($secret, 0, 12);

        $this->db->query_bind(
            'INSERT INTO registry_tokens (id, namespace, label, token_hash, token_prefix, created_by, created_at, last_used_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)',
            [$id, $namespace, $label, $hash, $prefix, $created_by, time()],
            'statement'
        );

        return [
            'id' => $id,
            'namespace' => $namespace,
            'label' => $label,
            'token' => $secret,
            'token_prefix' => $prefix,
        ];
    }

    /**
     * Verifies a plaintext push token against an author namespace and updates last_used_at.
     */
    public function _verify_token(string $namespace, string $plaintext): ?array {
        $namespace = Module_spec::validate_namespace($namespace);
        if ($plaintext === '' || strlen($plaintext) > 200) {
            return null;
        }
        $hash = hash('sha256', $plaintext);
        $stmt = $this->db->query_bind(
            'SELECT id, namespace, label, token_prefix, created_by, created_at, last_used_at, revoked_at FROM registry_tokens WHERE token_hash = ? LIMIT 1',
            [$hash],
            'statement'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['revoked_at'] ?? 0) !== 0) {
            return null;
        }
        if (!hash_equals((string) $row['namespace'], $namespace)) {
            return null;
        }
        $this->db->query_bind(
            'UPDATE registry_tokens SET last_used_at = ? WHERE id = ?',
            [time(), (string) $row['id']],
            'statement'
        );
        return $row;
    }

    public function _list_tokens(?string $namespace = null): array {
        if ($namespace !== null && $namespace !== '') {
            $ns = Module_spec::validate_namespace($namespace);
            $stmt = $this->db->query_bind(
                'SELECT id, namespace, label, token_prefix, created_by, created_at, last_used_at, revoked_at FROM registry_tokens WHERE namespace = ? ORDER BY created_at DESC',
                [$ns],
                'statement'
            );
        } else {
            $stmt = $this->db->query_bind(
                'SELECT id, namespace, label, token_prefix, created_by, created_at, last_used_at, revoked_at FROM registry_tokens ORDER BY created_at DESC',
                [],
                'statement'
            );
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function _revoke_token(string $id): void {
        $id = Validate::match(trim($id), '/^[a-f0-9]{32}$/D', 'token id');
        $this->db->query_bind(
            'UPDATE registry_tokens SET revoked_at = ? WHERE id = ? AND revoked_at = 0',
            [time(), $id],
            'statement'
        );
    }

    // -----------------------------------------------------------------------
    // EmbeddingGemma 2 Vector Store & Hybrid Search (RRF)
    // -----------------------------------------------------------------------

    /**
     * @param list<float> $vector
     */
    public function _save_embedding(
        string $slug,
        string $model,
        string $model_version,
        int $dimensions,
        string $text_hash,
        array $vector,
    ): void {
        $blob = Registry_embeddings::pack_vector($vector);
        $now = time();
        $this->db->query_bind('DELETE FROM registry_embeddings WHERE module_slug = ?', [$slug], 'statement');
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO registry_embeddings (module_slug, model, model_version, dimensions, text_hash, embedding, embedded_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $slug, PDO::PARAM_STR);
        $stmt->bindValue(2, $model, PDO::PARAM_STR);
        $stmt->bindValue(3, $model_version, PDO::PARAM_STR);
        $stmt->bindValue(4, $dimensions, PDO::PARAM_INT);
        $stmt->bindValue(5, $text_hash, PDO::PARAM_STR);
        $stmt->bindValue(6, $blob, PDO::PARAM_LOB);
        $stmt->bindValue(7, $now, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @return array<string, array{text_hash: string, vector: list<float>, embedded_at: int}>
     */
    public function _embeddings_for_model(string $model, string $model_version, int $dimensions): array {
        $stmt = $this->db->query_bind(
            'SELECT module_slug, text_hash, embedding, embedded_at FROM registry_embeddings WHERE model = ? AND model_version = ? AND dimensions = ?',
            [$model, $model_version, $dimensions],
            'statement'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $blob = is_resource($row['embedding']) ? (string) stream_get_contents($row['embedding']) : (string) $row['embedding'];
            $vec = Registry_embeddings::unpack_vector($blob);
            if ($vec !== []) {
                $out[(string) $row['module_slug']] = [
                    'text_hash' => (string) $row['text_hash'],
                    'vector' => $vec,
                    'embedded_at' => (int) $row['embedded_at'],
                ];
            }
        }
        return $out;
    }

    /**
     * @return ?list<float>
     */
    public function _cached_query_vector(string $query, string $model, string $model_version, int $dimensions): ?array {
        $hash = hash('sha256', strtolower(trim($query)) . '|' . $model . '|' . $model_version . '|' . $dimensions);
        $stmt = $this->db->query_bind(
            'SELECT embedding FROM registry_query_cache WHERE query_hash = ? LIMIT 1',
            [$hash],
            'statement'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $blob = is_resource($row['embedding']) ? (string) stream_get_contents($row['embedding']) : (string) $row['embedding'];
        $vec = Registry_embeddings::unpack_vector($blob);
        return $vec !== [] ? $vec : null;
    }

    /**
     * @param list<float> $vector
     */
    public function _cache_query_vector(string $query, string $model, string $model_version, int $dimensions, array $vector): void {
        $clean = substr(trim($query), 0, 500);
        $hash = hash('sha256', strtolower($clean) . '|' . $model . '|' . $model_version . '|' . $dimensions);
        $blob = Registry_embeddings::pack_vector($vector);
        $this->db->query_bind('DELETE FROM registry_query_cache WHERE query_hash = ?', [$hash], 'statement');
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO registry_query_cache (query_hash, query_text, model, model_version, dimensions, embedding, cached_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $hash, PDO::PARAM_STR);
        $stmt->bindValue(2, $clean, PDO::PARAM_STR);
        $stmt->bindValue(3, $model, PDO::PARAM_STR);
        $stmt->bindValue(4, $model_version, PDO::PARAM_STR);
        $stmt->bindValue(5, $dimensions, PDO::PARAM_INT);
        $stmt->bindValue(6, $blob, PDO::PARAM_LOB);
        $stmt->bindValue(7, time(), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Counts indexed vs missing/outdated modules for the currently configured EmbeddingGemma model.
     *
     * @return array{total: int, indexed: int, outdated: int}
     */
    public function _embedding_stats(Registry_embeddings $client): array {
        $modules = $this->_all_modules();
        $vectors = $this->_embeddings_for_model($client->model(), $client->model_version(), $client->dimensions());
        $indexed = 0;
        $outdated = 0;
        foreach ($modules as $mod) {
            $slug = (string) ($mod['slug'] ?? $mod['id'] ?? '');
            $expected = Registry_embeddings::text_hash(Registry_embeddings::document_text($mod));
            if (isset($vectors[$slug]) && hash_equals($vectors[$slug]['text_hash'], $expected)) {
                $indexed++;
            } else {
                $outdated++;
            }
        }
        return [
            'total' => count($modules),
            'indexed' => $indexed,
            'outdated' => $outdated,
        ];
    }

    /**
     * Embeds any missing or outdated modules in batches.
     *
     * @return array{embedded: int, skipped: int}
     */
    public function _index_modules(Registry_embeddings $client, bool $force = false): array {
        $modules = $this->_all_modules();
        $existing = $force ? [] : $this->_embeddings_for_model($client->model(), $client->model_version(), $client->dimensions());
        $pending = [];
        $skipped = 0;
        foreach ($modules as $mod) {
            $slug = (string) ($mod['slug'] ?? $mod['id'] ?? '');
            $doc = Registry_embeddings::document_text($mod);
            $hash = Registry_embeddings::text_hash($doc);
            if (!$force && isset($existing[$slug]) && hash_equals($existing[$slug]['text_hash'], $hash)) {
                $skipped++;
                continue;
            }
            $pending[] = ['slug' => $slug, 'doc' => $doc, 'hash' => $hash];
        }

        $embedded = 0;
        foreach (array_chunk($pending, 16) as $batch) {
            $docs = array_map(static fn(array $item): string => $item['doc'], $batch);
            $vectors = $client->embed($docs, 'document');
            foreach ($batch as $idx => $item) {
                if (isset($vectors[$idx])) {
                    $this->_save_embedding(
                        $item['slug'],
                        $client->model(),
                        $client->model_version(),
                        $client->dimensions(),
                        $item['hash'],
                        $vectors[$idx]
                    );
                    $embedded++;
                }
            }
        }

        return ['embedded' => $embedded, 'skipped' => $skipped];
    }

    /**
     * Performs keyword, semantic, or hybrid (RRF) search over the module catalog.
     * Falls back gracefully to keyword search if EmbeddingGemma is unconfigured or slow.
     *
     * @return array{
     *   results: list<array<string, mixed>>,
     *   mode: string,
     *   effective_mode: string,
     *   fallback_notice: ?string
     * }
     */
    public function _search(
        string $query,
        string $category = '',
        string $mode = 'hybrid',
        ?Registry_embeddings $embeddings = null,
    ): array {
        $query = trim($query);
        $category = trim(strtolower($category));
        $mode = in_array($mode, ['hybrid', 'keyword', 'semantic'], true) ? $mode : 'hybrid';

        $all = $this->_all_modules();
        if ($category !== '' && array_key_exists($category, Module_spec::CATEGORIES)) {
            $all = array_values(array_filter(
                $all,
                static fn(array $m): bool => strtolower((string) ($m['category'] ?? 'general')) === $category
            ));
        }

        if ($query === '') {
            $results = array_map(static fn(array $m): array => $m + ['match' => null, 'score' => null, 'semantic_score' => null], $all);
            return [
                'results' => $results,
                'mode' => $mode,
                'effective_mode' => 'browse',
                'fallback_notice' => null,
            ];
        }

        $by_slug = [];
        foreach ($all as $mod) {
            $by_slug[(string) ($mod['slug'] ?? $mod['id'])] = $mod;
        }

        $keyword_scores = $this->_keyword_scores($all, $query);
        $semantic_scores = [];
        $fallback_notice = null;
        $effective_mode = $mode;

        if ($mode !== 'keyword') {
            $vectors = $embeddings !== null
                ? $this->_embeddings_for_model($embeddings->model(), $embeddings->model_version(), $embeddings->dimensions())
                : [];
            $query_vec = null;

            if ($embeddings !== null && $vectors !== []) {
                $query_vec = $this->_cached_query_vector($query, $embeddings->model(), $embeddings->model_version(), $embeddings->dimensions());
                if ($query_vec === null && $embeddings->configured()) {
                    try {
                        $embedded = $embeddings->embed([$query], 'query');
                        if (isset($embedded[0])) {
                            $query_vec = $embedded[0];
                            $this->_cache_query_vector($query, $embeddings->model(), $embeddings->model_version(), $embeddings->dimensions(), $query_vec);
                        }
                    } catch (Throwable) {
                        $fallback_notice = 'Semantic search is temporarily unavailable; showing keyword matches.';
                        $effective_mode = 'keyword';
                    }
                } elseif ($query_vec === null && !$embeddings->configured()) {
                    $effective_mode = 'keyword';
                }
            } else {
                $effective_mode = 'keyword';
            }

            if ($query_vec !== null) {
                foreach ($by_slug as $slug => $_mod) {
                    if (!isset($vectors[$slug])) {
                        continue;
                    }
                    $sim = Registry_embeddings::cosine($query_vec, $vectors[$slug]['vector']);
                    if ($sim >= Registry_embeddings::MIN_SIMILARITY) {
                        $semantic_scores[$slug] = $sim;
                    }
                }
                arsort($semantic_scores, SORT_NUMERIC);
                $semantic_scores = array_slice($semantic_scores, 0, 40, true);
            }
        }

        if ($effective_mode === 'keyword') {
            $fused = Registry_embeddings::fuse($keyword_scores, []);
        } elseif ($effective_mode === 'semantic') {
            $fused = Registry_embeddings::fuse([], $semantic_scores);
        } else {
            $fused = Registry_embeddings::fuse($keyword_scores, $semantic_scores);
        }

        // Pin exact slug or module name match to the top, like SKU pinning in semantic-webshop.
        $q_lower = strtolower($query);
        usort($fused, static function (array $a, array $b) use ($by_slug, $q_lower): int {
            $a_exact = $a['slug'] === $q_lower || strtolower((string) ($by_slug[$a['slug']]['name'] ?? '')) === $q_lower;
            $b_exact = $b['slug'] === $q_lower || strtolower((string) ($by_slug[$b['slug']]['name'] ?? '')) === $q_lower;
            if ($a_exact !== $b_exact) {
                return $a_exact ? -1 : 1;
            }
            return $b['score'] <=> $a['score'];
        });

        $results = [];
        foreach ($fused as $hit) {
            if (!isset($by_slug[$hit['slug']])) {
                continue;
            }
            $results[] = $by_slug[$hit['slug']] + [
                'match' => $hit['match'],
                'score' => round($hit['score'], 4),
                'semantic_score' => $hit['semantic_score'] !== null ? round($hit['semantic_score'], 3) : null,
            ];
        }

        return [
            'results' => $results,
            'mode' => $mode,
            'effective_mode' => $effective_mode,
            'fallback_notice' => $fallback_notice,
        ];
    }

    /**
     * @param list<array<string, mixed>> $modules
     * @return array<string, float>
     */
    private function _keyword_scores(array $modules, string $query): array {
        $q = strtolower(trim($query));
        $terms = array_values(array_filter(
            preg_split('/[\s\/._-]+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $t): bool => strlen($t) >= 2
        ));
        if ($terms === []) {
            $terms = [$q];
        }

        $scores = [];
        foreach ($modules as $mod) {
            $slug = strtolower((string) ($mod['slug'] ?? $mod['id'] ?? ''));
            $name = strtolower((string) ($mod['name'] ?? ''));
            $title = strtolower((string) ($mod['title'] ?? ''));
            $summary = strtolower((string) ($mod['summary'] ?? ''));
            $desc = strtolower((string) ($mod['description'] ?? ''));
            $readme = strtolower((string) ($mod['readme'] ?? ''));
            $tags = strtolower(implode(' ', is_array($mod['tags'] ?? null) ? $mod['tags'] : []));

            $score = 0.0;
            if ($slug === $q || $name === $q) {
                $score += 20.0;
            } elseif (str_contains($slug, $q) || str_contains($title, $q)) {
                $score += 8.0;
            }
            foreach ($terms as $term) {
                if ($name === $term || $slug === $term) {
                    $score += 6.0;
                } elseif (str_contains($name, $term) || str_contains($title, $term)) {
                    $score += 3.5;
                }
                if (str_contains($tags, $term)) {
                    $score += 3.0;
                }
                if (str_contains($summary, $term)) {
                    $score += 2.0;
                }
                if (str_contains($desc, $term) || str_contains($readme, $term)) {
                    $score += 1.0;
                }
            }
            if ($score > 0.0) {
                $scores[(string) ($mod['slug'] ?? $mod['id'])] = $score;
            }
        }
        arsort($scores, SORT_NUMERIC);
        return array_slice($scores, 0, 40, true);
    }

    // -----------------------------------------------------------------------
    // Queue Helpers for tc-runtime Registry Operations
    // -----------------------------------------------------------------------

    public function _default_registry_server(): string {
        foreach ($this->records->_all('server') as $server) {
            if (!empty($server['registry_ready']) || !empty($server['application_ready'])) {
                return (string) ($server['id'] ?? '');
            }
        }
        $servers = $this->records->_all('server');
        return $servers !== [] ? (string) ($servers[0]['id'] ?? '') : '';
    }

    public function _queue_init_repo(string $server, string $namespace, string $name): string {
        $server = Validate::id($server);
        $this->records->_get(['kind' => 'server', 'id' => $server]);
        return $this->enqueue('registry-init-repo', 'server:' . $server, [
            'server' => $server,
            'namespace' => Module_spec::validate_namespace($namespace),
            'name' => Module_spec::validate_name($name),
        ]);
    }

    public function _queue_publish(string $server, string $namespace, string $name, string $version, string $ref = '', string $subdir = ''): string {
        $server = Validate::id($server);
        $this->records->_get(['kind' => 'server', 'id' => $server]);
        $version = Module_spec::validate_version($version);
        return $this->enqueue('registry-publish', 'server:' . $server, [
            'server' => $server,
            'namespace' => Module_spec::validate_namespace($namespace),
            'name' => Module_spec::validate_name($name),
            'version' => $version,
            'ref' => $ref !== '' ? $ref : ('v' . $version),
            'subdir' => $subdir,
        ]);
    }

    public function _queue_upload(string $server, string $namespace, string $name, string $version, string $archive): string {
        $server = Validate::id($server);
        $this->records->_get(['kind' => 'server', 'id' => $server]);
        $version = Module_spec::validate_version($version);
        if ($archive === '' || strlen($archive) > Module_spec::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Module archive must be between 1 byte and 512 KiB.');
        }

        return $this->enqueue('registry-publish', 'server:' . $server, [
            'server' => $server,
            'namespace' => Module_spec::validate_namespace($namespace),
            'name' => Module_spec::validate_name($name),
            'version' => $version,
            'ref' => 'upload',
            'archive_b64' => base64_encode($archive),
        ]);
    }

    public function _queue_yank(string $server, string $namespace, string $name, string $version): string {
        $server = Validate::id($server);
        $this->records->_get(['kind' => 'server', 'id' => $server]);
        return $this->enqueue('registry-yank', 'server:' . $server, [
            'server' => $server,
            'namespace' => Module_spec::validate_namespace($namespace),
            'name' => Module_spec::validate_name($name),
            'version' => Module_spec::validate_version($version),
        ]);
    }
}
