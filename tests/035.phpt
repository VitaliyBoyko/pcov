--TEST--
validated export reuses compilation fingerprints without rereading source
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

$root = '/tmp/pcov-fingerprint-profile-' . getmypid();
mkdir($root);
$fixture = $root . '/fixture.php';
$manifest = $root . '/manifest.pcov';
$dump = $root . '/request.pcov';
$source = "<?php\nfunction pcov_fingerprint_profile_fixture(): int { return 42; }\n";
file_put_contents($fixture, $source);
require $fixture;

pcov\start();
pcov_fingerprint_profile_fixture();
pcov\stop();
$coverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$identity = pcov_manifest_environment_identity([
    'application_revision' => 'profile-v1',
    'dependency_lock' => str_repeat('a', 64),
    'modules_config' => str_repeat('b', 64),
    'generated_code' => str_repeat('c', 64),
    'source_tree' => str_repeat('d', 64),
]);
pcov_manifest_create_from_coverage($manifest, $identity['id'], $coverage);
pcov\export($dump, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();

var_dump($stats['compile_fingerprint_calls']);
var_dump($stats['fingerprint_calls']);
var_dump($stats['compile_fingerprint_bytes_read'] === strlen($source));
var_dump($stats['fingerprint_bytes_read'] === strlen($source));
var_dump($stats['compile_fingerprint_stat_calls']);
var_dump($stats['fingerprint_stat_calls']);
var_dump($stats['fingerprint_failures']);

foreach ([$fixture, $manifest, $dump] as $path) {
    if (is_file($path)) unlink($path);
}
rmdir($root);
?>
--EXPECT--
int(1)
int(0)
bool(true)
bool(false)
int(3)
int(1)
int(0)
