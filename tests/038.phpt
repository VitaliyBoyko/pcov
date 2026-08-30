--TEST--
validated hit export profiling records its separate sort, grouping, emission, checksum, and copy passes
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!function_exists('pcov\\export')) print 'skip validated export unavailable';
?>
--ENV--
PCOV_DUMP_BENCH_STATS=1
--INI--
pcov.enabled=1
pcov.directory=/tmp
pcov.large_codebase=1
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

$id = getmypid();
$fixture = "/tmp/pcov-export-passes-{$id}.php";
$manifest = "/tmp/pcov-export-passes-manifest-{$id}.pcov";
$dump = "/tmp/pcov-export-passes-dump-{$id}.pcov";
file_put_contents($fixture, <<<'PHP'
<?php
function pcov_export_passes(int $value): int {
    $result = 1;
    if ($value > 0) {
        $result++;
    }
    return $result;
}
PHP);
require $fixture;

pcov\start();
pcov_export_passes(1);
pcov\stop();
$reference = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$identity = hash('sha256', 'pcov-export-passes-v1');
pcov_manifest_create_from_coverage($manifest, $identity, $reference);

pcov\clear();
pcov\start();
pcov_export_passes(1);
pcov\stop();
$result = pcov\export($dump, $manifest, $identity, pcov\inclusive, [$fixture]);
$record = pcov_record_load($dump);
$stats = pcov\export_stats();
$hits = count($record['records'][$fixture]);
var_dump($result['mode'] === 'hit-only');
var_dump($stats['validated_sort_calls'] === 2);
var_dump($stats['validated_sort_entries'] === $hits * 2);
var_dump($stats['manifest_hit_entries_validated'] === $hits);
var_dump($stats['validated_group_entries'] === $hits);
var_dump($stats['validated_payload_size_entries'] === 0);
var_dump($stats['validated_file_metadata_entries'] === 1);
var_dump($stats['validated_emit_entries'] === $hits);
var_dump($stats['validated_checksum_calls'] === 1);
var_dump($stats['validated_checksum_bytes'] === $record['payload_length']);
var_dump($stats['validated_payload_copy_bytes'] === $record['payload_length']);
var_dump($stats['validated_payload_bytes'] === $record['payload_length']);
var_dump($stats['validated_output_bytes'] === filesize($dump));
var_dump($stats['hit_array_allocations'] === 1);
var_dump($stats['hit_array_allocated_bytes'] > 0);
var_dump($stats['temporary_logical_peak_bytes'] >= filesize($dump));

pcov\clear();
$result = pcov\export($dump, $manifest, $identity, pcov\inclusive, [$fixture]);
$record = pcov_record_load($dump);
$stats = pcov\export_stats();
var_dump($result['mode'] === 'hit-only');
var_dump($record['records'] === []);
var_dump($stats['validated_sort_calls'] === 0);
var_dump($stats['validated_group_entries'] === 0);
var_dump($stats['validated_emit_entries'] === 0);
var_dump($stats['validated_checksum_calls'] === 1);
var_dump($stats['hit_array_allocations'] === 0);

foreach ([$fixture, $manifest, $dump] as $path) {
    if (is_file($path)) unlink($path);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
