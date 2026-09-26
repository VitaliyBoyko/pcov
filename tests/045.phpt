--TEST--
Native Magento cache needs only the flag and preserves actual hits with the unchanged collector
--SKIPIF--
<?php if (!extension_loaded('pcov') || !function_exists('proc_open')) print 'skip'; ?>
--INI--
pcov.enabled=1
pcov.large_codebase=1
pcov.directory=/tmp
--FILE--
<?php
require __DIR__ . '/native_request_cache.inc.php';
$one = $request(); $two = $request();
$check($one['result']['cache'] === 'miss' && $two['result']['cache'] === 'hit', 'flag alone enables native reuse');
$check($two['result']['mode'] === 'hit-only' && $two['stats']['request_cache_hit'] &&
    $two['stats']['validated_checksum_calls'] === 0 && $two['stats']['validated_emit_entries'] === 0,
    'cache hit skips native serialization and checksum');
$check($one['path'] !== $two['path'] && is_file($two['path']) &&
    file_get_contents($one['path']) === file_get_contents($two['path']), 'collector receives its own standard record');
$first = pcov_record_load($one['path'])['records'];
file_put_contents("$root/state", '1');
$changed = $request();
$check($changed['result']['cache'] === 'miss' && $changed['value'] === 22 &&
    pcov_record_load($changed['path'])['records'] !== $first, 'same GET with new application state retains new hits');
$check($request()['result']['cache'] === 'hit', 'new hit set can be reused');
$configure(['directory' => "$root/other"]);
$check($request()['result']['cache'] === 'miss', 'output directory isolates suites');
$configure(['directory' => "$root/records", 'clear' => true]);
$cleared = $request();
$check($cleared['result']['cache'] === 'miss' && pcov_record_load($cleared['path'])['records'] === [], 'clear never resurrects earlier hits');
$configure(['clear' => false, 'filter' => []]);
$filtered = $request();
$check($filtered['result']['cache'] === 'miss' && pcov_record_load($filtered['path'])['records'] === [], 'changed export filter retains its meaning');
$configure(['filter' => [$fixture]]);
$request();
$configure(['fail' => true]);
$failed = $request();
$check($failed['result'] === false && $failed['stats']['request_cache_hit'] &&
    $failed['retried']['cache'] === 'hit' && $failed['temporaries'] === [] && is_file($failed['path']),
    'cache publication failures remain retryable and clean');
$configure(['fail' => false]);
$request(method: 'POST');
$check($request()['result']['cache'] === 'miss', 'mutating request evicts worker cache');
$merged = pcov_manifest_merge($manifest, glob("$root/records/*.pcov"));
$check($merged['coverage_complete'] && $merged['coverage'][$fixture] === $coverage[$fixture], 'standard merger preserves complete aggregate coverage');
?>
--EXPECT--
flag alone enables native reuse
cache hit skips native serialization and checksum
collector receives its own standard record
same GET with new application state retains new hits
new hit set can be reused
output directory isolates suites
clear never resurrects earlier hits
changed export filter retains its meaning
cache publication failures remain retryable and clean
mutating request evicts worker cache
standard merger preserves complete aggregate coverage
