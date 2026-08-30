<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/pcov_manifest_tools.php';

[$script, $mode, $sourceDirectory, $outputPath, $seed, $offset, $limit, $manifest, $deploymentId] =
    $argv + [null, null, null, null, '0', '0', '0', null, null];
if (!in_array($mode, ['official', 'full', 'large-codebase'], true) ||
    !is_dir((string) $sourceDirectory)) {
    fwrite(
        STDERR,
        "Usage: php worker.php official|full|large-codebase SOURCE OUT SEED OFFSET LIMIT [MANIFEST DEPLOYMENT]\n"
    );
    exit(64);
}

$usage = static function (): array {
    $usage = getrusage();
    return [
        'cpu_us' => (($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000) +
            $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'],
        'minor_faults' => $usage['ru_minflt'],
        'major_faults' => $usage['ru_majflt'],
        'max_rss_kb' => $usage['ru_maxrss'],
    ];
};

$before = $usage();
$wallStarted = hrtime(true);
$files = glob($sourceDirectory . '/*.php');
sort($files, SORT_STRING);
$selected = [];
$count = count($files);
$take = min((int) $limit, $count);
for ($index = 0; $index < $take; $index++) {
    $selected[] = $files[((int) $offset + $index) % $count];
}
$files = $selected;
foreach ($files as $file) {
    require $file;
}

$workloadChecksum = 0;
pcov\clear();
pcov\start();
foreach ($files as $file) {
    $function = basename($file, '.php');
    $workloadChecksum ^= $function((int) $seed);
}
pcov\stop();

$exportStarted = hrtime(true);
$resultMode = $mode;
if ($mode === 'official') {
    pcov_atomic_publish($outputPath, serialize(pcov\collect()));
} elseif ($mode === 'full') {
    if ($deploymentId === null) {
        throw new RuntimeException('Bootstrap requires a deployment identity');
    }
    $result = pcov\export($outputPath, null, $deploymentId);
    if (!is_array($result) || $result['mode'] !== 'full') {
        throw new RuntimeException('Native full bootstrap export failed');
    }
    $resultMode = 'full';
} else {
    if ($manifest === null || $deploymentId === null) {
        throw new RuntimeException('Large-codebase request requires manifest and deployment identity');
    }
    $result = pcov\export($outputPath, $manifest, $deploymentId);
    if (!is_array($result) || $result['mode'] !== 'hit-only') {
        throw new RuntimeException('Warm manifest request did not use hit-only export');
    }
    $resultMode = $result['mode'];
}
$exportNs = hrtime(true) - $exportStarted;
$after = $usage();

echo json_encode([
    'mode' => $mode,
    'result_mode' => $resultMode,
    'php' => PHP_VERSION,
    'pcov' => constant('pcov\\version'),
    'loaded_files' => count($files),
    'wall_ns' => hrtime(true) - $wallStarted,
    'export_ns' => $exportNs,
    'cpu_us' => $after['cpu_us'] - $before['cpu_us'],
    'minor_faults' => $after['minor_faults'] - $before['minor_faults'],
    'major_faults' => $after['major_faults'] - $before['major_faults'],
    'max_rss_kb' => $after['max_rss_kb'],
    'output_bytes' => filesize($outputPath),
    'workload_checksum' => $workloadChecksum,
], JSON_THROW_ON_ERROR), "\n";
