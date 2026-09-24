<?php
declare(strict_types=1);

namespace pcov;

require_once __DIR__ . '/pcov_record_loader.php';

/**
 * Optional HTTP collection adapter. Cached requests are coverage samples,
 * not proof that later execution would cover the same lines.
 *
 * Sources/manifests must remain immutable during a request. Keep every record
 * until the suite is merged, and invalidate whenever application state changes.
 */
final class MagentoRequestCache
{
    private bool $started = false;
    private bool $finished = false;
    private bool $hit = false;
    private string $cacheStatus = 'disabled';
    private ?string $key = null;
    private ?string $manifestHash = null;
    private ?string $record = null;
    private array $server = [];
    private array $cookies = [];
    private array|false $result = false;
    private string $records;
    private string $cacheDirectory;

    public function __construct(
        string $records,
        private readonly ?string $manifest,
        private readonly string $deploymentId,
        private readonly string $suiteId,
        private readonly int $ttl = 300,
        private readonly int $type = 0,
        private readonly array $filter = [],
        private readonly array $excludedPaths = [
            '/checkout', '/customer/section/load', '/admin', '/rest', '/graphql',
        ],
    ) {
        if ($suiteId === '' || !preg_match('/\A[0-9a-f]{64}\z/D', $deploymentId)
            || $ttl < 1 || !in_array($type, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Invalid PCOV request-cache configuration');
        }
        $directory = realpath($records);
        if ($directory === false || !is_dir($directory)) {
            throw new \InvalidArgumentException('The PCOV records directory must already exist');
        }
        foreach (array_merge($filter, $excludedPaths) as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new \InvalidArgumentException('Invalid PCOV filter or excluded path');
            }
        }
        $this->records = $directory;
        $this->cacheDirectory = $directory . '/.pcov-get-cache-' . hash('sha256', $suiteId);
    }

    /** Return true when recording, false when reusing a previous record. */
    public function start(?array $server = null, ?array $cookies = null): bool
    {
        if ($this->started) {
            throw new \LogicException('PCOV request collection has already started');
        }
        if (!function_exists('pcov\\export') || !enabled() || !ini_get('pcov.large_codebase')) {
            throw new \RuntimeException('MagentoRequestCache requires enabled large-codebase PCOV');
        }
        $this->started = true;
        $this->server = $server ?? $_SERVER;
        $this->cookies = $cookies ?? $_COOKIE;
        if (ini_get('pcov.request_magento_cache')) {
            $this->cacheStatus = 'bypass';
            if ($this->eligibleRequest()) {
                try {
                    $this->key = $this->requestKey();
                    if ($this->key !== null) {
                        $this->cacheStatus = 'miss';
                        $this->hit = $this->lookup();
                    }
                } catch (\Throwable) {
                    // Uncertain cache state must never suppress collection.
                    $this->cacheStatus = 'cache-error';
                }
            }
        }
        if ($this->hit) {
            $this->cacheStatus = 'hit';
            return false;
        }
        start();
        return true;
    }

    /**
     * Export once, or return the retained record on a cache hit. Optional HTTP
     * snapshots support frameworks that own their response object and tests.
     * @return array<string,mixed>|false
     */
    public function finish(?int $status = null, ?array $headers = null): array|false
    {
        if (!$this->started) {
            throw new \LogicException('Call MagentoRequestCache::start() before finish()');
        }
        if ($this->finished) {
            return $this->result;
        }
        $this->finished = true;
        if ($this->hit) {
            return $this->result = [
                'mode' => 'request-cache-hit', 'reason' => 'matching-get',
                'record' => $this->record, 'cache' => 'hit',
            ];
        }
        stop();
        $this->record = $this->records . '/request-' . bin2hex(random_bytes(16)) . '.pcov';
        $result = export($this->record, $this->manifest, $this->deploymentId, $this->type, $this->filter);
        if ($result === false) {
            return false;
        }
        if ($this->key !== null && $result['mode'] === 'hit-only') {
            try {
                $response = $this->responsePolicy($status ?? (http_response_code() ?: 200), $headers ?? headers_list());
                if ($response !== null && $this->manifestHash === $this->fileHash($this->manifest)) {
                    $entry = [
                        'version' => 1, 'created' => time(), 'expires' => time() + $response['ttl'],
                        'record' => basename($this->record), 'record_hash' => $this->fileHash($this->record),
                        'vary' => $response['vary'], 'vary_hash' => $this->varyHash($response['vary']),
                    ];
                    if ($entry['record_hash'] !== null) {
                        $this->publish($this->cacheDirectory . '/' . $this->key . '.json', json_encode($entry, JSON_THROW_ON_ERROR));
                    }
                } else {
                    $this->cacheStatus = 'response-bypass';
                }
            } catch (\Throwable) {
                $this->cacheStatus = 'cache-error';
            }
        }
        return $this->result = $result + ['record' => $this->record, 'cache' => $this->cacheStatus];
    }

