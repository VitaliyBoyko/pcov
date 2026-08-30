# Manifest and record architecture

## Data flow

PCOV records positive `(filename, line)` hits while PHP executes and retains
selected op-arrays. Recording does not run control-flow-graph discovery.

`pcov\export()` has two synchronous paths:

```text
no manifest
    -> complete CFG discovery
    -> full coverage record

immutable manifest
    -> validate compatibility and every retained source
    -> validate every positive hit against executable lines
       match       -> hit-only record
       uncertainty -> complete CFG discovery and full-fallback record
```

Native full uses the same forced discovery semantics as `pcov\collect()` and
overlays current positive hits. No request work leaves the PHP process.

## Identity and source validation

The environment identity is SHA-256 over canonical JSON containing:

- PHP version/build mode, integer size, and OS family;
- PCOV and coverage-record versions;
- application revision;
- dependency-lock identity;
- enabled-module/configuration identity;
- generated-code identity;
- relevant source-tree identity.

Every manifest file has a canonical absolute path, a SHA-256 source
fingerprint, and sorted executable line numbers. PCOV fingerprints selected
regular files after compilation. Validated export can reuse that SHA only when
the canonical path and strong stat identity still match. Otherwise it performs
an open/fstat/read/fstat/path-stat hash. New files, content changes, atomic
replacement, deletion, unreadable input, and detected races fail closed.

Loaded files without positive hits are validated too. A positive-hit-only
inventory would miss new but unexecuted code.

## Binary records

All fixed-width fields use big-endian byte order. The 48-byte header contains:

| Field | Purpose |
|---|---|
| Magic | Identifies `PCOVREC` coverage records |
| Format version | Selects the record layout |
| Header size | Rejects incompatible framing |
| Record type | Full coverage, manifest, or validated hits |
| Flags | Must contain supported values |
| Payload length | Bounds the exact payload |
| CRC-32 | Detects corruption and truncation |
| `PHP_VERSION_ID` | Enforces runtime compatibility |
| Compatibility version | Fences semantic changes |
| Reserved | Must be zero |

Payloads contain deployment and manifest SHA-256 identities, a deterministic
fallback reason, fixed-width counts and line numbers, length-prefixed canonical
paths, and source SHA-256 values where required. They contain no pointers,
`size_t`, Zend structs, or process-memory representations.

Readers reject unknown versions/types/flags, oversized counts and paths,
integer overflow, duplicate or unsorted files and lines, invalid coverage
values, checksum failures, truncation, and trailing bytes before iterating
untrusted lengths.

## Fallback and publication

Fallback is synchronous and complete for the current request. It is selected
for unavailable or corrupt manifests, identity mismatch, new or modified
files, source uncertainty, and positive hits absent from the manifest.

Writers create a unique temporary file with exclusive creation, write the
complete record, close it, and atomically rename it. A failed open, write,
close, or rename removes the temporary file and leaves in-memory hits available
for retry.

## Immutable merge lifecycle

```text
manifest N + matching hits + full-fallback records
    -> deterministic coverage and candidate delta
    -> authoritative inventory/full discovery
    -> atomically publish manifest N+1 at a new path
```

The merger requires exact deployment and manifest identities. Hit records are
idempotently overlaid on manifest executable lines. A request-local fallback
cannot prove deployment-wide removals or never-loaded files, so it marks the
candidate incomplete until a complete reference suite or authoritative source
inventory rebuilds the manifest. Manifest N is never changed in place and old
records are never interpreted against N+1.

The safety boundary is deployment orchestration: PCOV validates the supplied
identities and file contents, but cannot prove that caller-provided deployment
digests or an external inventory are authoritative.
