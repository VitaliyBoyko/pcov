<?php

require_once __DIR__ . '/pcov_record_loader.php';

const PCOV_MANIFEST_IDENTITY_SCHEMA = 1;

/**
 * Recursively normalize an identity document so its JSON representation is
 * independent of insertion order. Lists retain order; object keys do not.
 */
function pcov_manifest_identity_normalize(mixed $value): mixed
{
    if (!is_array($value)) {
        if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
            throw new InvalidArgumentException('Manifest identity values must be scalar or arrays');
        }
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('pcov_manifest_identity_normalize', $value);
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        if (!is_string($key) || $key === '' || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Manifest identity object keys must be non-empty strings');
        }
        $normalized[$key] = pcov_manifest_identity_normalize($item);
    }
    ksort($normalized, SORT_STRING);

    return $normalized;
}

/**
 * Build the opaque environment identity referenced by manifests and dumps.
 *
 * The caller-provided deployment values should identify application revision,
 * dependency lockfile, enabled modules/configuration, generated code, and the
 * relevant source-tree inventory. They are deliberately explicit: guessing
 * any of them from an individual request could claim freshness incorrectly.
 *
 * @return array{document:array<string,mixed>,canonical_json:string,id:string}
 */
function pcov_manifest_environment_identity(array $deployment): array
{
    $required = [
        'application_revision',
        'dependency_lock',
        'modules_config',
        'generated_code',
        'source_tree',
    ];
    foreach ($required as $key) {
        if (!array_key_exists($key, $deployment) ||
            !is_string($deployment[$key]) || $deployment[$key] === '' ||
            str_contains($deployment[$key], "\0")) {
            throw new InvalidArgumentException("Missing or invalid manifest identity component: {$key}");
        }
    }

    $document = pcov_manifest_identity_normalize([
        'schema' => PCOV_MANIFEST_IDENTITY_SCHEMA,
        'runtime' => [
            'php_version' => PHP_VERSION,
            'php_version_id' => PHP_VERSION_ID,
            'php_zts' => PHP_ZTS,
            'php_debug' => PHP_DEBUG,
            'php_int_size' => PHP_INT_SIZE,
            'php_os_family' => PHP_OS_FAMILY,
            'pcov_version' => defined('pcov\\version') ? constant('pcov\\version') : null,
            'record_format' => PCOV_RECORD_FORMAT_VERSION,
            'record_compatibility' => PCOV_RECORD_FORMAT_COMPATIBILITY,
        ],
        'deployment' => $deployment,
    ]);
    $json = json_encode(
        $document,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    return [
        'document' => $document,
        'canonical_json' => $json,
        'id' => hash('sha256', $json),
    ];
}

/** Return a deterministic SHA-256 source fingerprint for a regular file. */
function pcov_manifest_source_fingerprint(string $path): string
{
    if ($path === '' || str_contains($path, "\0") || !is_file($path)) {
        throw new RuntimeException("Cannot fingerprint non-regular PCOV source: {$path}");
    }
    $fingerprint = @hash_file('sha256', $path);
    if ($fingerprint === false) {
        throw new RuntimeException("Cannot fingerprint PCOV source: {$path}");
    }

    return $fingerprint;
}

/** @return array<string, array<int, -1|1>> */
function pcov_coverage_normalize(array $coverage): array
{
    $normalized = [];
    foreach ($coverage as $file => $lines) {
        if (!is_string($file) || $file === '' || str_contains($file, "\0") || $file[0] !== '/') {
            throw new RuntimeException('Coverage contains a non-canonical filename');
        }
        if (!is_array($lines)) {
            throw new RuntimeException("Coverage lines are not an array: {$file}");
        }

        $normalizedLines = [];
        foreach ($lines as $line => $value) {
            if (!is_int($line) || $line < 0 || $line > 0xffffffff || ($value !== -1 && $value !== 1)) {
                throw new RuntimeException("Coverage contains an invalid line record: {$file}");
            }
            $normalizedLines[$line] = $value;
        }
        ksort($normalizedLines, SORT_NUMERIC);
        $normalized[$file] = $normalizedLines;
    }
    ksort($normalized, SORT_STRING);

    return $normalized;
}

function pcov_coverage_hash(array $coverage): string
{
    $context = hash_init('sha256');
    foreach (pcov_coverage_normalize($coverage) as $file => $lines) {
        hash_update($context, pack('N', strlen($file)) . $file . pack('N', count($lines)));
        foreach ($lines as $line => $value) {
            hash_update($context, pack('NN', $line, $value));
        }
    }

    return hash_final($context);
}

/** @return array<string, array<int, -1|1>> */
function pcov_full_dumps_merge(array $paths): array
{
    $coverage = [];
    foreach ($paths as $path) {
        $record = pcov_record_load($path);
        if ($record['record_type'] !== PCOV_RECORD_FULL) {
            throw new RuntimeException("Record is not complete coverage: {$path}");
        }
        foreach ($record['records'] as $file => $lines) {
            foreach ($lines as $line => $value) {
                if (!isset($coverage[$file][$line]) || $value === 1) {
                    $coverage[$file][$line] = $value;
                }
            }
        }
    }

    return pcov_coverage_normalize($coverage);
}

function pcov_record_envelope(int $recordType, string $payload): string
{
    if (!in_array($recordType, [
        PCOV_RECORD_FULL,
        PCOV_RECORD_MANIFEST,
        PCOV_RECORD_HITS,
    ], true)) {
        throw new InvalidArgumentException("Unsupported PCOV coverage record type {$recordType}");
    }
    $length = strlen($payload);
    return PCOV_RECORD_MAGIC . pack(
        'N*',
        PCOV_RECORD_FORMAT_VERSION,
        PCOV_RECORD_HEADER_SIZE,
        $recordType,
        0,
        intdiv($length, 4294967296),
        $length % 4294967296,
        crc32($payload),
        PHP_VERSION_ID,
        PCOV_RECORD_FORMAT_COMPATIBILITY,
        0
    ) . $payload;
}

/**
 * @param array<string,array{fingerprint:string,lines:array<int,-1|1>}> $files
 */
function pcov_manifest_id(string $environmentId, array $files): string
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $environmentId)) {
        throw new InvalidArgumentException('Environment identity must be lowercase SHA-256 hex');
    }
    ksort($files, SORT_STRING);
    $context = hash_init('sha256');
    hash_update($context, "PCOV-MANIFEST-V1\0" . hex2bin($environmentId));
    foreach ($files as $file => $entry) {
        if (!preg_match('/^[0-9a-f]{64}$/D', $entry['fingerprint'] ?? '')) {
            throw new InvalidArgumentException("Invalid source fingerprint: {$file}");
        }
        $lines = pcov_coverage_normalize([$file => $entry['lines']])[$file];
        hash_update($context, pack('N', strlen($file)) . $file);
        hash_update($context, hex2bin($entry['fingerprint']));
        hash_update($context, pack('N', count($lines)));
        foreach ($lines as $line => $_value) {
            hash_update($context, pack('N', $line));
        }
    }
    return hash_final($context);
}

