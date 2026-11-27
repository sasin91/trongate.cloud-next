<?php
declare(strict_types=1);

/**
 * EmbeddingGemma 2 semantic & hybrid search client for the Module Marketplace.
 *
 * Follows the proven semantic-webshop design:
 *  - OpenAI-compatible POST /v1/embeddings served by llama.cpp (EmbeddingGemma 2 GGUF)
 *  - Model-card prompts:
 *      query:    "task: search result | query: {text}"
 *      document: "title: {title} | text: {summary}. {tags}. {description}"
 *  - Matryoshka dimension truncation (768, 512, 256, 128) + L2 unit normalization
 *  - Little-endian float32 BLOB packing (works on MariaDB 11.4, SQLite tests, and 11.7+)
 *  - Reciprocal Rank Fusion (k = 20, keyword weight = 0.5, semantic weight = 1.0)
 *  - 2-second query deadline with graceful fallback to keyword search
 */
final class Registry_embeddings {
    public const DEFAULT_MODEL = 'google/embeddinggemma-2';
    public const DEFAULT_MODEL_VERSION = 'ggml-org/embeddinggemma-2-GGUF Q8_0';
    public const DEFAULT_DIMENSIONS = 768;
    public const ALLOWED_DIMENSIONS = [768, 512, 256, 128];
    public const QUERY_TIMEOUT_SECONDS = 2;
    public const DOCUMENT_TIMEOUT_SECONDS = 30;
    public const RRF_K = 20;
    public const KEYWORD_WEIGHT = 0.5;
    public const SEMANTIC_WEIGHT = 1.0;
    public const MIN_SIMILARITY = 0.55;

    private string $url;
    private string $model;
    private string $model_version;
    private int $dimensions;

    public function __construct(
        private Http_client $http,
        ?string $url = null,
        ?string $model = null,
        ?string $model_version = null,
        ?int $dimensions = null,
    ) {
        $this->url = rtrim(trim($url ?? (string) (getenv('EMBEDDINGS_URL') ?: '')), '/');
        $this->model = trim($model ?? (string) (getenv('EMBEDDINGS_MODEL') ?: self::DEFAULT_MODEL));
        $this->model_version = trim($model_version ?? (string) (getenv('EMBEDDINGS_MODEL_VERSION') ?: self::DEFAULT_MODEL_VERSION));
        $dims = $dimensions ?? (int) (getenv('EMBEDDINGS_DIMENSIONS') ?: self::DEFAULT_DIMENSIONS);
        $this->dimensions = in_array($dims, self::ALLOWED_DIMENSIONS, true) ? $dims : self::DEFAULT_DIMENSIONS;
    }

    public function configured(): bool {
        return $this->url !== '';
    }

    public function url(): string {
        return $this->url;
    }

    public function model(): string {
        return $this->model;
    }

    public function model_version(): string {
        return $this->model_version;
    }

    public function dimensions(): int {
        return $this->dimensions;
    }

    public static function query_prompt(string $query): string {
        return 'task: search result | query: ' . trim($query);
    }

    public static function document_text(array $module): string {
        $title = trim((string) ($module['title'] ?? ($module['name'] ?? '')));
        $slug = trim((string) (($module['namespace'] ?? '') . '/' . ($module['name'] ?? '')), '/');
        $summary = trim((string) ($module['summary'] ?? ''));
        $description = trim((string) ($module['description'] ?? ''));
        $category = trim((string) ($module['category'] ?? ''));
        $tags = is_array($module['tags'] ?? null) ? implode(', ', $module['tags']) : trim((string) ($module['tags'] ?? ''));
        $readme = trim((string) ($module['readme'] ?? ''));
        if (strlen($readme) > 1200) {
            $readme = substr($readme, 0, 1200);
        }

        $parts = array_filter([
            $slug !== '' ? 'Module ' . $slug : '',
            $category !== '' ? 'Category: ' . $category : '',
            $tags !== '' ? 'Tags: ' . $tags : '',
            $summary,
            $description,
            $readme,
        ], static fn(string $s): bool => $s !== '');

        return 'title: ' . ($title !== '' ? $title : $slug) . ' | text: ' . implode('. ', $parts);
    }

    public static function text_hash(string $text): string {
        return hash('sha256', $text);
    }

