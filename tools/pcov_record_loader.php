<?php

const PCOV_RECORD_MAGIC = "PCOVREC\0";
const PCOV_RECORD_FORMAT_VERSION = 1;
const PCOV_RECORD_HEADER_SIZE = 48;
const PCOV_RECORD_FORMAT_COMPATIBILITY = 1;
const PCOV_RECORD_FULL = 1;
const PCOV_RECORD_MANIFEST = 2;
const PCOV_RECORD_HITS = 3;
const PCOV_RECORD_MAX_PATH_LENGTH = 1048576;
const PCOV_RECORD_MAX_FILES = 1000000;
const PCOV_RECORD_MAX_LINES = 100000000;

/**
 * Load a versioned PCOV coverage record.
 *
 * Hit records contain only positively executed lines. Manifest records contain
 * executable lines with the normal -1 value. A hit dump is not a replacement
 * for a full pcov\collect() result without a compatible manifest.
 *
 * @return array{
 *   version:int,
 *   record_type:int,
 *   flags:int,
 *   payload_length:int,
 *   checksum:int,
 *   php_version_id:int,
 *   compatibility:int,
 *   records:array<string, array<int, int>>
 * }
 */
function pcov_record_load(string $path): array
{
    $data = @file_get_contents($path);

    if ($data === false) {
        throw new RuntimeException("Cannot open PCOV coverage record: {$path}");
    }

    $size = strlen($data);
    $offset = 0;
    $read = static function (int $length) use ($data, $size, $path, &$offset): string {
        if ($length < 0 || $offset < 0 || $offset > $size || $length > $size - $offset) {
            throw new RuntimeException("Truncated PCOV coverage record: {$path}");
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    };
    $readU32 = static function () use ($read): int {
        return unpack('Nvalue', $read(4))['value'];
    };
    $readU64 = static function () use ($read, $path): int {
        $parts = unpack('Nhigh/Nlow', $read(8));
        $base = 4294967296;

        if ($parts['high'] > intdiv(PHP_INT_MAX - $parts['low'], $base)) {
            throw new RuntimeException("Oversized payload length in PCOV coverage record: {$path}");
        }

        return ($parts['high'] * $base) + $parts['low'];
    };

    if ($read(8) !== PCOV_RECORD_MAGIC) {
        throw new RuntimeException("Invalid PCOV coverage record magic: {$path}");
    }

    $version = $readU32();
    $headerSize = $readU32();
    $recordType = $readU32();
    $flags = $readU32();
    $payloadLength = $readU64();
    $checksum = $readU32();
    $phpVersionId = $readU32();
    $compatibility = $readU32();
    $reserved = $readU32();

    if ($version !== PCOV_RECORD_FORMAT_VERSION) {
        throw new RuntimeException("Unsupported PCOV record version {$version}: {$path}");
    }
    if ($headerSize !== PCOV_RECORD_HEADER_SIZE) {
        throw new RuntimeException("Unsupported PCOV coverage record header size {$headerSize}: {$path}");
    }
    if ($recordType !== PCOV_RECORD_HITS &&
        $recordType !== PCOV_RECORD_MANIFEST &&
        $recordType !== PCOV_RECORD_FULL) {
        throw new RuntimeException("Unsupported PCOV coverage record type {$recordType}: {$path}");
    }
    if ($flags !== 0 || $reserved !== 0) {
        throw new RuntimeException("Unsupported PCOV coverage record flags: {$path}");
    }
    if ($compatibility !== PCOV_RECORD_FORMAT_COMPATIBILITY) {
        throw new RuntimeException("Unsupported PCOV coverage record compatibility {$compatibility}: {$path}");
    }
    if ($phpVersionId !== PHP_VERSION_ID) {
        throw new RuntimeException(
            "Incompatible PHP version {$phpVersionId} in PCOV coverage record: {$path}"
        );
    }
    if ($payloadLength !== $size - PCOV_RECORD_HEADER_SIZE) {
        throw new RuntimeException("Invalid payload length in PCOV coverage record: {$path}");
    }

    $payload = substr($data, PCOV_RECORD_HEADER_SIZE);
    if (crc32($payload) !== $checksum) {
        throw new RuntimeException("Checksum mismatch in PCOV coverage record: {$path}");
    }

    $environmentId = bin2hex($read(32));
    $manifestId = bin2hex($read(32));
    $reason = $readU32();
        if ($recordType === PCOV_RECORD_MANIFEST && $reason !== 0) {
            throw new RuntimeException("Manifest record has an invalid reason: {$path}");
        }
        $readFile = static function (bool $status) use ($read, $readU32, $path): array {
            $fileLength = $readU32();
            if ($fileLength === 0 || $fileLength > PCOV_RECORD_MAX_PATH_LENGTH) {
                throw new RuntimeException("Invalid filename length in PCOV coverage record: {$path}");
            }
            $file = $read($fileLength);
            $fingerprintStatus = $status ? $readU32() : 0;
            $fingerprint = bin2hex($read(32));
            return [$file, $fingerprint, $fingerprintStatus];
        };
        $checkFile = static function (?string &$previousFile, string $file) use ($path): void {
            if ($previousFile !== null && strcmp($previousFile, $file) >= 0) {
                throw new RuntimeException("Unsorted or duplicate filename in PCOV coverage record: {$path}");
            }
            $previousFile = $file;
        };
        $readLines = static function (bool $values, string $lineFile) use (
            $readU32, $size, $path, $recordType, &$offset
        ): array {
            $lineCount = $readU32();
            $width = $values ? 8 : 4;
            if ($lineCount > PCOV_RECORD_MAX_LINES ||
                $lineCount > intdiv($size - $offset, $width)) {
                throw new RuntimeException("Oversized line count in PCOV coverage record: {$path}");
            }
            $lines = [];
            $previousLine = null;
            for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
                $line = $readU32();
                if ($line === 0 || ($previousLine !== null && $line <= $previousLine)) {
                    throw new RuntimeException(
                        "Unsorted or duplicate line {$line} after " .
                        ($previousLine ?? 'none') . " for {$lineFile} in PCOV coverage record: {$path}"
                    );
                }
                $previousLine = $line;
                if ($values) {
                    $raw = $readU32();
                    $value = $raw === 0xffffffff ? -1 : $raw;
                    if ($value !== -1 && $value !== 1) {
                        throw new RuntimeException("Invalid coverage value in PCOV coverage record: {$path}");
                    }
                    $lines[$line] = $value;
                } else {
                    $lines[$line] = $recordType === PCOV_RECORD_HITS ? 1 : -1;
                }
            }
            return $lines;
        };

        $records = [];
        $fingerprints = [];
        $fingerprintStatus = [];
        $validatedFiles = [];
        $previousFile = null;
        if ($recordType === PCOV_RECORD_HITS) {
            $validationCount = $readU32();
            if ($validationCount > PCOV_RECORD_MAX_FILES) {
                throw new RuntimeException("Oversized validation file count: {$path}");
            }
            for ($index = 0; $index < $validationCount; $index++) {
                [$file, $fingerprint] = $readFile(false);
                $checkFile($previousFile, $file);
                $validatedFiles[$file] = $fingerprint;
            }
            $fileCount = $readU32();
            if ($fileCount > PCOV_RECORD_MAX_FILES) {
                throw new RuntimeException("Oversized hit file count: {$path}");
            }
            $previousFile = null;
            for ($index = 0; $index < $fileCount; $index++) {
                $fileLength = $readU32();
                if ($fileLength === 0 || $fileLength > PCOV_RECORD_MAX_PATH_LENGTH) {
                    throw new RuntimeException("Invalid filename length in PCOV coverage record: {$path}");
                }
                $file = $read($fileLength);
                $checkFile($previousFile, $file);
                $records[$file] = $readLines(false, $file);
            }
        } else {
            $fileCount = $readU32();
            if ($fileCount > PCOV_RECORD_MAX_FILES) {
                throw new RuntimeException("Oversized file count in PCOV coverage record: {$path}");
            }
            for ($index = 0; $index < $fileCount; $index++) {
                [$file, $fingerprint, $fileStatus] = $readFile(
                    $recordType === PCOV_RECORD_FULL
                );
                $checkFile($previousFile, $file);
                $fingerprints[$file] = $fingerprint;
                $fingerprintStatus[$file] = $fileStatus;
                $records[$file] = $readLines(
                    $recordType === PCOV_RECORD_FULL, $file
                );
            }
        }
        if ($offset !== $size) {
            throw new RuntimeException("Trailing data in PCOV coverage record: {$path}");
        }

        return [
            'version' => $version,
            'record_type' => $recordType,
            'flags' => $flags,
            'payload_length' => $payloadLength,
            'checksum' => $checksum,
            'php_version_id' => $phpVersionId,
            'compatibility' => $compatibility,
            'environment_id' => $environmentId,
            'manifest_id' => $manifestId,
            'reason' => $reason,
            'records' => $records,
            'fingerprints' => $fingerprints,
            'fingerprint_status' => $fingerprintStatus,
            'validated_files' => $validatedFiles,
        ];
}
