--TEST--
native fingerprints and record checksums preserve SHA-256 and CRC32 across block boundaries
--SKIPIF--
<?php
if (!function_exists('pcov\\export')) print 'skip native export unavailable';
?>
--INI--
pcov.enabled=1
pcov.directory=/tmp
pcov.large_codebase=1
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';
$root = '/tmp/pcov-hash-boundaries-' . getmypid();
mkdir($root);
$files = [];
foreach ([55, 56, 57, 63, 64, 65, 127, 128, 129, 32767, 32768, 32769, 65537] as $length) {
    $prefix = '<?php /*';
    $suffix = '*/ return 7;';
    $path = "$root/source-$length.php";
    file_put_contents($path, $prefix . str_repeat('x', $length - strlen($prefix . $suffix)) . $suffix);
    $files[] = $path;
}
pcov\start();
foreach ($files as $file) require $file;
pcov\stop();
$full = "$root/full.pcov";
$manifest = "$root/manifest.pcov";
$hits = "$root/hits.pcov";
$environment = str_repeat('a', 64);
pcov\export($full, null, $environment, pcov\inclusive, $files);
$record = pcov_record_load($full);
$valid = count($record['records']) === count($files);
foreach ($files as $file) {
    $valid = $valid && $record['fingerprint_status'][$file] === 0
        && $record['fingerprints'][$file] === hash_file('sha256', $file);
}
var_dump($valid);
$raw = file_get_contents($full);
var_dump(unpack('N', substr($raw, 32, 4))[1] === crc32(substr($raw, 48)));
pcov_manifest_bootstrap($manifest, $environment, [$full]);
pcov\clear();
pcov\start();
foreach ($files as $file) require $file;
pcov\stop();
$result = pcov\export($hits, $manifest, $environment, pcov\inclusive, $files);
var_dump($result['mode'] === 'hit-only');
$merged = pcov_manifest_merge($manifest, [$hits, $hits]);
var_dump($merged['coverage'] === $record['records']);
var_dump($merged['stats']['hit_entries_before_deduplication'] === 2 * count($files));
var_dump($merged['stats']['hit_entries_after_deduplication'] === count($files));
$raw = file_get_contents($manifest);
$raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
file_put_contents("$root/corrupt.pcov", $raw);
$result = pcov\export("$root/fallback.pcov", "$root/corrupt.pcov", $environment, pcov\inclusive, $files);
var_dump($result['mode'] === 'full-fallback' && $result['reason'] === 'manifest-corrupt');
foreach (glob("$root/*") as $file) unlink($file);
rmdir($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
