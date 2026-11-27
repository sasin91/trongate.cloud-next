<?php
declare(strict_types=1);

final class Runtime_environment {
    public readonly string $root;
    public readonly bool $production;

    private function __construct(string $root, bool $production) {
        $this->root = $root;
        $this->production = $production;
    }

    public static function production(): self {
        return new self('/var/db/tc/runtime', true);
    }

    public static function isolated(string $root): self {
        return new self($root, false);
    }

    public static function for_root(string $root): self {
        return $root === '/var/db/tc/runtime'
            ? self::production()
            : self::isolated($root);
    }
}
