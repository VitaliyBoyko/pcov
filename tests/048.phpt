--TEST--
Native Magento cache flag off preserves independent normal exports
--SKIPIF--
<?php if (!extension_loaded('pcov') || !function_exists('proc_open')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.large_codebase=1
pcov.directory=/tmp
--FILE--
<?php
$nativeCacheEnabled = '0';
require __DIR__ . '/native_request_cache.inc.php';
$one = $request(); $two = $request();
$check(!isset($one['result']['cache'], $two['result']['cache']) && !$two['stats']['request_cache_hit'], 'flag off leaves cache inactive');
$check($two['stats']['validated_checksum_calls'] === 1 && is_file($one['path']) && is_file($two['path']), 'both requests export normally');
?>
--EXPECT--
flag off leaves cache inactive
both requests export normally
