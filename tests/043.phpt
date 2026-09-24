--TEST--
Magento cache bypasses changed source fingerprints including equal-size equal-mtime edits and unloaded sources
--SKIPIF--
<?php if (!extension_loaded('pcov')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.large_codebase=1
pcov.request_magento_cache=1
pcov.directory=/tmp
--FILE--
<?php
require __DIR__ . '/request_magento_cache.inc.php';
$run();
$check(!$run()[0], 'unchanged fingerprints hit');
$mtime = filemtime($fixture);
file_put_contents($fixture, str_replace('11', '22', $original));
touch($fixture, $mtime);
[$recording, $result] = $run();
$check($recording && $result['mode'] === 'full-fallback', 'same-size same-mtime content change collects and falls back');
$check($run()[0], 'fallback never becomes a cache hit');
file_put_contents($fixture, $original);
$run();
$check(!$run()[0], 'restored source can hit');
file_put_contents($extra, "<?php\nreturn 42;\n");
[$recording, $result] = $run();
$check($recording && $result['cache'] === 'fingerprint-mismatch', 'unloaded manifest source change collects');
unlink($extra);
$check($run()[0], 'missing manifest source collects');
file_put_contents($extra, "<?php\nreturn 41;\n");
$run();
$check(!$run()[0], 'valid source inventory can hit again');
$newCoverage = $coverage;
$newCoverage[$extra][3] = -1;
pcov_manifest_create_from_coverage($manifest, $deployment, $newCoverage);
$check($run()[0], 'manifest fingerprint change collects');
$check(!$run()[0], 'new immutable manifest generation can cache');
file_put_contents($manifest, 'corrupt');
[$recording, $result] = $run();
$check($recording && $result['mode'] === 'full-fallback', 'corrupt manifest falls back to full collection');
?>
--EXPECT--
unchanged fingerprints hit
same-size same-mtime content change collects and falls back
fallback never becomes a cache hit
restored source can hit
unloaded manifest source change collects
missing manifest source collects
valid source inventory can hit again
manifest fingerprint change collects
new immutable manifest generation can cache
corrupt manifest falls back to full collection