    /** Invalidate this suite across workers, including exports still in flight. */
    public function invalidate(): void
    {
        $this->publish($this->cacheDirectory . '/generation', bin2hex(random_bytes(16)));
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    private function eligibleRequest(): bool
    {
        if (($this->server['REQUEST_METHOD'] ?? '') !== 'GET'
            || !is_string($this->server['HTTP_HOST'] ?? null)
            || $this->server['HTTP_HOST'] === ''
            || !is_string($this->server['REQUEST_URI'] ?? null)
            || !str_starts_with($this->server['REQUEST_URI'], '/')
            || isset($this->server['HTTP_AUTHORIZATION']) || isset($this->server['PHP_AUTH_USER'])
            || ($this->server['HTTP_X_PASS'] ?? '') === '1'
            || (int) ($this->server['CONTENT_LENGTH'] ?? 0) > 0
            || preg_match('/(?:no-cache|no-store|max-age\s*=\s*0)/i', (string) ($this->server['HTTP_CACHE_CONTROL'] ?? ''))
            || stripos((string) ($this->server['HTTP_PRAGMA'] ?? ''), 'no-cache') !== false) {
            return false;
        }
        $path = parse_url($this->server['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        $path = rawurldecode($path);
        if (str_contains($path, '%') || str_contains($path, "\0")) {
            return false;
        }
        $path = preg_replace('#^/index\.php(?=/|$)#', '', $path);
        foreach ($this->excludedPaths as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return false;
            }
        }
        foreach (['X-Magento-Vary', 'store'] as $cookie) {
            if (isset($this->cookies[$cookie]) && !is_string($this->cookies[$cookie])) {
                return false;
            }
        }
        return true;
    }

    private function requestKey(): ?string
    {
        $this->manifestHash = $this->fileHash($this->manifest);
        if ($this->manifestHash === null) {
            return null;
        }
        clearstatcache();
        $generationPath = $this->cacheDirectory . '/generation';
        $generation = is_file($generationPath) ? @file_get_contents($generationPath) : '0';
        if (!is_string($generation) || ($generation !== '0' && !preg_match('/\A[0-9a-f]{32}\z/D', $generation))) {
            return null;
        }
        $headers = [];
        foreach ($this->server as $name => $value) {
            if (str_starts_with((string) $name, 'HTTP_') && $name !== 'HTTP_COOKIE') {
                if (!is_string($value)) {
                    return null;
                }
                $headers[$name] = $value;
            }
        }
        ksort($headers, SORT_STRING);
        $filter = $this->filter;
        sort($filter, SORT_STRING);
        return hash('sha256', json_encode([
            'pcov-get-v1', PHP_VERSION_ID, constant('pcov\\version'),
            $this->deploymentId, $this->suiteId, $generation, $this->manifestHash,
            ini_get('pcov.directory'), ini_get('pcov.exclude'), $this->type, $filter,
            $this->server['REQUEST_URI'], $this->server['HTTPS'] ?? '',
            $this->server['SERVER_PORT'] ?? '', $headers,
            $this->cookies['X-Magento-Vary'] ?? '', $this->cookies['store'] ?? '',
        ], JSON_THROW_ON_ERROR));
    }

    private function lookup(): bool
    {
        $path = $this->cacheDirectory . '/' . $this->key . '.json';
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) > 16384) {
            return false;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return false;
        }
        $entry = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($entry) || ($entry['version'] ?? null) !== 1
            || !is_int($entry['created'] ?? null) || !is_int($entry['expires'] ?? null)
            || $entry['created'] > time() || min($entry['expires'], $entry['created'] + $this->ttl) <= time()
            || !is_string($entry['record'] ?? null)
            || !preg_match('/\Arequest-[0-9a-f]{32}\.pcov\z/D', $entry['record'])
            || !is_array($entry['vary'] ?? null) || !is_string($entry['vary_hash'] ?? null)
            || !hash_equals($entry['vary_hash'], $this->varyHash($entry['vary']))) {
            return false;
        }
        $record = $this->records . '/' . $entry['record'];
        $hash = $this->fileHash($record);
        if ($hash === null || !is_string($entry['record_hash'] ?? null)
            || !hash_equals($entry['record_hash'], $hash)) {
            return false;
        }
        // Validate all manifest sources before any decision to skip recording.
        // Do not substitute mtime/size: equal-size edits can preserve mtime.
        $manifest = \pcov_record_load($this->manifest);
        if ($manifest['record_type'] !== PCOV_RECORD_MANIFEST
            || $manifest['environment_id'] !== $this->deploymentId) {
            return false;
        }
        foreach ($manifest['fingerprints'] as $file => $fingerprint) {
            $current = $this->fileHash($file);
            if ($current === null || !hash_equals($fingerprint, $current)) {
                $this->cacheStatus = 'fingerprint-mismatch';
                return false;
            }
        }
        if ($this->manifestHash !== $this->fileHash($this->manifest)) {
            return false;
        }
        $this->record = $record;
        return true;
    }

    private function responsePolicy(int $status, array $headers): ?array
    {
        $error = error_get_last();
        if ($status !== 200 || ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true))) {
            return null;
        }
        $map = [];
        foreach ($headers as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                return null;
            }
            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if ($name === 'set-cookie' && preg_match('/\A(X-Magento-Vary|store)=([^;]*)/', $value, $match)
                && rawurldecode($match[2]) !== ($this->cookies[$match[1]] ?? '')) {
                return null;
            }
            $map[$name] = isset($map[$name]) ? $map[$name] . ', ' . $value : $value;
        }
        $control = ($map['cache-control'] ?? '') . ',' . ($map['surrogate-control'] ?? '');
        if (preg_match('/(?:^|,)\s*(?:private|no-cache|no-store)(?:\s|=|,|$)/i', $control)) {
            return null;
        }
        $ttl = null;
        foreach ([[$map['surrogate-control'] ?? '', 'max-age'], [$map['cache-control'] ?? '', 's-maxage'], [$map['cache-control'] ?? '', 'max-age']] as [$value, $directive]) {
            if (preg_match('/(?:^|,)\s*' . $directive . '\s*=\s*"?([0-9]+)"?(?:\s|,|$)/i', $value, $match)) {
                $ttl = min($this->ttl, (int) $match[1]);
                break;
            }
        }
        if ($ttl === null && isset($map['expires'])) {
            $expires = strtotime($map['expires']);
            $ttl = $expires === false ? 0 : min($this->ttl, $expires - time());
        }
        $ttl = ($ttl ?? 0) - max(0, (int) ($map['age'] ?? 0));
        $vary = array_values(array_filter(array_map('trim', explode(',', strtolower($map['vary'] ?? '')))));
        if ($ttl <= 0 || in_array('*', $vary, true)) {
            return null;
        }
        foreach ($vary as $name) {
            if (!preg_match('/\A[a-z0-9!#$%&\x27+.^_`|~-]+\z/D', $name)) {
                return null;
            }
        }
        sort($vary, SORT_STRING);
        return ['ttl' => $ttl, 'vary' => $vary];
    }

    private function varyHash(array $vary): string
    {
        $values = [];
        foreach ($vary as $name) {
            $values[$name] = match ($name) {
                'x-magento-vary' => $this->cookies['X-Magento-Vary'] ?? '',
                'x-store-cookie' => $this->cookies['store'] ?? '',
                'https' => $this->server['HTTPS'] ?? '',
                default => $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '',
            };
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function fileHash(?string $path): ?string
    {
        if ($path === null || str_contains($path, '://')) {
            return null;
        }
        clearstatcache(true, $path);
        $before = @stat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
            return null;
        }
        $hash = @hash_file('sha256', $path);
        clearstatcache(true, $path);
        $after = @stat($path);
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if ($after === false || $before[$field] !== $after[$field]) {
                return null;
            }
        }
        return is_string($hash) ? $hash : null;
    }

    private function publish(string $path, string $contents): void
    {
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0700, true)
            && !is_dir($this->cacheDirectory)) {
            throw new \RuntimeException('Cannot create PCOV request-cache directory');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create PCOV request-cache entry');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write PCOV request-cache entry');
            }
            fclose($handle);
            $handle = false;
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish PCOV request-cache entry');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
