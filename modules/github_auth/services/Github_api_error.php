<?php
declare(strict_types=1);

/**
 * GitHub API or OAuth error carrying the HTTP status code.
 */
final class Github_api_error extends RuntimeException {
    public function __construct(string $message, int $status = 0, ?Throwable $previous = null) {
        parent::__construct($message, $status, $previous);
    }
}
