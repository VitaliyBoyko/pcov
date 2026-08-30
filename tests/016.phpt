--TEST--
native export respects pcov.directory and pcov.exclude
--SKIPIF--
<?php if (!extension_loaded('pcov')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.directory=/tmp
pcov.exclude=~pcov-excluded-~
--FILE--
<?php
$id = getmypid();
$allowed = "/tmp/pcov-allowed-{$id}.php";
$excluded = "/tmp/pcov-excluded-{$id}.php";
$dump = "/tmp/pcov-filtered-{$id}.bin";

file_put_contents($allowed, "<?php\nfunction pcov_allowed() { return 1; }\n");
file_put_contents($excluded, "<?php\nfunction pcov_excluded() { return 2; }\n");
require $allowed;
require $excluded;
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

pcov\start();
pcov_allowed();
pcov_excluded();
pcov\stop();
var_dump(is_array(pcov\export($dump, null, null, pcov\inclusive, [$allowed, $excluded])));
$native = pcov_record_load($dump)['records'];
var_dump(array_keys($native) === [$allowed]);

pcov\clear();
pcov\start();
pcov_allowed();
pcov_excluded();
pcov\stop();
$normal = pcov\collect(pcov\inclusive, [$allowed, $excluded]);
var_dump(pcov_coverage_normalize($native) === pcov_coverage_normalize($normal));

unlink($allowed);
unlink($excluded);
unlink($dump);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
