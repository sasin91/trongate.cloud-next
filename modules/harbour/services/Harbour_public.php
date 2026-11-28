<?php
declare(strict_types=1);

/**
 * Public Module Harbour mode for the unprivileged `tc_registry` jail.
 *
 * The jail runs this same source with `TC_REGISTRY_MODE=1`, but it must only
 * serve the anonymous, read-only Module Harbour. Login, apps, servers, publishing
 * and every other mutation stay on `tc_panel`, so any other route is answered
 * with a 404 before `Core` dispatches it.
 */
final class Harbour_public {
    private const METHODS = ['index', 'show', 'api_modules', 'api_module', 'archive', 'git'];
    private const ASSET_MODULES = ['harbour', 'templates'];

    public static function enabled(): bool {
        return getenv('TC_REGISTRY_MODE') === '1';
    }

    /**
     * Mirrors `Core`'s module/method normalization so a route cannot slip past
     * this check and still resolve to another controller.
     *
     * @param list<string> $segments The routed `SEGMENTS`
     */
    public static function allows(array $segments, string $assumed_url): bool {
        if (strpos($assumed_url, '/vendor/') !== false) {
            return false;
        }

        foreach (array_values($segments) as $index => $segment) {
            if (str_ends_with((string) $segment, MODULE_ASSETS_TRIGGER) && count($segments) - $index >= 3) {
                $module = substr((string) $segment, 0, -strlen(MODULE_ASSETS_TRIGGER));
                return ($segments[1] ?? '') === $segment
                    && in_array(strtolower($module), self::ASSET_MODULES, true);
            }
        }

        $module = preg_replace('/[^a-z0-9-_]/', '', strtolower(explode('?', (string) ($segments[1] ?? ''))[0]));
        if ($module !== '' && $module !== 'harbour') {
            return false;
        }

        $method = strtolower(explode('?', (string) ($segments[2] ?? ''))[0]);
        return $method === '' || in_array($method, self::METHODS, true);
    }

    public static function enforce(): void {
        if (!self::enabled() || self::allows(SEGMENTS, ASSUMED_URL)) {
            return;
        }

        http_response_code(404);
        (new Error_pages('error_pages'))->not_found();
        exit;
    }
}
