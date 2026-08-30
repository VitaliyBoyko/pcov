--TEST--
immutable-source fingerprint reuse is guarded and fails closed on source changes
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

$root = '/tmp/pcov-fingerprint-reuse-' . getmypid();
mkdir($root);
$fixture = $root . '/fixture.php';
$manifest = $root . '/manifest.pcov';
$dump = $root . '/request.pcov';
$original = "<?php\nfunction pcov_fingerprint_reuse_fixture(): int { return 11; }\n";
$changed =  "<?php\nfunction pcov_fingerprint_reuse_fixture(): int { return 22; }\n";
file_put_contents($fixture, $original);
$mtime = filemtime($fixture);
require $fixture;

pcov\start();
pcov_fingerprint_reuse_fixture();
pcov\stop();
$coverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$identity = pcov_manifest_environment_identity([
    'application_revision' => 'reuse-v1',
    'dependency_lock' => str_repeat('a', 64),
    'modules_config' => str_repeat('b', 64),
    'generated_code' => str_repeat('c', 64),
    'source_tree' => str_repeat('d', 64),
]);
pcov_manifest_create_from_coverage($manifest, $identity['id'], $coverage);

$match = pcov\export($dump, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();
var_dump($match['mode']);
var_dump($stats['fingerprint_calls']);
var_dump($stats['fingerprint_bytes_read']);
var_dump($stats['fingerprint_reuse_hits']);

file_put_contents($fixture, $changed);
touch($fixture, $mtime);
clearstatcache(true, $fixture);
pcov\clear();
pcov\start();
pcov_fingerprint_reuse_fixture();
pcov\stop();
$mismatch = pcov\export($dump, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();
var_dump($mismatch['mode']);
var_dump($stats['fingerprint_reuse_misses']);
var_dump($stats['fingerprint_calls']);
var_dump($stats['fingerprint_bytes_read'] === strlen($changed));

file_put_contents($root . '/replacement.php', $original);
rename($root . '/replacement.php', $fixture);
clearstatcache(true, $fixture);
pcov\clear();
pcov\start();
pcov_fingerprint_reuse_fixture();
pcov\stop();
$replacement = pcov\export($dump, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();
var_dump($replacement['mode']);
var_dump($stats['fingerprint_reuse_misses']);
var_dump($stats['fingerprint_calls']);

unlink($fixture);
pcov\clear();
pcov\start();
pcov_fingerprint_reuse_fixture();
pcov\stop();
$deleted = pcov\export($dump, $manifest, $identity['id'], pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();
var_dump($deleted['mode']);
var_dump($stats['fingerprint_reuse_misses']);
var_dump($stats['fingerprint_failures'] > 0);

foreach ([$manifest, $dump] as $path) {
    if (is_file($path)) unlink($path);
}
rmdir($root);
?>
--EXPECT--
string(8) "hit-only"
int(0)
int(0)
int(1)
string(13) "full-fallback"
int(1)
int(1)
bool(true)
string(8) "hit-only"
int(1)
int(1)
string(13) "full-fallback"
int(1)
bool(true)
