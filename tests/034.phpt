--TEST--
validated dump falls back when a positive hit is absent from a fingerprint-matching manifest
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
$fixture = "/tmp/pcov-unknown-hit-{$id}.php";
$manifest = "/tmp/pcov-unknown-hit-manifest-{$id}.bin";
$output = "/tmp/pcov-unknown-hit-output-{$id}.bin";
file_put_contents($fixture, "<?php\nfunction pcov_unknown_hit(): int { return 1; }\n");
require $fixture;
$identity = pcov_manifest_environment_identity([
    'application_revision' => 'unknown-hit', 'dependency_lock' => 'a',
    'modules_config' => 'b', 'generated_code' => 'c', 'source_tree' => 'd',
]);
$files = [$fixture => [
    'fingerprint' => hash_file('sha256', $fixture),
    'lines' => [],
]];
pcov_atomic_publish($manifest, pcov_manifest_encode($identity['id'], $files));
pcov\start(); pcov_unknown_hit(); pcov\stop();
$result = pcov\export($output, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$record = pcov_record_load($output);
var_dump($result['mode'] === 'full-fallback');
var_dump($result['reason'] === 'hit-not-in-manifest');
var_dump($record['record_type'] === PCOV_RECORD_FULL);
var_dump(in_array(1, $record['records'][$fixture], true));
var_dump(pcov\export_stats()['discovery_operations'] === 1);
foreach ([$fixture, $manifest, $output] as $path) if (is_file($path)) unlink($path);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