/**
 * @param array<string,array{fingerprint:string,lines:array<int,-1|1>}> $files
 */
function pcov_manifest_encode(string $environmentId, array $files): string
{
    ksort($files, SORT_STRING);
    $manifestId = pcov_manifest_id($environmentId, $files);
    $payload = hex2bin($environmentId) . hex2bin($manifestId) . pack('N', 0) . pack('N', count($files));
    foreach ($files as $file => $entry) {
        $lines = pcov_coverage_normalize([$file => $entry['lines']])[$file];
        $payload .= pack('N', strlen($file)) . $file;
        $payload .= hex2bin($entry['fingerprint']);
        $payload .= pack('N', count($lines));
        foreach ($lines as $line => $_value) {
            $payload .= pack('N', $line);
        }
    }
    return pcov_record_envelope(PCOV_RECORD_MANIFEST, $payload);
}

/**
 * Create a manifest from complete coverage and current source files.
 * This bootstrap helper is valid only when the full discovery run and source
 * fingerprinting belong to the same immutable deployment.
 *
 * @param array<string,array<int,-1|1>> $coverage
 * @return array{manifest_id:string,environment_id:string,files:int,executable_lines:int,size:int}
 */
function pcov_manifest_create_from_coverage(
    string $path,
    string $environmentId,
    array $coverage
): array {
    $coverage = pcov_coverage_normalize($coverage);
    $files = [];
    $lineCount = 0;
    foreach ($coverage as $file => $lines) {
        $manifestLines = array_fill_keys(array_keys($lines), -1);
        $files[$file] = [
            'fingerprint' => pcov_manifest_source_fingerprint($file),
            'lines' => $manifestLines,
        ];
        $lineCount += count($lines);
    }
    $contents = pcov_manifest_encode($environmentId, $files);
    pcov_atomic_publish($path, $contents);
    $loaded = pcov_record_load($path);
    return [
        'manifest_id' => $loaded['manifest_id'],
        'environment_id' => $loaded['environment_id'],
        'files' => count($files),
        'executable_lines' => $lineCount,
        'size' => strlen($contents),
    ];
}

