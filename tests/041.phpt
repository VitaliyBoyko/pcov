--TEST--
Magento GET request caching is disabled by default and every request exports
--SKIPIF--
<?php if (!extension_loaded('pcov')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.large_codebase=1
pcov.directory=/tmp
--FILE--
<?php
require __DIR__ . '/request_magento_cache.inc.php';
[$one, $first] = $run();
[$two, $second] = $run();
$check(ini_get('pcov.request_magento_cache') === '0', 'default is disabled');
$check($one && $two && $first['mode'] === 'hit-only' && $second['mode'] === 'hit-only', 'both requests collected');
$check($first['record'] !== $second['record'] && is_file($first['record']) && is_file($second['record']), 'independent records retained');
$check(glob($root . '/.pcov-get-cache-*') === [], 'no cache files created');
?>
--EXPECT--
default is disabled
both requests collected
independent records retained
no cache files created
