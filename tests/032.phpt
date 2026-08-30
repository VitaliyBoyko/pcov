--TEST--
record loader rejects corrupt headers, oversized fields, truncation, and invalid records
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
?>
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

$path = '/tmp/pcov-lifecycle-corrupt-' . getmypid() . '.bin';
$environment = str_repeat('1', 64);
$empty = pcov_manifest_encode($environment, []);
$reject = static function (string $data, string $message) use ($path): void {
    file_put_contents($path, $data);
    try {
        pcov_record_load($path);
        var_dump(false);
    } catch (RuntimeException $exception) {
        var_dump(str_contains($exception->getMessage(), $message));
    }
};
$envelope = static function (
    int $type,
    string $payload,
    int $version = PCOV_RECORD_FORMAT_VERSION,
    int $compat = PCOV_RECORD_FORMAT_COMPATIBILITY
): string {
    return "PCOVREC\0" . pack('N*',
        $version, 48, $type, 0, 0, strlen($payload), crc32($payload),
        PHP_VERSION_ID, $compat, 0
    ) . $payload;
};
$prefix = hex2bin($environment) . str_repeat("\0", 32) . pack('N', 0);

$reject(substr($empty, 0, -1), 'payload length');
$reject(substr_replace($empty, pack('N', 99), 8, 4), 'version');
$reject(substr_replace($empty, pack('N', 99), 16, 4), 'record type');
$reject(substr_replace($empty, pack('N', 99), 40, 4), 'compatibility');
$reject(substr_replace($empty, pack('N', PHP_VERSION_ID - 1), 36, 4), 'PHP version');
$reject($envelope(PCOV_RECORD_MANIFEST, $prefix . pack('N', PCOV_RECORD_MAX_FILES + 1)), 'file count');
$reject($envelope(PCOV_RECORD_MANIFEST, $prefix . pack('NN', 1, PCOV_RECORD_MAX_PATH_LENGTH + 1)), 'filename length');
$reject($envelope(PCOV_RECORD_MANIFEST, hex2bin($environment) . str_repeat("\0", 32) . pack('NN', 7, 0)), 'reason');

/* A full record with an unavailable fingerprint parses, but the strict merger
 * rejects it instead of creating a candidate from unproved source state. */
$fixture = '/tmp/pcov-lifecycle-corrupt-source-' . getmypid() . '.php';
$manifest = '/tmp/pcov-lifecycle-corrupt-manifest-' . getmypid() . '.bin';
file_put_contents($fixture, "<?php\nreturn 1;\n");
$files = [$fixture => ['fingerprint' => hash_file('sha256', $fixture), 'lines' => [2 => -1]]];
pcov_atomic_publish($manifest, pcov_manifest_encode($environment, $files));
$loadedManifest = pcov_record_load($manifest);
$fullPayload = hex2bin($environment) . hex2bin($loadedManifest['manifest_id']) . pack('NN', 7, 1);
$fullPayload .= pack('N', strlen($fixture)) . $fixture . pack('N', 5) . str_repeat("\0", 32) . pack('N', 0);
$full = $envelope(PCOV_RECORD_FULL, $fullPayload);
file_put_contents($path, $full);
try {
    pcov_manifest_merge($manifest, [$path]);
    var_dump(false);
} catch (RuntimeException $exception) {
    var_dump(str_contains($exception->getMessage(), 'unavailable'));
}

foreach ([$path, $fixture, $manifest] as $file) if (is_file($file)) unlink($file);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
