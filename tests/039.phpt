--TEST--
documented full bootstrap, validated export, and deterministic merge workflow
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!function_exists('pcov\\export')) print 'skip native export unavailable';
?>
--INI--
pcov.enabled=1
pcov.large_codebase=1
pcov.directory=/tmp
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

$id = getmypid();
$fixture = "/tmp/pcov-public-workflow-{$id}.php";
$full = "/tmp/pcov-public-workflow-full-{$id}.pcov";
$manifest = "/tmp/pcov-public-workflow-manifest-{$id}.pcov";
$hits = "/tmp/pcov-public-workflow-hits-{$id}.pcov";
$deployment = hash('sha256', 'public-workflow-v1');
file_put_contents($fixture, <<<'PHP'
<?php
function pcov_public_workflow(bool $covered): int {
    if ($covered) {
        return 1;
    }
    return 0;
}
PHP
);
require $fixture;

pcov\start();
pcov_public_workflow(true);
pcov\stop();
$fullResult = pcov\export($full, null, $deployment, pcov\inclusive, [$fixture]);
$reference = pcov_record_load($full)['records'];
$created = pcov_manifest_bootstrap($manifest, $deployment, [$full]);

pcov\clear();
pcov\start();
pcov_public_workflow(true);
pcov\stop();
$hitResult = pcov\export($hits, $manifest, $deployment, pcov\inclusive, [$fixture]);
$merged = pcov_manifest_merge($manifest, [$hits]);

var_dump($fullResult['mode']);
var_dump(strlen($created['manifest_id']) === 64);
var_dump($hitResult['mode']);
var_dump($merged['coverage_complete']);
var_dump(
    pcov_coverage_normalize($merged['coverage']) ===
    pcov_coverage_normalize($reference)
);
var_dump(pcov_coverage_hash($merged['coverage']) === pcov_coverage_hash($reference));

foreach ([$fixture, $full, $manifest, $hits] as $path) unlink($path);
?>
--EXPECT--
string(4) "full"
bool(true)
string(8) "hit-only"
bool(true)
bool(true)
bool(true)
