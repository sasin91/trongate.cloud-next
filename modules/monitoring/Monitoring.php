<?php
class Monitoring extends Trongate {
    public function __construct(?string $module_name = null) {
        parent::__construct($module_name ?? 'monitoring');
        if (PHP_SAPI !== 'cli') {
            block_url('monitoring');
        }
    }

    function _collect(): void {
        $ssh = new Ssh($this->model);
        foreach ($this->records->_all('server') as $server) {
            if (empty($server['application_ready']) && empty($server['builder_ready'])) {
                continue;
            }
            try {
                $response = $ssh->command($server, !empty($server['application_ready']) ? 'tc-runtime' : 'tc-build', self::_encode_json(['action' => 'metrics']), 20);
                $sample = json_decode($response['out'], true, 512, JSON_THROW_ON_ERROR);
                if ($response['exit'] !== 0 || !isset($sample['memory_total'], $sample['disk_total'], $sample['at'])) {
                    throw new RuntimeException('Metrics unavailable');
                }
                $this->model->_record($server['id'], $sample);
            } catch (Throwable) {
                // Preserve the last sample; the view marks stale data instead of showing zero.
            }
        }
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
