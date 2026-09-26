--TEST--
Native Magento cache never bypasses deployment manifest or source validation
--SKIPIF--
<?php if (!extension_loaded('pcov') || !function_exists('proc_open')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.large_codebase=1
pcov.directory=/tmp
--FILE--
<?php
require __DIR__ . '/native_request_cache.inc.php';
$request();
$check($request()['result']['cache'] === 'hit', 'warm cache');
$mtime = filemtime($fixture);
file_put_contents($fixture, str_replace('11', '33', $source)); touch($fixture, $mtime);
$changed = $request();
$check($changed['result']['cache'] === 'bypass' && $changed['result']['mode'] === 'full-fallback', 'equal-size equal-mtime source edit falls back');
file_put_contents($fixture, $source);
$configure(['deployment' => str_repeat('b', 64)]);
$check($request()['result']['mode'] === 'full-fallback', 'deployment mismatch falls back');
$configure(['deployment' => $deployment]);
$saved = file_get_contents($manifest);
file_put_contents($manifest, 'corrupt');
$check($request()['result']['mode'] === 'full-fallback', 'corrupt manifest falls back');
file_put_contents($manifest, $saved);
$configure(['manifest' => null]);
$full = $request();
$check($full['result']['cache'] === 'bypass' && $full['result']['mode'] === 'full', 'full reference collection bypasses cache');
file_put_contents("$root/extra.php", "<?php\nreturn 123;\n");
$configure(['manifest' => $manifest, 'extra' => true, 'filter' => [$fixture, "$root/extra.php"]]);
$check($request()['result']['mode'] === 'full-fallback', 'new selected source falls back');
$configure(['extra' => false, 'filter' => [$fixture]]);
unlink($manifest);
$check($request()['result']['mode'] === 'full-fallback', 'missing manifest falls back');
?>
--EXPECT--
warm cache
equal-size equal-mtime source edit falls back
deployment mismatch falls back
corrupt manifest falls back
full reference collection bypasses cache
new selected source falls back
missing manifest falls back
