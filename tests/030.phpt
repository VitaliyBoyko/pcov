--TEST--
validated lifecycle detects modified unexecuted code, no-hit files, corrupt manifests, and identity changes
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
$fixture = "/tmp/pcov-stale-fixture-{$id}.php";
$silent = "/tmp/pcov-stale-silent-{$id}.php";
$manifest = "/tmp/pcov-stale-manifest-{$id}.bin";
$corrupt = "/tmp/pcov-stale-corrupt-{$id}.bin";
$incompatible = "/tmp/pcov-stale-incompatible-{$id}.bin";
$paths = [];
$environment = pcov_manifest_environment_identity([
    'application_revision' => 'stale-v1',
    'dependency_lock' => str_repeat('a', 64),
    'modules_config' => str_repeat('b', 64),
    'generated_code' => str_repeat('c', 64),
    'source_tree' => str_repeat('d', 64),
]);
$otherEnvironment = pcov_manifest_environment_identity([
    'application_revision' => 'stale-v2',
    'dependency_lock' => str_repeat('a', 64),
    'modules_config' => str_repeat('b', 64),
    'generated_code' => str_repeat('c', 64),
    'source_tree' => str_repeat('e', 64),
]);

$oldSource = <<<'PHP'
<?php
$GLOBALS['pcov_stale_value'] = 1;
PHP;
file_put_contents($fixture, $oldSource);
pcov\start(); require $fixture; pcov\stop();
$oldCoverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
pcov_manifest_create_from_coverage($manifest, $environment['id'], $oldCoverage);

/* Recompile a modified file in the same request after dropping retained files.
 * The newly added branch is executable but deliberately unexecuted. */
pcov\clear(true);
$newSource = <<<'PHP'
<?php
$GLOBALS['pcov_stale_value'] = 1;
if (($GLOBALS['pcov_never_set'] ?? false)) {
    $GLOBALS['pcov_stale_value']++;
}
PHP;
file_put_contents($fixture, $newSource);
pcov\start(); require $fixture; pcov\stop();
$modifiedPath = "/tmp/pcov-stale-modified-{$id}.bin"; $paths[] = $modifiedPath;
$modified = pcov\export(
    $modifiedPath, $manifest, $environment['id'], pcov\inclusive, [$fixture]
);
$modifiedRecord = pcov_record_load($modifiedPath);
var_dump($modified['reason'] === 'modified-loaded-file');
var_dump($modifiedRecord['record_type'] === PCOV_RECORD_FULL);
var_dump(in_array(-1, $modifiedRecord['records'][$fixture], true));
var_dump($modifiedRecord['fingerprint_status'][$fixture] === 0);

/* A wanted file compiled while recording is stopped still has to invalidate N. */
file_put_contents($silent, "<?php\n\$GLOBALS['pcov_silent'] = 1;\nif (false) { \$GLOBALS['pcov_silent']++; }\n");
pcov\clear(true);
pcov\stop(); require $silent;
$silentPath = "/tmp/pcov-stale-silent-dump-{$id}.bin"; $paths[] = $silentPath;
$silentResult = pcov\export(
    $silentPath, $manifest, $environment['id'], pcov\inclusive, [$silent]
);
$silentRecord = pcov_record_load($silentPath);
var_dump($silentResult['reason'] === 'new-loaded-file');
var_dump(isset($silentRecord['records'][$silent]));
var_dump(!in_array(1, $silentRecord['records'][$silent], true));
var_dump($silentRecord['fingerprint_status'][$silent] === 0);

/* Bad input never authorizes hit-only output. */
$manifestData = file_get_contents($manifest);
file_put_contents($corrupt, substr($manifestData, 0, -3));
file_put_contents($incompatible, substr_replace(
    $manifestData, pack('N', PHP_VERSION_ID - 1), 36, 4
));
foreach ([
    [$corrupt, $environment['id'], 'manifest-corrupt'],
    [$incompatible, $environment['id'], 'runtime-compatibility'],
    [$manifest, $otherEnvironment['id'], 'environment-identity'],
] as $index => [$input, $identity, $reason]) {
    pcov\clear(); pcov\start(); pcov\stop();
    $output = "/tmp/pcov-stale-invalid-{$id}-{$index}.bin"; $paths[] = $output;
    $result = pcov\export($output, $input, $identity, pcov\inclusive, [$silent]);
    $record = pcov_record_load($output);
    var_dump($result['mode'] === 'full-fallback');
    var_dump($result['reason'] === $reason);
    var_dump($record['record_type'] === PCOV_RECORD_FULL);
}

try {
    pcov\export('/tmp/unused', $manifest, 'not-a-sha256');
    var_dump(false);
} catch (TypeError $error) {
    var_dump(str_contains($error->getMessage(), '64 hexadecimal'));
}

foreach (array_merge([$fixture, $silent, $manifest, $corrupt, $incompatible], $paths) as $path) {
    if (is_file($path)) unlink($path);
}
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
