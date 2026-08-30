--TEST--
validated hit lookup profiling records sorted linear-search behavior and fail-closed misses
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
if (!function_exists('pcov\\export')) print 'skip validated export unavailable';
?>
--ENV--
PCOV_DUMP_BENCH_STATS=1
--INI--
pcov.enabled=1
pcov.directory=/tmp
pcov.large_codebase=1
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

$id = getmypid();
$fixture = "/tmp/pcov-hit-lookup-{$id}.php";
$manifest = "/tmp/pcov-hit-lookup-manifest-{$id}.pcov";
$stale = "/tmp/pcov-hit-lookup-stale-{$id}.pcov";
$dump = "/tmp/pcov-hit-lookup-dump-{$id}.pcov";
$source = <<<'PHP'
<?php
function pcov_hit_lookup(int $value): int {
    $result = 1;
    if ($value > 0) {
        $result++;
    }
    return $result;
}
PHP;
file_put_contents($fixture, $source);
require $fixture;

pcov\start();
pcov_hit_lookup(1);
pcov\stop();
$reference = pcov_coverage_normalize(pcov\collect(pcov\inclusive, [$fixture]));
$positive = array_keys(array_filter($reference[$fixture], static fn (int $value): bool => $value === 1));
$identity = hash('sha256', 'pcov-hit-lookup-profile-v1');
pcov_manifest_create_from_coverage($manifest, $identity, $reference);

pcov\clear();
pcov\start();
pcov_hit_lookup(1);
pcov_hit_lookup(1);
pcov_hit_lookup(1);
pcov\stop();
$result = pcov\export($dump, $manifest, $identity, pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();
$hits = count($positive);
var_dump($result['mode'] === 'hit-only');
var_dump($stats['manifest_hit_entries_validated'] === $hits);
var_dump($stats['manifest_hit_search_restarts'] === $hits);
var_dump($stats['manifest_hit_matches'] === $hits);
var_dump($stats['manifest_hit_duplicates'] === 0);
var_dump($stats['manifest_hit_line_comparisons'] >= $hits);
var_dump($stats['manifest_hit_line_records_decoded'] === $stats['manifest_hit_line_comparisons']);
var_dump($stats['manifest_hit_unknown_lines'] === 0);
var_dump($stats['manifest_hit_unknown_files'] === 0);

$staleLines = $reference[$fixture];
unset($staleLines[$positive[array_key_last($positive)]]);
pcov_atomic_publish($stale, pcov_manifest_encode($identity, [
    $fixture => [
        'fingerprint' => hash_file('sha256', $fixture),
        'lines' => array_fill_keys(array_keys($staleLines), -1),
    ],
]));
pcov\clear();
pcov\start();
pcov_hit_lookup(1);
pcov\stop();
$result = pcov\export($dump, $stale, $identity, pcov\inclusive, [$fixture]);
$stats = pcov\export_stats();
var_dump($result['mode'] === 'full-fallback');
var_dump($result['reason'] === 'hit-not-in-manifest');
var_dump($stats['manifest_hit_unknown_lines'] === 1);
var_dump($stats['manifest_hit_unknown_files'] === 0);

foreach ([$fixture, $manifest, $stale, $dump] as $path) {
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
