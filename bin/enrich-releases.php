<?php
declare(strict_types=1);
require dirname(__DIR__) . '/modules/operations/bootstrap.php';

// One-off enrichment for releases created before source metadata was recorded.
// Never change the immutable runtime spec or release identity.
$store = new Apps_model('apps');
$builds = $store->run("SELECT id,payload,result,created FROM jobs WHERE kind='build' AND status='succeeded' ORDER BY created")->fetchAll();
$cache = [];
foreach ($store->all('release') as $release) {
    if (!empty($release['metadata']['message']) && !empty($release['created'])) {
        continue;
    }
    foreach ($builds as $build) {
        $payload = json_decode($build['payload'], true);
        $result = json_decode($build['result'] ?? '{}', true);
        if (($result['image'] ?? '') !== $release['spec']['image'] || ($payload['launch']['app'] ?? '') !== $release['app']) {
            continue;
        }
        $repository = $payload['plan']['repository'] ?? '';
        $sha = $payload['plan']['commit'] ?? '';
        try {
            $repository = Apps_model::_repository($repository);
            Validate::match($sha, '/^[a-f0-9]{40}$/D', 'source commit');
            $key = $repository . '/' . $sha;
            if (!isset($cache[$key])) {
                $commit = Apps_model::_github($repository . '/commits/' . $sha);
                $cache[$key] = Apps_model::_release_metadata($repository, $commit);
            }
            $release['metadata'] = $cache[$key];
            // Historical jobs preserve the queue time, not the completion time.
            $completed = $store->run("SELECT MAX(at) FROM steps WHERE operation=? AND step='observed'", [$build['id']])->fetchColumn();
            if ($completed) {
                $release['created'] = (int) $completed;
            }
            $id = $release['id'];
            unset($release['id']);
            $store->put('release', $id, $release);
            echo 'Enriched ' . substr($id, 0, 10) . PHP_EOL;
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'Metadata unavailable for ' . substr($release['id'], 0, 10) . PHP_EOL);
        }
        break;
    }
}
