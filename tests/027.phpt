--TEST--
manifest environment identities and source fingerprints are deterministic
--SKIPIF--
<?php
if (!extension_loaded('pcov')) print 'skip';
?>
--FILE--
<?php
require dirname(__DIR__) . '/tools/pcov_manifest_tools.php';

$first = pcov_manifest_environment_identity([
    'application_revision' => 'revision-1',
    'dependency_lock' => str_repeat('a', 64),
    'modules_config' => str_repeat('b', 64),
    'generated_code' => str_repeat('c', 64),
    'source_tree' => str_repeat('d', 64),
    'extra' => ['z' => 1, 'a' => true],
]);
$second = pcov_manifest_environment_identity([
    'source_tree' => str_repeat('d', 64),
    'generated_code' => str_repeat('c', 64),
    'modules_config' => str_repeat('b', 64),
    'dependency_lock' => str_repeat('a', 64),
    'application_revision' => 'revision-1',
    'extra' => ['a' => true, 'z' => 1],
]);

var_dump($first['id'] === $second['id']);
var_dump($first['canonical_json'] === $second['canonical_json']);
var_dump(strlen($first['id']) === 64);
var_dump($first['document']['runtime']['php_version_id'] === PHP_VERSION_ID);
var_dump(pcov_manifest_source_fingerprint(__FILE__) === hash_file('sha256', __FILE__));

try {
    pcov_manifest_environment_identity([]);
    var_dump(false);
} catch (InvalidArgumentException $exception) {
    var_dump(str_contains($exception->getMessage(), 'application_revision'));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
