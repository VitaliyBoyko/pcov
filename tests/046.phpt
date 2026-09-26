--TEST--
Native Magento coverage cache works with private no-store responses and stays bounded
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
foreach (['/checkout/cart', '/customer/account', '/customer/section/load', '/index.php/%61dmin/page', '/rest/test', '/graphql?x=y'] as $route) {
    if ($request($route)['result']['cache'] !== 'miss' || $request($route)['result']['cache'] !== 'hit') throw new RuntimeException($route);
}
$check(true, 'GET routes reuse only exact coverage');
foreach ([['Authorization: Bearer test'], ['Cache-Control: no-cache'], ['Cache-Control: no-store'],
    ['Cache-Control: max-age=0'], ['Pragma: no-cache'], ['X-Pass: 1']] as $headers) {
    if ($request(headers: $headers)['result']['cache'] !== 'miss' || $request(headers: $headers)['result']['cache'] !== 'hit') throw new RuntimeException(json_encode($headers));
}
$check($request(method: 'POST')['result']['cache'] === 'bypass', 'POST bypasses');
$request();
foreach ([['Cache-Control: private, max-age=300'], ['Cache-Control: no-cache'], ['Cache-Control: no-store'],
    ['Cache-Control: max-age=0'], ['Cache-Control: max-age=300', 'Vary: *'],
    ['Cache-Control: max-age=300', 'Set-Cookie: X-Magento-Vary=other'], [],
    ['Cache-Control: max-age=1', 'Age: 2']] as $headers) {
    $configure(['headers' => $headers]);
    if ($request()['result']['cache'] !== 'hit') throw new RuntimeException(json_encode($headers));
}
$check(true, 'response cache policy never substitutes for actual hit comparison');
$configure(['headers' => ['Cache-Control: max-age=300'], 'status' => 500]);
$check($request()['result']['cache'] === 'bypass', 'error response bypasses');
$configure(['status' => 200]);
foreach ([['Cookie: X-Magento-Vary=guest; store=one'], ['Cookie: X-Magento-Vary=member; store=one'],
    ['Cookie: X-Magento-Vary=member; store=two'], ['Accept: application/json']] as $headers) {
    if ($request(headers: $headers)['result']['cache'] !== 'miss' || $request(headers: $headers)['result']['cache'] !== 'hit') {
        throw new RuntimeException('Context mismatch');
    }
}
$check(true, 'cookies store and headers partition context');
$request('/eviction-first');
for ($i = 0; $i < 20; $i++) { $request('/eviction-' . $i); }
$check($request('/eviction-first')['result']['cache'] === 'miss', 'bounded cache evicts old entries');
?>
--EXPECT--
GET routes reuse only exact coverage
POST bypasses
response cache policy never substitutes for actual hit comparison
error response bypasses
cookies store and headers partition context
bounded cache evicts old entries
