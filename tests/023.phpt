--TEST--
native export reports publication failure and remains retryable
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!function_exists('pcov\\export')) print 'skip native export unavailable';
?>
--INI--
pcov.enabled=1
pcov.directory=/tmp
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_record_loader.php';

$id = getmypid();
$fixture = "/tmp/pcov-hit-retry-fixture-{$id}.php";
$valid = "/tmp/pcov-hit-retry-{$id}.bin";
$invalid = "/tmp/pcov-hit-missing-{$id}/dump.bin";
$warnings = [];

file_put_contents($fixture, <<<'PHP'
<?php
function pcov_hit_retry_fixture(): int {
    return 42;
}
PHP
);
require $fixture;

pcov\start();
pcov_hit_retry_fixture();
pcov\stop();

set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
var_dump(pcov\export($invalid));
restore_error_handler();

var_dump(count($warnings) === 1);
var_dump(str_contains($warnings[0], 'temporary-file open failed'));
var_dump(is_array(pcov\export($valid)));
$coverage = pcov_record_load($valid)['records'];
var_dump(isset($coverage[$fixture]) && count($coverage[$fixture]) > 0);
var_dump(glob($invalid . '.pcovtmp.*'));

unlink($fixture);
unlink($valid);
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
array(0) {
}
