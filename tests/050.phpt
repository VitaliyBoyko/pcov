--TEST--
bulk line decoding rejects malformed records and merge deduplicates hits across full and hit records
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
?>
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';
$root = '/tmp/pcov-bulk-lines-' . getmypid();
mkdir($root);
$path = "$root/record.pcov";
$manifest = "$root/manifest.pcov";
$file = "$root/source.php";
file_put_contents($file, "<?php\nreturn 7;\n");
$environment = str_repeat('a', 64);
$fingerprint = hash_file('sha256', $file);
file_put_contents($manifest, pcov_manifest_encode($environment, [
    $file => ['fingerprint' => $fingerprint, 'lines' => [2 => -1, 3 => -1]],
]));
$manifestId = pcov_record_load($manifest)['manifest_id'];
$encode = static function (int $type, int $count, array $words) use (
    $file, $environment, $manifestId, $fingerprint
): string {
    $prefix = hex2bin($environment . $manifestId) . pack('N', $type === PCOV_RECORD_FULL ? 7 : 0);
    $metadata = pack('N', strlen($file)) . $file;
    if ($type === PCOV_RECORD_HITS) {
        $payload = $prefix . pack('N', 1) . $metadata . hex2bin($fingerprint)
            . pack('N', 1) . $metadata;
    } else {
        $payload = $prefix . pack('N', 1) . $metadata
            . ($type === PCOV_RECORD_FULL ? pack('N', 0) : '') . hex2bin($fingerprint);
    }
    $payload .= pack('N', $count) . ($words ? pack('N*', ...$words) : '');
    return "PCOVREC\0" . pack('N*', 1, 48, $type, 0, 0, strlen($payload),
        crc32($payload), PHP_VERSION_ID, 1, 0) . $payload;
};
foreach ([PCOV_RECORD_MANIFEST, PCOV_RECORD_HITS, PCOV_RECORD_FULL] as $type) {
    $words = $type === PCOV_RECORD_FULL ? [2, 0xffffffff, 3, 1] : [2, 3];
    file_put_contents($path, $encode($type, 2, $words));
    $loaded = pcov_record_load($path)['records'][$file];
    var_dump($loaded === ($type === PCOV_RECORD_FULL ? [2 => -1, 3 => 1]
        : ($type === PCOV_RECORD_HITS ? [2 => 1, 3 => 1] : [2 => -1, 3 => -1])));
    file_put_contents($path, $encode($type, 0, []));
    var_dump(pcov_record_load($path)['records'][$file] === []);
    $invalid = $type === PCOV_RECORD_FULL
        ? [[2, [0, 1, 3, 1]], [2, [2, 1, 2, 1]], [2, [3, 1, 2, 1]], [2, [2, 1]], [1, [2, 0]]]
        : [[2, [0, 3]], [2, [2, 2]], [2, [3, 2]], [2, [2]]];
    $rejected = 0;
    foreach ($invalid as [$count, $words]) {
        file_put_contents($path, $encode($type, $count, $words));
        try { pcov_record_load($path); } catch (RuntimeException $e) { $rejected++; }
    }
    var_dump($rejected === count($invalid));
}
$hits = "$root/hits.pcov";
$full = "$root/full.pcov";
file_put_contents($hits, $encode(PCOV_RECORD_HITS, 1, [2]));
file_put_contents($full, $encode(PCOV_RECORD_FULL, 2, [2, 0xffffffff, 3, 1]));
$first = pcov_manifest_merge($manifest, [$hits, $full, $hits, $full]);
$second = pcov_manifest_merge($manifest, [$full, $hits, $full, $hits]);
var_dump($first['coverage'] === [$file => [2 => 1, 3 => 1]]);
var_dump($second['coverage'] === $first['coverage']);
foreach ([$first, $second] as $merge) {
    var_dump($merge['stats']['hit_entries_before_deduplication'] === 4
        && $merge['stats']['hit_entries_after_deduplication'] === 2);
}
foreach (glob("$root/*") as $item) unlink($item);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