function pcov_atomic_publish(string $path, string $contents): void
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new RuntimeException('Invalid PCOV output path');
    }

    $temporary = null;
    $handle = false;
    for ($attempt = 0; $attempt < 16; $attempt++) {
        $suffix = bin2hex(random_bytes(8));
        $candidate = "{$path}.pcovtmp." . getmypid() . ".{$suffix}";
        $handle = @fopen($candidate, 'x+b');
        if ($handle !== false) {
            $temporary = $candidate;
            break;
        }
    }
    if ($handle === false || $temporary === null) {
        throw new RuntimeException("Cannot create temporary PCOV file for: {$path}");
    }

    try {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException("Cannot write temporary PCOV file for: {$path}");
            }
            $offset += $written;
        }
        if (!fclose($handle)) {
            $handle = false;
            throw new RuntimeException("Cannot close temporary PCOV file for: {$path}");
        }
        $handle = false;
        if (!@rename($temporary, $path)) {
            throw new RuntimeException("Cannot publish PCOV file: {$path}");
        }
        $temporary = null;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($temporary !== null && is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/** @return array<string,string> canonical path => SHA-256 */
function pcov_manifest_source_inventory(array $paths): array
{
    $inventory = [];
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Source inventory paths must be canonical absolute paths');
        }
        $inventory[$path] = pcov_manifest_source_fingerprint($path);
    }
    ksort($inventory, SORT_STRING);
    return $inventory;
}

/**
 * Merge immutable manifest N with validated hit dumps and complete fallback
 * records. Fallback records are coverage-complete for their request, but a
 * request-local delta cannot prove deployment-wide removals; candidate N+1 is
 * therefore not publishable until checked against an authoritative inventory.
 *
 * @return array{
 *   coverage:array<string,array<int,-1|1>>,
 *   coverage_complete:bool,
 *   candidate_files:array<string,array{fingerprint:string,lines:array<int,-1>}>,
 *   candidate_complete:bool,
 *   requires_full_discovery:bool,
 *   environment_id:string,
 *   base_manifest_id:string,
 *   candidate_manifest_id:string,
 *   delta_files:list<string>,
 *   stats:array<string,int>
 * }
 */
