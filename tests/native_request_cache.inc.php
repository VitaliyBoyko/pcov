<?php
// Real requests in a persistent PHP worker, using the unchanged collector API.
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';
$root = sys_get_temp_dir() . '/pcov-native-cache-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir("$root/records");
mkdir("$root/other");
$fixture = "$root/source.php";
$source = <<<'PHP'
<?php
function native_cache_fixture(bool $alternate): int {
    if ($alternate) {
        return 22;
    }
    return 11;
}
PHP;
file_put_contents($fixture, $source);
file_put_contents("$root/state", '0');
require $fixture;
pcov\start(); native_cache_fixture(false); native_cache_fixture(true); pcov\stop();
$coverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$deployment = hash('sha256', 'native-cache-test');
$manifest = "$root/manifest.pcov";
pcov_manifest_create_from_coverage($manifest, $deployment, $coverage);
$config = ['deployment' => $deployment, 'manifest' => $manifest, 'directory' => "$root/records",
    'headers' => ['Cache-Control: public, max-age=300'], 'status' => 200, 'type' => pcov\inclusive,
    'filter' => [$fixture], 'fail' => false, 'clear' => false, 'extra' => false];
$configure = static function (array $changes = []) use ($root, &$config): void {
    $config = array_replace($config, $changes);
    file_put_contents("$root/config.json", json_encode($config, JSON_THROW_ON_ERROR));
};
$configure();
file_put_contents("$root/router.php", <<<'PHP'
<?php
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
http_response_code($config['status']);
foreach ($config['headers'] as $header) { header($header); }
require __DIR__ . '/source.php';
pcov\clear();
pcov\start();
$value = native_cache_fixture(file_get_contents(__DIR__ . '/state') === '1');
if ($config['extra']) { require __DIR__ . '/extra.php'; }
pcov\stop();
if ($config['clear']) { pcov\clear(); }
$path = $config['directory'] . '/' . bin2hex(random_bytes(12)) . '.pcov';
if ($config['fail']) { mkdir($path); } // Force atomic rename to fail, even as root.
$result = @pcov\export($path, $config['manifest'], $config['deployment'], $config['type'], $config['filter']);
$stats = pcov\export_stats();
if ($config['fail']) {
    rmdir($path);
    $retried = pcov\export($path, $config['manifest'], $config['deployment'], $config['type'], $config['filter']);
}
echo json_encode(['result' => $result, 'stats' => $stats, 'path' => $path, 'value' => $value,
    'retried' => $retried ?? null, 'temporaries' => glob($path . '.pcovtmp.*')]);
PHP);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
fclose($socket);
$process = null;
register_shutdown_function(static function () use ($root, &$process): void {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
});
$extension = dirname(__DIR__) . '/modules/pcov.' . PHP_SHLIB_SUFFIX;
$process = proc_open([getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY, '-n', '-d', "extension=$extension",
    '-d', 'pcov.enabled=1', '-d', 'pcov.large_codebase=1', '-d', "pcov.directory=$root",
    '-d', 'pcov.request_magento_cache=' . ($nativeCacheEnabled ?? '1'),
    '-S', $address, "$root/router.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "$root/server.log", 'a'], 2 => ['file', "$root/server.log", 'a']], $pipes,
    null, array_replace(getenv(), ['PHP_CLI_SERVER_WORKERS' => '1', 'PCOV_DUMP_BENCH_STATS' => '1']));
if (!is_resource($process)) { throw new RuntimeException('Cannot start PHP server'); }
fclose($pipes[0]);
$ready = false;
for ($i = 0; $i < 200; $i++) {
    $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
    if ($connection) { fclose($connection); $ready = true; break; }
    usleep(10000);
}
if (!$ready) { throw new RuntimeException(file_get_contents("$root/server.log")); }
$request = static function (string $route = '/product', array $headers = [], string $method = 'GET') use ($address, $root): array {
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'timeout' => 10]]);
    $body = file_get_contents('http://' . $address . $route, false, $context);
    try { return json_decode($body, true, flags: JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new RuntimeException($body . "\n" . file_get_contents("$root/server.log"), 0, $e); }
};
$check = static function (bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    echo $label, "\n";
};
