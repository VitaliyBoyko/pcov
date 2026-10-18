# Repository guide for AI coding agents

## Project identity and scope

This is VitaliyBoyko's PCOV fork: a native PHP line-coverage extension with synchronous coverage export, immutable executable-line manifests, and optional native Magento GET coverage caching. It is not the upstream `krakjoe/pcov` release. Start with [README.md](README.md) for usage and [docs/manifest-and-records.md](docs/manifest-and-records.md) for the record contract.

The supported release target is Linux, non-thread-safe PHP 8.3, 8.4 and 8.5, as declared by `composer.json`, `config.m4` and CI. Legacy `cfg/701/` through `cfg/704/` and `config.w32` are retained source, not evidence of current PHP 7, Windows or ZTS support.

## Architecture and navigation

| Path | Responsibility |
| --- | --- |
| `pcov.c` | Zend execution/compile hooks, hit recording, CFG discovery, request/module lifecycle and public API registration |
| `php_pcov.h` | Extension version, shared declarations and module globals |
| `pcov_dump.c` | Native record validation, manifest-aware export, serialization and atomic publication |
| `pcov_fingerprint.c`, `pcov_fingerprint.h` | Source fingerprints and file identity checks |
| `pcov_request_cache.h` | Worker-local serialized-record cache, included by `pcov_dump.c` |
| `tools/` | PHP record reader, immutable manifest creation/merge/update CLI, legacy Magento sampling helper; read [tools/AGENTS.md](tools/AGENTS.md) before editing |
| `tests/` | PHPT regression suite and HTTP/cache test helpers |
| `benchmark/large-codebase/` | Synthetic upstream-versus-fork benchmark |
| `benchmark/magento/run.py`, `benchmark/measurements/` | Magento benchmark runner and recorded measurements |
| `.github/workflows/ci.yml`, `.github/workflows/release.yml` | PHP matrix checks and tag-driven release |

Data flow: execution records positive hits and retains selected op-arrays; export validates a manifest and source identity to emit hit-only records, or performs complete CFG discovery and emits full/full-fallback records. PHP tooling merges compatible records with a manifest to reconstruct executable lines, including uncovered lines.

## Build and validation

Run from the repository root with matching PHP CLI/development headers, `phpize`, compiler, autoconf, make and the other build dependencies listed in CI. Use an isolated PHP environment without conflicting coverage extensions.

CI's build and test commands are:

```sh
phpize
CFLAGS="-O2 -Wall -Wextra -Werror" ./configure --enable-pcov
make -j"$(nproc)"
NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test
```

CI runs these on PHP 8.3, 8.4 and 8.5 NTS. A pass on one runtime does not establish the whole matrix. Inspect skips as well as failures, especially tests requiring native export or HTTP request helpers. There is no PHPUnit or Composer test script; Composer metadata describes a PHP extension.

For changed PHP helpers use `php -l path/to/file.php`; for benchmark shell changes use `bash -n path/to/script.sh`. CI also rejects trailing whitespace. These syntax checks do not replace PHPT behavior checks.

The optional benchmark entry point is `sh benchmark/large-codebase/run.sh` (see its README for arguments). It requires Docker, Git and network access, builds upstream and fork extensions, and writes benchmark results. Do not treat old measurements as validation of a new change. Building/testing does not require `sudo make install` or changing the host's PHP configuration.

## Correctness and safety constraints

- Preserve upstream-compatible collection semantics and `-1` uncovered / positive covered line values. Recording must not acquire expensive CFG discovery work accidentally.
- Manifest uncertainty must fail closed to complete synchronous discovery: changed, new, unreadable or raced sources, incompatible identities, corrupt records and missing executable lines must not produce false hit-only success.
- Validate retained sources even when they have no positive hits. A request-local fallback cannot prove the complete application's file inventory.
- Keep manifests immutable. Publish replacements at new paths; never reinterpret old hit records against a new manifest.
- Maintain binary reader/writer parity, bounded lengths/counts, overflow checks, checksums and deterministic fallback reasons. Coordinate native changes with `tools/` and PHPT tests.
- Preserve exclusive temporary creation and atomic rename. Failed publication must retain retryable in-memory coverage.
- Keep request-allocated Zend data separate from worker-owned cache bytes. Cache reuse must compare actual current coverage and must never skip execution or become an HTTP response cache.
- Do not conflate native request caching with the older PHP sampling helper. Their semantics differ.
- Preserve C ownership and lifecycle cleanup, existing formatting and PHP compatibility. Do not modernize legacy CFG sources or broaden platform claims as part of an unrelated fix.

## Change workflow

Trace the affected API through native implementation, PHP decoder/merger and tests before editing. Extend nearby PHPT cases for changed behavior; `tests/049.phpt` covers fingerprint/checksum boundaries and `tests/050.phpt` malformed record decoding and merge deduplication.

Keep generated build products, coverage records and benchmark output out of the change. Do not change versions, create release tags or publish releases unless requested. PRs should explain the behavioral contract, tests actually run and missing runtime/platform checks. Documentation-only changes need path/command and diff review, not a fabricated runtime-test claim.
