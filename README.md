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

Release 2.0 supports Linux, NTS PHP 8.3, 8.4, and 8.5. It uses the extension
name `pcov`, so it replaces and cannot be loaded beside official PCOV.

After the package is indexed by Packagist, install with
[PIE](https://github.com/php/pie), the PHP extension installer:

```sh
pie install vitaliyboyko/pcov
```

Until that registry listing is available, or to build from source directly:

```sh
git clone --branch v2.0.0 https://github.com/VitaliyBoyko/pcov.git
cd pcov
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

`pcov.large_codebase` is the single feature switch and defaults to `1`. Set it
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

## Official PCOV comparison

### Representative E2E workload

The primary comparison uses a representative large PHP e-commerce application
under PHP 8.3.31 NTS. One Cypress runner executes 20 read-only E2E tests with
120 cache-busted storefront navigations through a one-child PHP-FPM pool.
Blackfire and SPX are disabled in both otherwise identical images. Three
accepted rounds rotate mode order.

Official PCOV 1.0.12 uses php-code-coverage's normal `PcovDriver`, PHP object
writer, and one atomic request file. Large-codebase PCOV 2.0 uses the identical
file filter and collection intervals, one prebuilt immutable manifest,
synchronous validation, and atomic hit records. The “finalized” column includes
the post-Cypress deterministic merge.

| Mode | Cypress wall median / p95 | Finalized wall median / p95 | FPM CPU median / p95 | Request export median / p95 | Output median |
|---|---:|---:|---:|---:|---:|
| Official PCOV 1.0.12 | 316.245 / 317.229 s | 316.766 / 317.717 s | 208.433 / 210.015 s | 519.1 / 1090.6 ms | 59.34 MB |
| Large-codebase PCOV 2.0 | 234.870 / 236.068 s | 236.125 / 237.366 s | 112.699 / 115.070 s | 1.85 / 2.95 ms | 3.10 MB |

The fork reduced Cypress wall time by **25.7%**, finalized wall time by
**25.5%**, FPM CPU by **45.9%**, pooled per-request export latency by **99.6%**,
peak cgroup memory by **21.9%**, minor faults by **20.9%**, and request-record
output by **94.8%**. Its final merge was slower (1.255 s median versus 0.489 s),
and that cost is included in finalized wall time.

All six accepted mode runs reconstructed exactly 208 files and 4,341
executable lines with normalized SHA-256
`6b1280edc9ecddd14657907a43b0cbed63bebb69dba81b280931c5c43d3c2903`.
All 435 fork exports were validated hit-only matches; no fallback was accepted.
With only three whole-scenario samples, the reported scenario p95 is the
nearest-rank observed maximum, not a high-confidence tail estimate. Detailed
application names, routes, and local infrastructure are intentionally omitted.

### Reproducible synthetic workload

The public synthetic benchmark builds both extensions in the same PHP 8.5
container. It generates 1,500 branch-heavy files, 64,500 executable lines, ten
request-isolated slices, and ten interleaved rounds:

| Mode | Total wall median / p95 | CPU median / p95 | Request export median / p95 | Output per round |
|---|---:|---:|---:|---:|
| Official PCOV 1.0.12 | 2.031 / 2.166 s | 1.992 / 2.148 s | 344.2 / 361.2 ms | 694,580 B |
| Large-codebase PCOV 2.0 | 2.114 / 2.490 s | 2.006 / 2.339 s | 73.9 / 97.5 ms | 517,240 B |

Here the fork reduced request export by **78.5%**, output by **25.5%**, and
minor faults by **7.7%**, but the run was too short to amortize validation and
the PHP finalizer: wall was **4.1% higher** and CPU **0.7% higher**. This is why
the real multi-minute workload is the primary result.

All synthetic rounds reconstructed the same 1,500 files and 64,500 lines with
SHA-256
`2bf06aab6b79a763167a9c5d05947f6876a0ab5d738f78de1dfe8885bdff3dea`.
Reproduce that cohort with:

```sh
benchmark/large-codebase/run.sh
```

Raw JSONL is written under ignored `benchmark/results/`. Both results are
workload-specific. Manifest generation is excluded from warm rows: the
optimization pays off only when an immutable manifest is reused across enough
requests, while code changes or uncertain validation synchronously fall back
to complete discovery.

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
