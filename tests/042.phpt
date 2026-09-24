--TEST--
Magento GET cache retains coverage and separates context, request, suite and filter
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
[$one, $first, $firstWaiting] = $run();
[$two, $second, $secondWaiting, $cache] = $run();
$check($one && !$two && $firstWaiting > 0 && $secondWaiting === 0, 'repeat skips recording');
$check($second['mode'] === 'request-cache-hit' && $first['record'] === $second['record'], 'repeat references the retained record');
$check($cache->finish() === $second && count(glob($root . '/request-*.pcov')) === 1, 'finish is idempotent and skips export');
$check($run(context: ['X-Magento-Vary' => 'member', 'store' => 'default'])[0], 'vary change collects');
$check($run(context: ['X-Magento-Vary' => 'guest-context', 'store' => 'second'])[0], 'store change collects');
$check($run(array_replace($server, ['REQUEST_URI' => '/product?q=two']))[0], 'query change collects');
$check($run(array_replace($server, ['HTTP_HOST' => 'second.test']))[0], 'host change collects');
$check($run(array_replace($server, ['HTTPS' => 'off', 'SERVER_PORT' => '80']))[0], 'scheme change collects');
$check($run(suite: 'suite-two')[0], 'suite change collects');
$changedFilter = $make(files: [$fixture, $extra]);
$check($changedFilter->start($server, $cookies), 'filter change collects');
$changedFilter->finish(200, $headers);
pcov\clear();
$otherSession = array_replace($server, ['HTTP_COOKIE' => 'PHPSESSID=two']);
$check(!$run($otherSession)[0], 'public context can share between sessions');
$make()->invalidate();
$check($run()[0], 'invalidation forces collection');
$check(!$run()[0], 'new generation can cache');
$merged = pcov_manifest_merge($manifest, glob($root . '/request-*.pcov'));
$check($merged['coverage_complete'] && $merged['coverage'][$fixture] === $coverage[$fixture], 'aggregate coverage is retained');
?>
--EXPECT--
repeat skips recording
repeat references the retained record
finish is idempotent and skips export
vary change collects
store change collects
query change collects
host change collects
scheme change collects
suite change collects
filter change collects
public context can share between sessions
invalidation forces collection
new generation can cache
aggregate coverage is retained