function pcov_manifest_merge(string $manifestPath, array $dumpPaths): array
{
    $started = hrtime(true);
    $manifest = pcov_record_load($manifestPath);
    if ($manifest['version'] !== PCOV_RECORD_FORMAT_VERSION ||
        $manifest['record_type'] !== PCOV_RECORD_MANIFEST) {
        throw new RuntimeException("Record is not a PCOV manifest: {$manifestPath}");
    }

    $coverage = pcov_coverage_normalize($manifest['records']);
    $candidate = [];
    foreach ($coverage as $file => $lines) {
        $fingerprint = $manifest['fingerprints'][$file] ?? null;
        if (!is_string($fingerprint) || !preg_match('/^[0-9a-f]{64}$/D', $fingerprint)) {
            throw new RuntimeException("Manifest has no valid fingerprint: {$file}");
        }
        $candidate[$file] = [
            'fingerprint' => $fingerprint,
            'lines' => array_fill_keys(array_keys($lines), -1),
        ];
    }

    $fallbacks = 0;
    $hitDumps = 0;
    $hitEntries = 0;
    $uniqueHits = 0;
    $deltaFiles = [];
    foreach ($dumpPaths as $dumpPath) {
        $dump = pcov_record_load($dumpPath);
        if ($dump['version'] !== PCOV_RECORD_FORMAT_VERSION) {
            throw new RuntimeException("Incompatible record cannot be merged into this manifest: {$dumpPath}");
        }
        if ($dump['environment_id'] !== $manifest['environment_id']) {
            throw new RuntimeException("Environment identity mismatch: {$dumpPath}");
        }
        if ($dump['manifest_id'] !== $manifest['manifest_id']) {
            throw new RuntimeException("Manifest identity mismatch: {$dumpPath}");
        }

        if ($dump['record_type'] === PCOV_RECORD_HITS) {
            $hitDumps++;
            if ($dump['reason'] !== 0) {
                throw new RuntimeException("Hit record has a fallback reason: {$dumpPath}");
            }
            foreach ($dump['validated_files'] as $file => $fingerprint) {
                if (!isset($candidate[$file])) {
                    throw new RuntimeException("Validated file is absent from manifest: {$file}");
                }
                if (!hash_equals($candidate[$file]['fingerprint'], $fingerprint)) {
                    throw new RuntimeException("Validated file fingerprint mismatch: {$file}");
                }
            }
            foreach ($dump['records'] as $file => $lines) {
                if (!isset($dump['validated_files'][$file])) {
                    throw new RuntimeException("Hit file was not fingerprint-validated: {$file}");
                }
                foreach ($lines as $line => $_value) {
                    $hitEntries++;
                    if (!isset($coverage[$file]) || !array_key_exists($line, $coverage[$file])) {
                        throw new RuntimeException("Hit is absent from PCOV manifest: {$file}:{$line}");
                    }
                    if ($coverage[$file][$line] !== 1) {
                        $uniqueHits++;
                    }
                    $coverage[$file][$line] = 1;
                }
            }
            continue;
        }

        if ($dump['record_type'] !== PCOV_RECORD_FULL || $dump['reason'] === 0) {
            throw new RuntimeException("Unsupported manifest input record: {$dumpPath}");
        }
        $fallbacks++;
        foreach ($dump['records'] as $file => $lines) {
            $status = $dump['fingerprint_status'][$file] ?? -1;
            if ($status !== 0) {
                throw new RuntimeException("Fallback fingerprint is unavailable ({$status}): {$file}");
            }
            $fingerprint = $dump['fingerprints'][$file] ?? null;
            if (!is_string($fingerprint) || !preg_match('/^[0-9a-f]{64}$/D', $fingerprint)) {
                throw new RuntimeException("Fallback fingerprint is invalid: {$file}");
            }
            foreach ($lines as $line => $value) {
                if ($value === 1) {
                    $hitEntries++;
                    if (($coverage[$file][$line] ?? -1) !== 1) {
                        $uniqueHits++;
                    }
                }
                if (!isset($coverage[$file][$line]) || $value === 1) {
                    $coverage[$file][$line] = $value;
                }
            }
            $candidate[$file] = [
                'fingerprint' => $fingerprint,
                'lines' => array_fill_keys(array_keys($lines), -1),
            ];
            $deltaFiles[$file] = true;
        }
    }

    $coverage = pcov_coverage_normalize($coverage);
    ksort($candidate, SORT_STRING);
    $candidateId = pcov_manifest_id($manifest['environment_id'], $candidate);

    return [
        'coverage' => $coverage,
        'coverage_complete' => $fallbacks === 0,
        'candidate_files' => $candidate,
        'candidate_complete' => $fallbacks === 0,
        'requires_full_discovery' => $fallbacks !== 0,
        'environment_id' => $manifest['environment_id'],
        'base_manifest_id' => $manifest['manifest_id'],
        'candidate_manifest_id' => $candidateId,
        'delta_files' => array_keys($deltaFiles),
        'stats' => [
            'merge_ns' => hrtime(true) - $started,
            'hit_dumps' => $hitDumps,
            'hit_entries_before_deduplication' => $hitEntries,
            'hit_entries_after_deduplication' => $uniqueHits,
            'full_fallbacks' => $fallbacks,
            'delta_files' => count($deltaFiles),
        ],
    ];
}