    /**
     * Checks whether the llama-server EmbeddingGemma endpoint is healthy.
     *
     * @return array{configured: bool, ok: bool, latency_ms: ?int, error: ?string}
     */
    public function health(): array {
        if (!$this->configured()) {
            return ['configured' => false, 'ok' => false, 'latency_ms' => null, 'error' => 'EMBEDDINGS_URL not configured'];
        }
        $start = microtime(true);
        try {
            $res = $this->http->request('GET', $this->url . '/health', [], null, 2, 2);
            $ms = (int) round((microtime(true) - $start) * 1000);
            $ok = $res['status'] >= 200 && $res['status'] < 300;
            return [
                'configured' => true,
                'ok' => $ok,
                'latency_ms' => $ms,
                'error' => $ok ? null : ('HTTP ' . $res['status']),
            ];
        } catch (Throwable $e) {
            return [
                'configured' => true,
                'ok' => false,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Embeds one or more texts via llama.cpp's OpenAI-compatible /v1/embeddings endpoint.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, string $mode = 'query'): array {
        if ($texts === []) {
            return [];
        }
        if (!$this->configured()) {
            throw new RuntimeException('Embeddings service is not configured.');
        }

        $inputs = [];
        foreach ($texts as $text) {
            $inputs[] = $mode === 'query' ? self::query_prompt($text) : $text;
        }

        $timeout = $mode === 'query' ? self::QUERY_TIMEOUT_SECONDS : self::DOCUMENT_TIMEOUT_SECONDS;
        $payload = json_encode([
            'model' => $this->model,
            'input' => $inputs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response = $this->http->request(
            'POST',
            $this->url . '/v1/embeddings',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $payload,
            min(2, $timeout),
            $timeout
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Embeddings service returned HTTP ' . $response['status']);
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new RuntimeException('Unexpected embeddings service response.');
        }

        $by_index = [];
        foreach ($decoded['data'] as $idx => $item) {
            if (!is_array($item) || !is_array($item['embedding'] ?? null)) {
                throw new RuntimeException('Missing embedding vector in response.');
            }
            $pos = isset($item['index']) && is_int($item['index']) ? $item['index'] : (int) $idx;
            $by_index[$pos] = self::normalize($item['embedding'], $this->dimensions);
        }
        ksort($by_index);
        if (count($by_index) !== count($inputs)) {
            throw new RuntimeException('Embeddings count mismatch.');
        }
        return array_values($by_index);
    }

    /**
     * Truncates a Matryoshka vector to $dimensions and normalizes it to unit L2 length.
     *
     * @param list<int|float> $vector
     * @return list<float>
     */
    public static function normalize(array $vector, int $dimensions = self::DEFAULT_DIMENSIONS): array {
        $slice = array_slice($vector, 0, $dimensions);
        $sum_sq = 0.0;
        $floats = [];
        foreach ($slice as $v) {
            $f = (float) $v;
            $floats[] = $f;
            $sum_sq += $f * $f;
        }
        $norm = sqrt($sum_sq);
        if ($norm <= 0.0) {
            return $floats;
        }
        return array_map(static fn(float $f): float => $f / $norm, $floats);
    }

    /**
     * Packs a float vector into little-endian float32 binary BLOB format.
     *
     * @param list<float> $vector
     */
    public static function pack_vector(array $vector): string {
        return pack('g*', ...$vector);
    }

    /**
     * Unpacks a little-endian float32 binary BLOB back into a float array.
     *
     * @return list<float>
     */
    public static function unpack_vector(string $blob): array {
        if ($blob === '' || (strlen($blob) % 4) !== 0) {
            return [];
        }
        $unpacked = unpack('g*', $blob);
        return is_array($unpacked) ? array_values($unpacked) : [];
    }

    /**
     * Cosine similarity between two unit-normalized vectors (dot product).
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosine(array $a, array $b): float {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
        }
        return $dot;
    }

    /**
     * Reciprocal Rank Fusion combining keyword rankings and semantic rankings.
     *
     * @param array<string, float> $keyword_scores  module_slug => keyword score (sorted best-first)
     * @param array<string, float> $semantic_scores module_slug => cosine similarity (sorted best-first)
     * @return list<array{slug: string, score: float, match: string, keyword_score: ?float, semantic_score: ?float}>
     */
    public static function fuse(
        array $keyword_scores,
        array $semantic_scores,
        int $k = self::RRF_K,
        float $keyword_weight = self::KEYWORD_WEIGHT,
        float $semantic_weight = self::SEMANTIC_WEIGHT,
    ): array {
        $combined = [];

        $rank = 1;
        foreach ($keyword_scores as $slug => $score) {
            $combined[$slug] = [
                'slug' => (string) $slug,
                'score' => $keyword_weight / ($k + $rank),
                'match' => 'keyword',
                'keyword_score' => (float) $score,
                'semantic_score' => null,
            ];
            $rank++;
        }

        $rank = 1;
        foreach ($semantic_scores as $slug => $sim) {
            $slug = (string) $slug;
            $add = $semantic_weight / ($k + $rank);
            if (isset($combined[$slug])) {
                $combined[$slug]['score'] += $add;
                $combined[$slug]['match'] = 'both';
                $combined[$slug]['semantic_score'] = (float) $sim;
            } else {
                $combined[$slug] = [
                    'slug' => $slug,
                    'score' => $add,
                    'match' => 'semantic',
                    'keyword_score' => null,
                    'semantic_score' => (float) $sim,
                ];
            }
            $rank++;
        }

        usort($combined, static function (array $a, array $b): int {
            $cmp = $b['score'] <=> $a['score'];
            return $cmp !== 0 ? $cmp : strcmp($a['slug'], $b['slug']);
        });

        return array_values($combined);
    }
}
