--TEST--
concurrent processes read one immutable manifest and produce deterministic hit and delta merges
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!extension_loaded('pcntl')) print 'skip pcntl unavailable';
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
$base = "/tmp/pcov-concurrent-base-{$id}.php";
$manifest = "/tmp/pcov-concurrent-manifest-{$id}.bin";
file_put_contents($base, "<?php\nfunction pcov_concurrent_base(int \$v): int { return \$v + 1; }\n");
require $base;
$identity = pcov_manifest_environment_identity([
    'application_revision' => 'concurrent',
    'dependency_lock' => str_repeat('1', 64),
    'modules_config' => str_repeat('2', 64),
    'generated_code' => str_repeat('3', 64),
    'source_tree' => str_repeat('4', 64),
]);
pcov\start(); pcov_concurrent_base(1); pcov\stop();
$coverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$base]));
$manifestStats = pcov_manifest_create_from_coverage($manifest, $identity['id'], $coverage);

$children = [];
$dumps = [];
for ($worker = 0; $worker < 6; $worker++) {
    $output = "/tmp/pcov-concurrent-hit-{$id}-{$worker}.bin";
    $dumps[] = $output;
    $pid = pcntl_fork();
    if ($pid === 0) {
        pcov\clear(); pcov\start(); pcov_concurrent_base($worker); pcov\stop();
        $result = pcov\export(
            $output, $manifest, $identity['id'], pcov\inclusive, [$base]
        );
        exit(is_array($result) && $result['mode'] === 'hit-only' ? 0 : 1);
    }
    $children[] = $pid;
}
$ok = true;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $ok = $ok && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
}
var_dump($ok);
var_dump(file_get_contents($manifest) !== '');
$merged = pcov_manifest_merge($manifest, $dumps);
$reverse = pcov_manifest_merge($manifest, array_reverse($dumps));
var_dump($merged['coverage'] === $reverse['coverage']);
var_dump($merged['candidate_manifest_id'] === $manifestStats['manifest_id']);
var_dump($merged['stats']['hit_dumps'] === 6);
var_dump($merged['stats']['hit_entries_before_deduplication'] >
    $merged['stats']['hit_entries_after_deduplication']);
var_dump($merged['stats']['hit_entries_after_deduplication'] > 0);

/* Concurrent fallback deltas add disjoint files without touching manifest N. */
$deltaDumps = [];
$deltaFiles = [];
$children = [];
for ($worker = 0; $worker < 4; $worker++) {
    $file = "/tmp/pcov-concurrent-delta-file-{$id}-{$worker}.php";
    $output = "/tmp/pcov-concurrent-delta-{$id}-{$worker}.bin";
    file_put_contents($file, "<?php\n\$GLOBALS['pcov_delta_{$worker}'] = {$worker};\n");
    $deltaFiles[] = $file;
    $deltaDumps[] = $output;
    $pid = pcntl_fork();
    if ($pid === 0) {
        pcov\clear(true); pcov\start(); require $file; pcov\stop();
        $result = pcov\export(
            $output, $manifest, $identity['id'], pcov\inclusive, [$file]
        );
        exit(is_array($result) && $result['mode'] === 'full-fallback' ? 0 : 1);
    }
    $children[] = $pid;
}
$ok = true;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $ok = $ok && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
}
var_dump($ok);
$deltaMerge = pcov_manifest_merge($manifest, $deltaDumps);
$deltaReverse = pcov_manifest_merge($manifest, array_reverse($deltaDumps));
var_dump($deltaMerge['candidate_manifest_id'] === $deltaReverse['candidate_manifest_id']);
var_dump($deltaMerge['coverage'] === $deltaReverse['coverage']);
var_dump($deltaMerge['stats']['full_fallbacks'] === 4);
var_dump(file_get_contents($manifest) !== '');

foreach (array_merge([$base, $manifest], $dumps, $deltaDumps, $deltaFiles) as $path) {
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
