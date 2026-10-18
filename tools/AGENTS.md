# PHP record and manifest tools

This guidance supplements the root [AGENTS.md](../AGENTS.md) for `tools/`.

## Navigation

- `pcov_record_loader.php`: strict binary decoder and record constants.
- `pcov_manifest_tools.php`: manifest encoding, bootstrap, merge, source inventory and atomic publication.
- `pcov_manifest.php`: CLI dispatch for `create`, `merge`, `update` and `inspect`; argument signatures are printed by the script.
- `pcov_request_magento_cache.php`: legacy PHP sampling helper. It is distinct from the native cache in `../pcov_request_cache.h`.

## Contracts to preserve

Read [manifest-and-records.md](../docs/manifest-and-records.md) and the corresponding native code in `../pcov_dump.c` before changing framing. The current format uses a 48-byte header, big-endian fields, SHA-256 identities and CRC-32. Never encode platform-dependent pointers or native structures.

Validate sizes before slicing/iterating; reject unsupported framing, duplicate or unsorted entries, invalid line values, overflow, truncation and trailing bytes. Keep PHP integer-width assumptions explicit. Treat record contents as input data, not executable PHP.

Merging requires matching environment and manifest identities. Duplicate hit records must remain idempotent. Full fallback makes the candidate incomplete until an authoritative inventory/full discovery resolves it; do not remove the CLI's incomplete-coverage refusal.

The `merge` CLI writes a PHP coverage array, while CLI status is JSON on stdout and errors go to stderr. Preserve these interfaces and exit behavior. Use temporary fixtures and new output paths; never overwrite an input manifest.

## Validation

Run `php -l` on changed helpers, then the root PHPT suite with the freshly built extension. Add behavioral cases in `../tests/`, especially malformed records, mixed full/hit merges, source changes and atomic-write failures. Lint alone does not validate binary compatibility. Avoid adding a separate PHPUnit/Composer workflow.
