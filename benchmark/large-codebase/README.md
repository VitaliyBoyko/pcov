# Official PCOV versus large-codebase PCOV

This benchmark generates 1,500 PHP files with 40 source-line statements per
file. Each statement contains an 80-term short-circuit expression so CFG work
is substantial without inflating the executable-line inventory. Ten
interleaved rounds run ten isolated request processes against official PCOV
1.0.12 and this fork; each request loads 150 files and each complete round
covers all files. The official path calls `pcov\collect()`, serializes the PHP
array and publishes one file per request. The fork reuses one immutable
manifest and calls `pcov\export()` for validated hit-only records.

Run it from the repository root:

```sh
benchmark/large-codebase/run.sh
```

The script builds both extensions with PHP 8.5 in the same container image.
Raw JSONL and a JSON summary are written below `benchmark/results/`, which is
ignored by Git. Every accepted round must produce the same normalized coverage
SHA-256 in both modes.

The accepted PHP 8.5 cohort produced 1,500 files, 64,500 executable lines, and
normalized SHA-256
`2bf06aab6b79a763167a9c5d05947f6876a0ab5d738f78de1dfe8885bdff3dea`.
Median request-export time was 344.2 ms for official PCOV and 73.9 ms for this
fork. Median end-to-end benchmark time was 2.031 s and 2.114 s respectively;
the slower PHP final merge offset the request-path saving in this synthetic
cohort. This intentionally short benchmark is not representative of a
multi-minute browser suite. See the root README for the primary real-E2E
comparison, complete tables, and interpretation.
