# PCOV for large PHP codebases

This is an alternative distribution of [PCOV](https://github.com/krakjoe/pcov)
for aggregate coverage of large PHP test suites. It keeps PCOV's fast opcode
recording and all upstream APIs, and adds a synchronous native export path that
can reuse an immutable executable-line manifest.

Normal PCOV records positive line hits while PHP executes. `pcov\collect()`
later runs Zend control-flow-graph (CFG) discovery to find executable but
unexecuted lines and builds the nested PHP coverage array. Repeating that work
for many web requests can dominate coverage collection in a large application.

```text
Normal full collection:
every request repeatedly discovers all executable lines

Manifest mode:
discover executable lines once
validate that code is unchanged
save only lines actually executed
fall back to full collection whenever validity is uncertain
```

Nothing is forked from PHP-FPM, no work is delegated to another process, and
request export remains synchronous. Native full collection is always the
correctness fallback. The optimization is designed for coverage aggregated
across an entire suite, not for a standalone request report.

## Requirements and installation

Release 2.1.1 supports Linux, NTS PHP 8.3, 8.4, and 8.5. It uses the extension
name `pcov`, so it replaces and cannot be loaded beside official PCOV.

Install with [PIE](https://github.com/php/pie), the PHP extension installer:

```sh
pie install vitaliyboyko/pcov
```

Or build from source:

```sh
phpize
./configure --enable-pcov
make -j"$(getconf _NPROCESSORS_ONLN)"
make test
sudo make install
```

Enable it in the dedicated coverage SAPI:

```ini
extension=pcov.so
pcov.enabled=1
pcov.large_codebase=1
pcov.directory=/path/to/project
```

`pcov.large_codebase` controls manifest export and defaults to `1`. Set it
to `0` to retain upstream collection behavior without compile fingerprints or
native export. Application sources must be immutable during a request and an
OPcache generation must not mix deployments; use atomic deployment paths and
reset OPcache when switching generations.

## Upstream-compatible API

The original API and line-coverage semantics are unchanged:

```php
pcov\start();
// Execute application or test code.
pcov\stop();

$coverage = pcov\collect(); // array<string, array<int, -1|1>>
pcov\clear();
```

`pcov\waiting()`, `pcov\memory()`, `pcov\enabled()`, `pcov\all`,
`pcov\inclusive`, and `pcov\exclusive` remain available.

## Large-codebase workflow

### 1. Define the deployment identity

The identity must change when application code, dependencies, enabled modules,
generated code, or the relevant source inventory changes:

```sh
DEPLOYMENT_ID=$(php -r '
require "tools/pcov_manifest_tools.php";
echo pcov_manifest_environment_identity([
    "application_revision" => "release-2026-08-30",
    "dependency_lock" => hash_file("sha256", "composer.lock"),
    "modules_config" => hash("sha256", "enabled-modules-v1"),
    "generated_code" => hash("sha256", "generated-code-v1"),
    "source_tree" => hash("sha256", "source-inventory-v1"),
])["id"];
')
```

Use authoritative build inputs in place of the generic strings. Timestamps
alone are not a deployment identity.

### 2. Generate an immutable manifest

During one complete reference suite, export full records tagged with that
identity:

```php
$result = pcov\export(
    '/path/to/bootstrap/request-1.pcov',
    null,
    getenv('DEPLOYMENT_ID') ?: throw new RuntimeException('Missing deployment identity')
);

if ($result === false || $result['mode'] !== 'full') {
    throw new RuntimeException('Full PCOV export failed');
}
```

Build and atomically publish the immutable manifest from all reference records:

```sh
php tools/pcov_manifest.php create \
  /path/to/manifests/manifest-v1.pcov \
  "$DEPLOYMENT_ID" \
  /path/to/bootstrap/*.pcov
```

The reference suite must load the complete coverage scope. Keep old manifest
generations while request records still reference them.

### 3. Export validated request hits

```php
$result = pcov\export(
    '/path/to/records/request-42.pcov',
    '/path/to/manifests/manifest-v1.pcov',
    getenv('DEPLOYMENT_ID') ?: throw new RuntimeException('Missing deployment identity')
);

if ($result === false) {
    throw new RuntimeException('PCOV publication failed');
}

// "hit-only" when validation succeeds, "full-fallback" otherwise.
error_log("PCOV export mode: {$result['mode']} ({$result['reason']})");
```

PCOV validates the runtime identity, every selected loaded file, SHA-256 source
fingerprints, and every positive hit. New, changed, unreadable, raced, unknown,
or incompatible code synchronously produces a complete full-fallback record.
Temporary files are unique and publication uses atomic rename.

### 4. Merge into the normal PCOV representation

```sh
php tools/pcov_manifest.php merge \
  /path/to/coverage.php \
  /path/to/manifests/manifest-v1.pcov \
  /path/to/records/*.pcov
```

`coverage.php` returns the normal `file => line => -1|1` array. The merger is
deterministic and idempotent. It rejects corrupt records, mismatched identities,
unknown files or lines, and incomplete fallback cohorts. A fallback is never
silently reported as complete; rebuild the immutable manifest from a complete
full-discovery suite before publishing the next generation.

See [Manifest and record architecture](docs/manifest-and-records.md) for the
identity, validation, fallback, and binary-format contract.

## Added API

```php
pcov\export(
    string $path,
    ?string $manifest = null,
    ?string $deploymentId = null,
    int $type = pcov\all,
    array $filter = []
): array|false;

pcov\export_stats(): array;
```

With no manifest, `export()` writes complete native coverage. Passing a
deployment ID tags that full record for manifest creation. With a manifest and
deployment ID, it writes validated hits or a synchronous full fallback.
`export_stats()` exposes diagnostics used by the benchmark suite.

Coverage records use a fixed-width, big-endian, checksummed format (version 1)
with strict bounds checking. Records are intentionally tied to an exact
`PHP_VERSION_ID`; regenerate manifests when changing PHP or PCOV versions.

## Benchmarks — 2.1.1

### Magento

Magento 2.4.8 / PHP 8.3.31, 30 GETs across two routes; median of three rotating
rounds. Total time includes requests and coverage merge; export is part of it.

| Extension / setting | Total time | Export time | Output |
|---|---:|---:|---:|
| Official PCOV 1.0.12 | 9.113 s | 5.302 s | 11.56 MB |
| PCOV 2.1.1, cache off | 4.553 s | 0.131 s | 7.70 MB |
| PCOV 2.1.1, cache on | 4.473 s | 0.120 s | 7.70 MB |

**2.1.1 with cache on reduced total time by 50.9% versus official PCOV.**
Compared with the same 2.1.1 build with cache off, enabling the cache reduced
export time by 8.7% and total time by 1.7%, reusing 24/30 records per round.
All nine rounds produced identical coverage: 1,434 files and 42,145 executable
lines. [Measurements](benchmark/measurements/2.1.1-magento.json).

### Synthetic CLI

PHP 8.5.11, 1,500 files, 64,500 executable lines, ten requests per round and ten
rotating rounds. The GET cache is inactive in CLI. Total time and CPU include merge.

| Extension | Total median / p95 | CPU median / p95 | Export median | Output |
|---|---:|---:|---:|---:|
| Official PCOV 1.0.12 | 0.854 / 0.886 s | 0.854 / 0.885 s | 142.8 ms | 694,580 B |
| PCOV 2.1.1 | 0.775 / 0.806 s | 0.775 / 0.805 s | 11.9 ms | 517,240 B |

**2.1.1 reduced total time by 9.2% and export time by 91.7% versus official PCOV.**
Coverage matched in all 20 runs. [Summary](benchmark/measurements/2.1.1-synthetic-summary.json)
· [Raw rounds](benchmark/measurements/2.1.1-synthetic.jsonl).

PCOV 2.1.1 uses an existing manifest in both benchmarks; initial manifest
bootstrap is excluded.

```sh
benchmark/large-codebase/run.sh
python3 benchmark/magento/run.py /path/to/visual-demo
```

## Operational limits

- Use this extension only in a dedicated coverage SAPI. Like official PCOV,
  its executor hooks are not compatible with Xdebug, phpdbg coverage, or other
  tools replacing the same hooks.
- Sources and active manifests must be immutable. Publish new generations to a
  new path by atomic rename; never truncate an active manifest.
- Compile-side fingerprints are reused only while canonical path, device,
  inode, mode, size, nanosecond mtime, and nanosecond ctime still match. A
  mismatch rereads and hashes the source; uncertainty falls back fully.
- Eval code, unsupported streams, unreadable files, and source races fail
  closed.
- The merger needs an authoritative complete reference suite to account for
  files no request loaded and files removed between deployments.
- This release supports Linux NTS only. Export is synchronous and all mutable
  coverage state remains process-local.

## Native Magento GET coverage cache (2.1.1)

Adds native GET coverage reuse and optimizes source hashing, checksums, and merging.
Enable the cache with:

```ini
pcov.request_magento_cache=1
```

Restart PHP and keep your existing `pcov\start()` / `stop()` / `export()`
collector unchanged. No PHP cache helper or invalidation calls are needed.
Every request still records and validates its actual hits; matching GETs reuse
serialized coverage and write a normal record at the requested path. Magento's
private/no-store response headers do not prevent this: HTTP responses are not
cached. Requires a valid manifest and `pcov.large_codebase=1`.

The flag defaults to `0`. `export()` reports `cache: hit|miss|bypass` when enabled.
See [cache details](docs/manifest-and-records.md#native-magento-export-cache-211).
