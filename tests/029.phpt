--TEST--
immutable lifecycle merge builds deterministic candidates and rejects old manifest identities
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
$base = "/tmp/pcov-lifecycle-base-{$id}.php";
$added = "/tmp/pcov-lifecycle-added-{$id}.php";
$manifestN = "/tmp/pcov-lifecycle-n-{$id}.bin";
$manifestN1 = "/tmp/pcov-lifecycle-n1-{$id}.bin";
$removedCandidate = "/tmp/pcov-lifecycle-removed-{$id}.bin";
$hit = "/tmp/pcov-lifecycle-hit-{$id}.bin";
$fallback = "/tmp/pcov-lifecycle-full-{$id}.bin";

file_put_contents($base, <<<'PHP'
<?php
function pcov_lifecycle_base(bool $yes): int {
    if ($yes) return 1;
    return 0;
}
PHP);
require $base;
$environment = pcov_manifest_environment_identity([
    'application_revision' => 'lifecycle-fixture',
    'dependency_lock' => str_repeat('1', 64),
    'modules_config' => str_repeat('2', 64),
    'generated_code' => str_repeat('3', 64),
    'source_tree' => str_repeat('4', 64),
]);

pcov\start(); pcov_lifecycle_base(true); pcov\stop();
$baseCoverage = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$base]));
$created = pcov_manifest_create_from_coverage($manifestN, $environment['id'], $baseCoverage);

pcov\clear();
pcov\start(); pcov_lifecycle_base(false); pcov\stop();
$hitResult = pcov\export($hit, $manifestN, $environment['id'], pcov\inclusive, [$base]);
var_dump($hitResult['mode'] === 'hit-only');

file_put_contents($added, <<<'PHP'
<?php
function pcov_lifecycle_added(bool $yes): int {
    $value = 10;
    if ($yes) $value++;
    return $value;
}
PHP);
pcov\clear();
pcov\start(); require $added; pcov_lifecycle_base(true); pcov_lifecycle_added(true); pcov\stop();
$reference = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$base, $added]));

pcov\clear();
pcov\start(); pcov_lifecycle_base(true); pcov_lifecycle_added(true); pcov\stop();
$fallbackResult = pcov\export(
    $fallback, $manifestN, $environment['id'], pcov\inclusive, [$base, $added]
);
$fallbackRecord = pcov_record_load($fallback);
var_dump($fallbackResult['mode'] === 'full-fallback');
var_dump($fallbackResult['reason'] === 'new-loaded-file');
$expectedFallback = $reference;
$expectedFallback[$added][max(array_keys($expectedFallback[$added]))] = -1;
var_dump(pcov_coverage_normalize($fallbackRecord['records']) === $expectedFallback);

$hitsOnly = pcov_manifest_merge($manifestN, [$hit]);
var_dump($hitsOnly['coverage_complete']);
var_dump($hitsOnly['candidate_complete']);

$merged = pcov_manifest_merge($manifestN, [$fallback, $hit]);
$reordered = pcov_manifest_merge($manifestN, [$hit, $fallback]);
var_dump(!$merged['coverage_complete']);
var_dump($merged['requires_full_discovery']);
var_dump($merged['candidate_manifest_id'] === $reordered['candidate_manifest_id']);
var_dump($merged['coverage'] === $reordered['coverage']);
var_dump($merged['delta_files'] === [$added, $base]);

$published = pcov_manifest_publish_candidate(
    $manifestN1, $merged, pcov_manifest_source_inventory([$base, $added])
);
var_dump($published['manifest_id'] === $merged['candidate_manifest_id']);
var_dump(file_get_contents($manifestN) !== file_get_contents($manifestN1));

try {
    pcov_manifest_merge($manifestN1, [$hit]);
    var_dump(false);
} catch (RuntimeException $exception) {
    var_dump(str_contains($exception->getMessage(), 'Manifest identity mismatch'));
}
var_dump(pcov_manifest_merge($manifestN, [$hit])['coverage_complete']);

$removed = pcov_manifest_publish_candidate(
    $removedCandidate, $merged, pcov_manifest_source_inventory([$added])
);
$removedRecord = pcov_record_load($removedCandidate);
var_dump($removed['files'] === 1);
var_dump(!isset($removedRecord['records'][$base]));
var_dump(isset($removedRecord['records'][$added]));

try {
    pcov_manifest_publish_candidate(
        $removedCandidate, $merged, ['/tmp/not-discovered.php' => str_repeat('0', 64)]
    );
    var_dump(false);
} catch (RuntimeException $exception) {
    var_dump(str_contains($exception->getMessage(), 'full discovery pass'));
}

foreach ([$base, $added, $manifestN, $manifestN1, $removedCandidate, $hit, $fallback] as $path) {
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
bool(true)
