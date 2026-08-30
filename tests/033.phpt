--TEST--
validated dump reports publication failure and leaves coverage retryable
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!function_exists('pcov\\export')) print 'skip validated export unavailable';
?>
--INI--
pcov.enabled=1
pcov.directory=/tmp
pcov.large_codebase=1
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';
$id = getmypid();
$fixture = "/tmp/pcov-validated-write-{$id}.php";
$manifest = "/tmp/pcov-validated-write-manifest-{$id}.bin";
$output = "/tmp/pcov-validated-write-output-{$id}.bin";
file_put_contents($fixture, "<?php\nfunction pcov_validated_write(): int { return 1; }\n");
require $fixture;
$identity = pcov_manifest_environment_identity([
    'application_revision' => 'write', 'dependency_lock' => 'a',
    'modules_config' => 'b', 'generated_code' => 'c', 'source_tree' => 'd',
]);
pcov\start(); pcov_validated_write(); pcov\stop();
$coverage = pcov\collect(pcov\inclusive, [$fixture]);
pcov_manifest_create_from_coverage($manifest, $identity['id'], $coverage);
pcov\clear(); pcov\start(); pcov_validated_write(); pcov\stop();

set_error_handler(static function (int $severity, string $message): bool {
    var_dump(str_contains($message, 'temporary-file open failed'));
    return true;
});
$failed = pcov\export(
    "/tmp/pcov-no-such-directory-{$id}/dump.bin",
    $manifest,
    $identity['id'],
    pcov\inclusive,
    [$fixture]
);
restore_error_handler();
var_dump($failed);
$retried = pcov\export(
    $output, $manifest, $identity['id'], pcov\inclusive, [$fixture]
);
var_dump($retried['mode'] === 'hit-only');
var_dump(pcov_record_load($output)['records'] !== []);
var_dump(glob($output . '.pcovtmp.*'));

foreach ([$fixture, $manifest, $output] as $path) if (is_file($path)) unlink($path);
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(true)
array(0) {
}
