<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/pcov_manifest_tools.php';

$options = getopt('', [
    'official-so:', 'fork-so:', 'output:', 'rounds::', 'requests::',
    'files::', 'statements::', 'files-per-request::',
]);
foreach (['official-so', 'fork-so', 'output'] as $required) {
    if (!isset($options[$required])) {
        fwrite(STDERR, "Missing --{$required}\n");
        exit(64);
    }
}
$rounds = (int) ($options['rounds'] ?? 10);
$requests = (int) ($options['requests'] ?? 10);
$fileCount = (int) ($options['files'] ?? 1500);
$statements = (int) ($options['statements'] ?? 40);
$filesPerRequest = (int) ($options['files-per-request'] ?? (int) ceil($fileCount / $requests));
if ($rounds < 1 || $requests < 1 || $fileCount < 1 || $statements < 1 ||
    $filesPerRequest < 1 || $requests * $filesPerRequest < $fileCount) {
    throw new InvalidArgumentException('Requests must cover every generated file in each round');
}

$root = sys_get_temp_dir() . '/pcov-public-benchmark-' . getmypid() . '-' . bin2hex(random_bytes(4));
$sources = $root . '/sources';
mkdir($sources, 0777, true);
$generator = __DIR__ . '/generate.php';
$worker = __DIR__ . '/worker.php';
$run = static function (array $command): array {
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start benchmark process');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [$status, $stdout, $stderr];
};
$extensionCommand = static function (string $extension, string $sourceDirectory): array {
    return [
        PHP_BINARY, '-n', '-d', "extension={$extension}", '-d', 'pcov.enabled=1',
        '-d', "pcov.directory={$sourceDirectory}",
    ];
};

[$status, , $stderr] = $run([PHP_BINARY, $generator, $sources, (string) $fileCount, (string) $statements]);
if ($status !== 0) {
    throw new RuntimeException("Fixture generation failed: {$stderr}");
}

$deploymentId = hash('sha256', 'pcov-public-large-codebase-benchmark-v1');
$manifest = $root . '/manifest.pcov';
$coldStarted = hrtime(true);
$fullPaths = [];
$bootstrapCpuUs = 0;
$bootstrapMaxRssKb = 0;
for ($request = 0; $request < $requests; $request++) {
    $full = sprintf('%s/full-%04d.pcov', $root, $request);
    $bootstrapCommand = $extensionCommand($options['fork-so'], $sources);
    array_push(
        $bootstrapCommand,
        '-d', 'pcov.large_codebase=1', $worker, 'full', $sources, $full,
        '0', (string) ($request * $filesPerRequest), (string) $filesPerRequest,
        '', $deploymentId
    );
    [$status, $stdout, $stderr] = $run($bootstrapCommand);
    if ($status !== 0) {
        throw new RuntimeException("Manifest bootstrap failed with status {$status}: {$stderr}");
    }
    $bootstrapRow = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
    $bootstrapCpuUs += $bootstrapRow['cpu_us'];
    $bootstrapMaxRssKb = max($bootstrapMaxRssKb, $bootstrapRow['max_rss_kb']);
    $fullPaths[] = $full;
}
pcov_manifest_bootstrap($manifest, $deploymentId, $fullPaths);
$coldManifestNs = hrtime(true) - $coldStarted;

