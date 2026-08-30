<?php

require_once __DIR__ . '/pcov_manifest_tools.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/pcov_manifest.php create OUT ENVIRONMENT_ID FULL_RECORD...\n");
    fwrite(STDERR, "  php tools/pcov_manifest.php merge COVERAGE_PHP MANIFEST RECORD...\n");
    fwrite(STDERR, "  php tools/pcov_manifest.php update OUT MANIFEST INVENTORY_JSON RECORD...\n");
    fwrite(STDERR, "  php tools/pcov_manifest.php inspect RECORD\n");
    exit(64);
};

try {
    $command = $argv[1] ?? null;
    if ($command === 'create' && count($argv) >= 5) {
        $result = pcov_manifest_bootstrap(
            $argv[2], $argv[3], array_slice($argv, 4)
        );
    } elseif ($command === 'merge' && count($argv) >= 5) {
        $result = pcov_manifest_merge($argv[3], array_slice($argv, 4));
        if (!$result['coverage_complete']) {
            throw new RuntimeException(
                'Coverage includes a full fallback; rebuild the manifest before claiming completeness'
            );
        }
        pcov_atomic_publish(
            $argv[2],
            "<?php\nreturn " . var_export($result['coverage'], true) . ";\n"
        );
        $result['coverage_path'] = $argv[2];
        $result['normalized_hash'] = pcov_coverage_hash($result['coverage']);
        unset($result['coverage'], $result['candidate_files']);
    } elseif ($command === 'update' && count($argv) >= 6) {
        $inventoryPaths = json_decode(
            file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR
        );
        if (!is_array($inventoryPaths) || !array_is_list($inventoryPaths)) {
            throw new RuntimeException('Inventory JSON must contain a list of canonical source paths');
        }
        $merge = pcov_manifest_merge($argv[3], array_slice($argv, 5));
        $result = pcov_manifest_publish_candidate(
            $argv[2], $merge, pcov_manifest_source_inventory($inventoryPaths)
        );
        $result['base_manifest_id'] = $merge['base_manifest_id'];
        $result['delta_files'] = $merge['delta_files'];
    } elseif ($command === 'inspect' && count($argv) === 3) {
        $record = pcov_record_load($argv[2]);
        $result = [
            'version' => $record['version'],
            'record_type' => $record['record_type'],
            'environment_id' => $record['environment_id'],
            'manifest_id' => $record['manifest_id'],
            'reason' => $record['reason'],
            'files' => count($record['records']),
            'normalized_hash' => pcov_coverage_hash($record['records']),
        ];
    } else {
        $usage();
    }

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
