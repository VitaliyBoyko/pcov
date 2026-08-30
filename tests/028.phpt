--TEST--
validated synchronous dump uses hits for a matching manifest and full discovery after a source change
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
$fixture = "/tmp/pcov-validated-fixture-{$id}.php";
$manifest = "/tmp/pcov-validated-manifest-{$id}.bin";
$hit = "/tmp/pcov-validated-hit-{$id}.bin";
$fallback = "/tmp/pcov-validated-fallback-{$id}.bin";
$source = <<<'PHP'
<?php
function pcov_validated_fixture(int $value): int {
    if ($value > 0) {
        return 1;
    }
    return 0;
}
PHP;
file_put_contents($fixture, $source);
require $fixture;

pcov\start();
pcov_validated_fixture(1);
pcov\stop();
$reference = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$identity = pcov_manifest_environment_identity([
    'application_revision' => 'fixture-v1',
    'dependency_lock' => str_repeat('a', 64),
    'modules_config' => str_repeat('b', 64),
    'generated_code' => str_repeat('c', 64),
    'source_tree' => str_repeat('d', 64),
]);
$manifestStats = pcov_manifest_create_from_coverage($manifest, $identity['id'], $reference);

pcov\clear();
pcov\start();
pcov_validated_fixture(1);
pcov\stop();
$matched = pcov\export($hit, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$matchedRecord = pcov_record_load($hit);
$matchedStats = pcov\export_stats();
var_dump($matched['mode']);
var_dump($matched['reason']);
var_dump($matched['manifest_id'] === $manifestStats['manifest_id']);
var_dump($matchedRecord['record_type'] === PCOV_RECORD_HITS);
var_dump($matchedRecord['manifest_id'] === $manifestStats['manifest_id']);
var_dump($matchedRecord['validated_files'][$fixture] === hash_file('sha256', $fixture));
var_dump($matchedStats['cfg_discovery_ns'] === 0);
var_dump($matchedStats['discovery_operations'] === 0);

file_put_contents($fixture, $source . "\n// controlled change\n");
pcov\clear();
pcov\start();
pcov_validated_fixture(0);
pcov\stop();
$changed = pcov\export($fallback, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$fallbackRecord = pcov_record_load($fallback);
var_dump($changed['mode']);
var_dump($changed['reason']);
var_dump($fallbackRecord['record_type'] === PCOV_RECORD_FULL);
var_dump($fallbackRecord['reason'] === 6);
var_dump(isset($fallbackRecord['records'][$fixture]));
var_dump($fallbackRecord['fingerprints'][$fixture] === hash_file('sha256', $fixture));

foreach ([$fixture, $manifest, $hit, $fallback] as $path) {
    if (is_file($path)) unlink($path);
}
?>
--EXPECT--
string(8) "hit-only"
string(5) "match"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(13) "full-fallback"
string(23) "fingerprint-unavailable"
bool(true)
bool(false)
bool(true)
bool(false)