$rawPath = $options['output'];
$failurePath = $rawPath . '.failures.jsonl';
$results = [];
for ($round = 0; $round < $rounds; $round++) {
    $order = $round % 2 === 0 ? ['official', 'large-codebase'] : ['large-codebase', 'official'];
    foreach ($order as $mode) {
        $directory = "{$root}/{$mode}-{$round}";
        mkdir($directory, 0777, true);
        $extension = $mode === 'official' ? $options['official-so'] : $options['fork-so'];
        $rows = [];
        $paths = [];
        for ($request = 0; $request < $requests; $request++) {
            $path = sprintf('%s/request-%04d.pcov', $directory, $request);
            $command = $extensionCommand($extension, $sources);
            if ($mode === 'large-codebase') {
                array_push($command, '-d', 'pcov.large_codebase=1');
            }
            array_push(
                $command,
                $worker, $mode, $sources, $path, (string) ($request + 1),
                (string) ($request * $filesPerRequest), (string) $filesPerRequest,
                $manifest, $deploymentId
            );
            [$status, $stdout, $stderr] = $run($command);
            if ($status !== 0) {
                file_put_contents($failurePath, json_encode([
                    'round' => $round, 'request' => $request, 'mode' => $mode,
                    'status' => $status, 'stderr' => $stderr,
                ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
                throw new RuntimeException("{$mode} round {$round} request {$request} failed: {$stderr}");
            }
            $rows[] = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
            $paths[] = $path;
        }

        $mergeCpuStarted = getrusage();
        $mergeStarted = hrtime(true);
        if ($mode === 'official') {
            $coverage = [];
            foreach ($paths as $path) {
                $requestCoverage = unserialize(file_get_contents($path), ['allowed_classes' => false]);
                foreach ($requestCoverage as $file => $lines) {
                    foreach ($lines as $line => $value) {
                        if (!isset($coverage[$file][$line]) || $value === 1) {
                            $coverage[$file][$line] = $value;
                        }
                    }
                }
            }
            $coverage = pcov_coverage_normalize($coverage);
        } else {
            $merged = pcov_manifest_merge($manifest, $paths);
            if (!$merged['coverage_complete'] || $merged['requires_full_discovery']) {
                throw new RuntimeException('Manifest merge is incomplete');
            }
            $coverage = $merged['coverage'];
        }
        $mergeNs = hrtime(true) - $mergeStarted;
        $mergeCpuEnded = getrusage();
        $mergeCpuUs = 0;
        foreach (['utime', 'stime'] as $kind) {
            $mergeCpuUs += ($mergeCpuEnded["ru_{$kind}.tv_sec"] - $mergeCpuStarted["ru_{$kind}.tv_sec"]) * 1000000
                + $mergeCpuEnded["ru_{$kind}.tv_usec"] - $mergeCpuStarted["ru_{$kind}.tv_usec"];
        }
        $result = [
            'round' => $round,
            'mode' => $mode,
            'php' => $rows[0]['php'],
            'pcov' => $rows[0]['pcov'],
            'files' => count($coverage),
            'executable_lines' => array_sum(array_map('count', $coverage)),
            'requests' => $requests,
            'wall_ns' => array_sum(array_column($rows, 'wall_ns')) + $mergeNs,
            'export_ns' => array_sum(array_column($rows, 'export_ns')),
            'merge_ns' => $mergeNs,
            'cpu_us' => array_sum(array_column($rows, 'cpu_us')) + $mergeCpuUs,
            'merge_cpu_us' => $mergeCpuUs,
            'minor_faults' => array_sum(array_column($rows, 'minor_faults')),
            'major_faults' => array_sum(array_column($rows, 'major_faults')),
            'max_rss_kb' => max(array_column($rows, 'max_rss_kb')),
            'output_bytes' => array_sum(array_column($rows, 'output_bytes')),
            'coverage_hash' => pcov_coverage_hash($coverage),
        ];
        $results[] = $result;
        file_put_contents($rawPath, json_encode($result, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }
}

$hashes = array_values(array_unique(array_column($results, 'coverage_hash')));
if (count($hashes) !== 1) {
    throw new RuntimeException('Official and large-codebase normalized coverage hashes differ');
}
$percentile = static function (array $values, float $fraction): int {
    sort($values, SORT_NUMERIC);
    return $values[(int) ceil(count($values) * $fraction) - 1];
};
$summary = [
    'environment' => [
        'php' => PHP_VERSION,
        'kernel' => implode(' ', [php_uname('s'), php_uname('r'), php_uname('v'), php_uname('m')]),
        'files' => $fileCount,
        'statements_per_file' => $statements,
        'files_per_request' => $filesPerRequest,
        'requests_per_round' => $requests,
        'rounds' => $rounds,
        'cold_manifest_ns' => $coldManifestNs,
        'cold_manifest_cpu_us' => $bootstrapCpuUs,
        'cold_manifest_max_rss_kb' => $bootstrapMaxRssKb,
        'manifest_bytes' => filesize($manifest),
    ],
    'coverage_hash' => $hashes[0],
    'modes' => [],
];
foreach (['official', 'large-codebase'] as $mode) {
    $rows = array_values(array_filter($results, static fn (array $row): bool => $row['mode'] === $mode));
    foreach (['wall_ns', 'cpu_us', 'export_ns', 'merge_ns', 'output_bytes', 'max_rss_kb', 'minor_faults'] as $metric) {
        $values = array_column($rows, $metric);
        $summary['modes'][$mode][$metric] = [
            'median' => $percentile($values, 0.5),
            'p95' => $percentile($values, 0.95),
        ];
    }
    $summary['modes'][$mode]['files'] = $rows[0]['files'];
    $summary['modes'][$mode]['executable_lines'] = $rows[0]['executable_lines'];
    $summary['modes'][$mode]['pcov'] = $rows[0]['pcov'];
}

$summaryPath = preg_replace('/\.jsonl$/', '', $rawPath) . '-summary.json';
file_put_contents($summaryPath, json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
