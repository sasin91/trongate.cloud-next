<?php
declare(strict_types=1);

final readonly class Job_context {
    public function __construct(
        public string $id,
        public string $kind,
        public array $payload,
        private Closure $renew_lease
    ) {}

    public function heartbeat(): void {
        ($this->renew_lease)();
    }
}
