--TEST--
native synchronous full exports are complete and deterministic
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!function_exists('pcov\\export')) print 'skip native export unavailable';
?>
--ENV--
PCOV_DUMP_BENCH_STATS=1
--INI--
pcov.enabled=1
pcov.directory=/tmp
--FILE--
<?php
$id = getmypid();
$fixture = "/tmp/pcov-full-fixture-{$id}.php";
$first = "/tmp/pcov-full-first-{$id}.bin";
$second = "/tmp/pcov-full-second-{$id}.bin";
$empty = "/tmp/pcov-full-empty-{$id}.bin";

file_put_contents($fixture, <<<'PHP'
<?php
function pcov_full_fixture(int $value): int {
    $result = 0;
    if ($value > 0) {
        $result++;
    }
    if ($value > 1) {
        $result++;
    }
    return $result;
}
PHP
);
require $fixture;
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

pcov\start();
pcov_full_fixture(1);
pcov\stop();
$reference = pcov\collect(pcov\inclusive, [$fixture]);
pcov\clear();
pcov\start();
pcov_full_fixture(1);
pcov\stop();
$result = pcov\export($first, null, null, pcov\inclusive, [$fixture]);
$record = pcov_record_load($first);
var_dump(is_array($result));
var_dump($result['mode'] === 'full');
var_dump($record['record_type'] === PCOV_RECORD_FULL);
var_dump(
    pcov_coverage_normalize($record['records']) ===
    pcov_coverage_normalize($reference)
);
var_dump(glob($first . '.pcovtmp.*'));

pcov\clear();
pcov\start();
pcov_full_fixture(1);
pcov\stop();
var_dump(is_array(pcov\export($second, null, null, pcov\inclusive, [$fixture])));
var_dump(file_get_contents($second) === file_get_contents($first));

pcov\clear();
var_dump(is_array(pcov\export($empty)));
$emptyCoverage = pcov_record_load($empty)['records'];
var_dump(
    isset($emptyCoverage[$fixture]) &&
    count(array_unique($emptyCoverage[$fixture], SORT_REGULAR)) === 1 &&
    reset($emptyCoverage[$fixture]) === -1
);

foreach ([$fixture, $first, $second, $empty] as $path) {
    unlink($path);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
array(0) {
}
bool(true)
bool(true)
bool(true)
bool(true)
