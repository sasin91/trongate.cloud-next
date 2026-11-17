<?php
interface HostDeployer {
    function deploy(array $server,array $deployment,callable $heartbeat): array;
    function inspect(array $server): array;
    function rollback(array $server,array $deployment,callable $heartbeat): array;
    function reconcile(array $server,array $deployment): array;
}
