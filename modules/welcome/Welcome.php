<?php
declare(strict_types=1);

/**
 * Public landing page and the sample app overview embedded in its hero.
 */
class Welcome extends Trongate {

    /**
     * The landing page in the trongate.cloud palette.
     */
    public function index(): void {
        $this->view('default_homepage', [
            'apps_domain' => Apps_model::apps_domain(),
        ]);
    }

    /**
     * The real app overview filled with sample data, framed by the landing page's hero.
     * Rendered in a same-origin iframe so dashboard styles stay apart from the landing page's.
     */
    public function preview(): void {
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'self'; form-action 'self'");

        $apps_domain = Apps_model::apps_domain();
        $now = time();
        $minutes_ago = static fn(int $minutes): int => $now - ($minutes * 60);

        $releases = [
            [
                'id' => 'rel48000',
                'metadata' => [
                    'commit' => '4f2b8d1c9a0e7f3a1b2c3d4e5f6a7b8c9d0e1f2a',
                    'message' => 'Cache product catalog queries on storefront home',
                ],
                'spec' => ['php_version' => '8.4', 'health' => '/'],
            ],
            [
                'id' => 'rel47000',
                'metadata' => [
                    'commit' => 'a91c03e7f214b8d0c1a2b3c4d5e6f7a8b9c0d1e2',
                    'message' => 'Add VAT breakdown to checkout summary',
                ],
                'spec' => ['php_version' => '8.4', 'health' => '/'],
            ],
            [
                'id' => 'rel45000',
                'metadata' => [
                    'commit' => '7c1f9e0b3d52a4c6e8f0a1b2c3d4e5f6a7b8c9d0',
                    'message' => 'Initial storefront release',
                ],
                'spec' => ['php_version' => '8.4', 'health' => '/'],
            ],
        ];

        $deployments = [
            [
                'id' => 'dep48000',
                'status' => 'succeeded',
                'commit' => $releases[0]['metadata']['commit'],
                'trigger' => 'push',
                'started_at' => $minutes_ago(3),
                'completed_at' => $minutes_ago(2),
                'release_id' => 'rel48000',
                'is_live' => true,
                'slot' => 'green',
            ],
            [
                'id' => 'dep47000',
                'status' => 'succeeded',
                'commit' => $releases[1]['metadata']['commit'],
                'trigger' => 'push',
                'started_at' => $minutes_ago(96),
                'completed_at' => $minutes_ago(95),
                'release_id' => 'rel47000',
                'is_live' => false,
                'slot' => 'blue',
            ],
            [
                'id' => 'dep46000',
                'status' => 'failed',
                'commit' => '0d7e52b4aa918273645566778899aabbccddeeff',
                'trigger' => 'push',
                'started_at' => $minutes_ago(141),
                'completed_at' => $minutes_ago(140),
                'release_id' => '',
                'is_live' => false,
                'slot' => 'green',
            ],
            [
                'id' => 'dep45000',
                'status' => 'succeeded',
                'commit' => $releases[2]['metadata']['commit'],
                'trigger' => 'manual',
                'started_at' => $minutes_ago(1501),
                'completed_at' => $minutes_ago(1500),
                'release_id' => 'rel45000',
                'is_live' => false,
                'slot' => 'blue',
            ],
        ];

        $pattern = [
            [0, 1, 18], [0, 0, 12], [0, 2, 20], [0, 1, 15], [0, 0, 14], [0, 1, 19],
            [0, 0, 16], [1, 3, 28], [0, 2, 24], [0, 1, 21], [0, 0, 17], [0, 1, 22],
            [0, 2, 26], [0, 1, 20], [0, 0, 18], [1, 2, 31], [0, 1, 23], [0, 0, 19],
            [0, 1, 21], [0, 0, 16], [0, 1, 20], [0, 0, 18], [0, 1, 22], [0, 0, 19],
        ];
        $log_rates = [];
        foreach ($pattern as $i => [$errors, $warnings, $total]) {
            $hour_ts = $now - ((23 - $i) * 3600);
            $log_rates[] = [
                'hour' => gmdate('Y-m-d\TH:00:00\Z', $hour_ts),
                'errors' => $errors,
                'warnings' => $warnings,
                'total' => $total,
            ];
        }

        $overview = [
            'app' => [
                'id' => 'storefront',
                'label' => 'Storefront',
                'domain' => 'storefront.' . $apps_domain,
                'repository' => 'acme/storefront',
                'branch' => 'main',
                'php_version' => '8.4',
            ],
            'settings' => [
                'branch' => 'main',
                'health' => '/',
            ],
            'releases' => $releases,
            'latest_release' => $releases[0],
            'deployments' => $deployments,
            'current' => $deployments[0],
            'live_state' => ['state' => 'running', 'label' => 'Running', 'detail' => ''],
            'canary' => null,
            'services' => [
                ['name' => 'worker', 'status' => 'running'],
            ],
            'can_rollback' => true,
            'database_name' => 'tc_storefront',
        ];

        $this->view('preview', [
            'overview' => $overview,
            'log_rates' => $log_rates,
        ]);
    }

}
