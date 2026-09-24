<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';
require dirname(__DIR__) . '/tools/pcov_request_magento_cache.php';

$root = sys_get_temp_dir() . '/pcov-magento-cache-' . bin2hex(random_bytes(8));
mkdir($root);
$fixture = $root . '/fixture.php';
$extra = $root . '/unloaded.php';
$original = "<?php\nfunction pcov_magento_fixture(): int { return 11; }\n";
file_put_contents($fixture, $original);
file_put_contents($extra, "<?php\nreturn 41;\n");
require $fixture;
pcov\start();
pcov_magento_fixture();
pcov\stop();
$coverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$coverage[$extra] = [2 => -1];
$deployment = str_repeat('a', 64);
$manifest = $root . '/manifest.pcov';
pcov_manifest_create_from_coverage($manifest, $deployment, $coverage);
pcov\clear();
$server = [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/product?q=one',
    'HTTP_HOST' => 'magento.test', 'HTTPS' => 'on', 'SERVER_PORT' => '443',
    'HTTP_ACCEPT' => 'text/html', 'HTTP_COOKIE' => 'PHPSESSID=one',
];
$cookies = ['X-Magento-Vary' => 'guest-context', 'store' => 'default'];
$headers = ['Cache-Control: public, s-maxage=300', 'Vary: X-Magento-Vary, X-Store-Cookie, Https'];
$make = static function (string $suite = 'suite-one', ?string $manifestPath = null, array $files = []) use ($root, $manifest, $deployment, $fixture): pcov\MagentoRequestCache {
    return new pcov\MagentoRequestCache($root, $manifestPath ?? $manifest, $deployment, $suite,
        type: pcov\inclusive, filter: $files ?: [$fixture]);
};
$run = static function (?array $request = null, ?array $context = null, ?array $response = null, int $status = 200, string $suite = 'suite-one') use ($make, $server, $cookies, $headers): array {
    $cache = $make($suite);
    $started = $cache->start($request ?? $server, $context ?? $cookies);
    pcov_magento_fixture();
    $waiting = count(pcov\waiting());
    $result = $cache->finish($status, $response ?? $headers);
    pcov\clear();
    return [$started, $result, $waiting, $cache];
};
$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo $label, "\n";
};
register_shutdown_function(static function () use ($root): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});
