--TEST--
Magento request-cache respects HTTP cacheability, expiration, corruption and record retention
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
foreach ([['REQUEST_METHOD' => 'POST'], ['HTTP_AUTHORIZATION' => 'Bearer test'], ['REQUEST_URI' => '/checkout/cart/'], ['REQUEST_URI' => '/customer/section/load/'], ['REQUEST_URI' => '/index.php/%61dmin/page'], ['REQUEST_URI' => '/graphql?query=x'], ['HTTP_CACHE_CONTROL' => 'no-cache']] as $change) {
    $check($run(array_replace($server, $change))[0], 'request bypass');
}
foreach ([['Cache-Control: private, max-age=300'], ['Cache-Control: no-cache'], ['Cache-Control: no-store'], ['Cache-Control: public, s-maxage=0'], ['Vary: *', 'Cache-Control: max-age=300'], ['Cache-Control: max-age=300', 'Set-Cookie: X-Magento-Vary=other; Path=/'], [], ['Cache-Control: max-age=10', 'Age: 20']] as $index => $response) {
    $suite = 'response-' . $index;
    $run(response: $response, suite: $suite);
    $check($run(response: $response, suite: $suite)[0], 'response bypass');
}
$run(status: 500, suite: 'error');
$check($run(suite: 'error')[0], 'failed response does not seed cache');
$run(response: ['Cache-Control: max-age=300', 'Vary: Cookie'], suite: 'vary-cookie');
$changedSession = array_replace($server, ['HTTP_COOKIE' => 'PHPSESSID=other']);
$check($run($changedSession, suite: 'vary-cookie')[0], 'Vary Cookie is respected');
$cacheDir = $root . '/.pcov-get-cache-' . hash('sha256', 'suite-one');
$entryPath = glob($cacheDir . '/*.json')[0];
$entry = json_decode(file_get_contents($entryPath), true);
$entry['expires'] = 0;
file_put_contents($entryPath, json_encode($entry));
$check($run()[0], 'expired entry recollects');
$entry = json_decode(file_get_contents($entryPath), true);
file_put_contents($root . '/' . $entry['record'], 'damaged');
$check($run()[0], 'corrupt retained record recollects');
$entry = json_decode(file_get_contents($entryPath), true);
unlink($root . '/' . $entry['record']);
$check($run()[0], 'deleted retained record recollects');
file_put_contents($entryPath, 'invalid JSON');
$check($run()[0], 'corrupt cache entry recollects');
$make()->invalidate();
$inflight = $make();
$inflight->start($server, $cookies);
pcov_magento_fixture();
$make()->invalidate();
$inflight->finish(200, $headers);
pcov\clear();
$check($run()[0], 'in-flight export cannot undo invalidation');
?>
--EXPECT--
request bypass
request bypass
request bypass
request bypass
request bypass
request bypass
request bypass
response bypass
response bypass
response bypass
response bypass
response bypass
response bypass
response bypass
response bypass
failed response does not seed cache
Vary Cookie is respected
expired entry recollects
corrupt retained record recollects
deleted retained record recollects
corrupt cache entry recollects
in-flight export cannot undo invalidation