/**
 * Validate a candidate against a complete deployment inventory and publish it
 * atomically. Every inventory file must already have executable-line discovery
 * data with the same fingerprint; removed files are omitted deterministically.
 *
 * @param array<string,mixed> $merge
 * @param array<string,string> $inventory
 * @return array{manifest_id:string,files:int,size:int}
 */
function pcov_manifest_publish_candidate(
    string $path,
    array $merge,
    array $inventory
): array {
    ksort($inventory, SORT_STRING);
    $files = [];
    foreach ($inventory as $file => $fingerprint) {
        if (!isset($merge['candidate_files'][$file])) {
            throw new RuntimeException("Inventory file needs a full discovery pass: {$file}");
        }
        $candidate = $merge['candidate_files'][$file];
        if (!is_string($fingerprint) ||
            !hash_equals($candidate['fingerprint'], $fingerprint)) {
            throw new RuntimeException("Inventory fingerprint needs a full discovery pass: {$file}");
        }
        $files[$file] = $candidate;
    }

    $contents = pcov_manifest_encode($merge['environment_id'], $files);
    pcov_atomic_publish($path, $contents);
    $loaded = pcov_record_load($path);
    return [
        'manifest_id' => $loaded['manifest_id'],
        'files' => count($files),
        'size' => strlen($contents),
    ];
}

/** Build manifest N from complete full-fallback records of one deployment. */
function pcov_manifest_bootstrap(
    string $path,
    string $environmentId,
    array $fullPaths
): array {
    $started = hrtime(true);
    $files = [];
    $coverage = [];
    foreach ($fullPaths as $fullPath) {
        $record = pcov_record_load($fullPath);
        if ($record['version'] !== PCOV_RECORD_FORMAT_VERSION ||
            $record['record_type'] !== PCOV_RECORD_FULL ||
            $record['environment_id'] !== $environmentId) {
            throw new RuntimeException("Invalid manifest bootstrap record: {$fullPath}");
        }
        foreach ($record['records'] as $file => $lines) {
            if (($record['fingerprint_status'][$file] ?? -1) !== 0) {
                throw new RuntimeException("Bootstrap fingerprint is unavailable: {$file}");
            }
            $fingerprint = $record['fingerprints'][$file];
            if (isset($files[$file]) && !hash_equals($files[$file]['fingerprint'], $fingerprint)) {
                throw new RuntimeException("Source changed during manifest bootstrap: {$file}");
            }
            foreach ($lines as $line => $value) {
                $files[$file]['lines'][$line] = -1;
                if (!isset($coverage[$file][$line]) || $value === 1) {
                    $coverage[$file][$line] = $value;
                }
            }
            $files[$file]['fingerprint'] = $fingerprint;
            $files[$file]['lines'] ??= [];
        }
    }
    ksort($files, SORT_STRING);
    foreach ($files as &$entry) ksort($entry['lines'], SORT_NUMERIC);
    unset($entry);
    $contents = pcov_manifest_encode($environmentId, $files);
    pcov_atomic_publish($path, $contents);
    $manifest = pcov_record_load($path);
    return [
        'manifest_id' => $manifest['manifest_id'],
        'environment_id' => $environmentId,
        'files' => count($files),
        'executable_lines' => array_sum(array_map(
            static fn (array $entry): int => count($entry['lines']), $files
        )),
        'normalized_hash' => pcov_coverage_hash($coverage),
        'size' => strlen($contents),
        'source_dumps' => count($fullPaths),
        'build_ns' => hrtime(true) - $started,
    ];
}
