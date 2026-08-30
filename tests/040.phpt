--TEST--
large-codebase mode is one switch and disabled mode preserves upstream collection
--SKIPIF--
<?php if (!extension_loaded('pcov')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.large_codebase=0
pcov.directory=/tmp
--FILE--
<?php
$fixture = '/tmp/pcov-large-switch-' . getmypid() . '.php';
$output = '/tmp/pcov-large-switch-' . getmypid() . '.pcov';
file_put_contents($fixture, "<?php\nfunction pcov_large_switch(): int { return 1; }\n");
require $fixture;
pcov\start();
pcov_large_switch();
pcov\stop();
$coverage = pcov\collect(pcov\inclusive, [$fixture]);

var_dump(ini_get('pcov.large_codebase'));
var_dump(isset($coverage[$fixture]));
var_dump(pcov\export($output));
var_dump(function_exists('pcov\\dump'));
var_dump(function_exists('pcov\\dump_hits'));
var_dump(function_exists('pcov\\dump_validated'));

unlink($fixture);
?>
--EXPECT--
string(1) "0"
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
