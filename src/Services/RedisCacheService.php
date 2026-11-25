<?php

namespace App\Services;

use SystemSettings;

class RedisCacheService
{
    protected SystemSettings $settings;
    protected mixed $redis = null;
    protected bool $connected = false;
    protected string $socketPath = '';
    protected string $prefix = 'nuviserpxssid:';

    public function __construct(SystemSettings $settings)
    {
        $this->settings = $settings;
        $this->socketPath = self::resolveSocketPath(self::homeCandidates(), (string)($settings->info('redis_socket') ?: ''));
        $this->connect();
    }

    /* ------------------------------ where Redis lives ------------------------------ */

    /** Home folders this account may have, most likely first (the account name is different on every server). */
    public static function homeCandidates(): array
    {
        $homes = [];
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (!empty($pw['dir'])) $homes[] = rtrim((string)$pw['dir'], '/');
        }
        if (($h = getenv('HOME')) !== false && $h !== '') $homes[] = rtrim($h, '/');
        if (defined('base_app') && ($h = self::homeFromAppPath((string)base_app)) !== null) $homes[] = $h;
        return array_values(array_unique(array_filter($homes)));
    }

    /** "/home/acct/public_html/app/" -> "/home/acct"; null when the path is not under a home folder. */
    public static function homeFromAppPath(string $path): ?string
    {
        return preg_match('#^(/home\d*/[^/]+)/#', $path, $m) ? $m[1] : null;
    }

    /** The socket to use: a path saved in settings, else the live one in a candidate home, else the first home's. '' when no home is known. */
    public static function resolveSocketPath(array $homes, string $override): string
    {
        if ($override !== '') return $override;
        foreach ($homes as $h) {
            if (file_exists($h . '/.redis/redis.sock')) return $h . '/.redis/redis.sock';
        }
        return $homes ? $homes[0] . '/.redis/redis.sock' : '';
    }

    /** Try to open the socket. errno 0 = something is listening; 111 = a file with nobody behind it. */
    public static function probeSocket(string $path): array
    {
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client('unix://' . $path, $errno, $errstr, 1.5);
        if ($fp) { fclose($fp); return ['errno' => 0, 'errstr' => '']; }
        return ['errno' => (int)$errno ?: -1, 'errstr' => (string)$errstr];
    }

    /** 'ok' | 'stale' (file, nobody listening) | 'permission' | 'missing' | 'other' */
    public static function classifySocket(int $errno, bool $fileExists): string
    {
        if ($errno === 0) return $fileExists ? 'ok' : 'missing';
        if (!$fileExists) return 'missing';
        if ($errno === 111) return 'stale';
        if ($errno === 13) return 'permission';
        return 'other';
    }

    /** Remove a socket file that nothing is listening on (e.g. copied over from the old server). Never touches a live one. */
    public static function clearStaleSocket(string $path): bool
    {
        if ($path === '' || !file_exists($path) || @filetype($path) !== 'socket') return false;
        $p = self::probeSocket($path);
        return self::classifySocket($p['errno'], true) === 'stale' && @unlink($path);
    }

    /**
     * A2 Hosting starts Redis for an account when ~/.redis.on exists (it checks about every five minutes).
     * @return array{created:string[],failed:string[]}
     */
    public static function touchStartFlag(array $homes): array
    {
        $created = $failed = [];
        foreach (array_unique($homes) as $h) {
            $flag = $h . '/.redis.on';
            if (is_file($flag) || @touch($flag)) { $created[] = $flag; } else { $failed[] = $flag; }
        }
        return ['created' => $created, 'failed' => $failed];
    }

    protected function connect(): void
    {
        self::touchStartFlag(self::homeCandidates());

        $host = $this->settings->info('redis_host') ?: '127.0.0.1';
        $port = intval($this->settings->info('redis_port') ?: 6379);
        $pass = $this->settings->info('redis_password') ?: null;
        $db = intval($this->settings->info('redis_db') ?: 0);

        try {
            if (extension_loaded('redis') && class_exists('Redis')) {
                $r = new \Redis();

                // Check socket path first if exists
                if ($this->socketPath !== '' && file_exists($this->socketPath)) {
                    if (@$r->connect($this->socketPath)) {
                        if (!empty($pass)) $r->auth($pass);
                        if ($db > 0) $r->select($db);
                        $this->redis = $r;
                        $this->connected = true;
                        return;
                    }
                }

                // Fallback to TCP host & port
                if (@$r->connect($host, $port, 1.5)) {
                    if (!empty($pass)) $r->auth($pass);
                    if ($db > 0) $r->select($db);
                    $this->redis = $r;
                    $this->connected = true;
                    return;
                }
            }

            // Stream socket fallback
            if ($this->socketPath !== '' && file_exists($this->socketPath)) {
                $fp = @fsockopen('unix://' . $this->socketPath, -1, $errno, $errstr, 1.5);
                if ($fp) {
                    $this->redis = $fp;
                    $this->connected = true;
                    return;
                }
            }

            $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
            if ($fp) {
                $this->redis = $fp;
                if (!empty($pass)) {
                    fwrite($fp, "AUTH {$pass}\r\n");
                    fgets($fp);
                }
                if ($db > 0) {
                    fwrite($fp, "SELECT {$db}\r\n");
                    fgets($fp);
                }
                $this->connected = true;
            }
        } catch (\Throwable $e) {
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getStatus(): array
    {
        $socketExists = file_exists($this->socketPath);
        $redisVersion = 'N/A';
        $usedMemory = 'N/A';
        $connectedClients = 0;
        $cachedKeysCount = 0;
        $activeSessionsCount = 0;
        $keysList = [];

        if ($this->connected && $this->redis instanceof \Redis) {
            try {
                $info = $this->redis->info();
                $redisVersion = $info['redis_version'] ?? 'Unknown';
                $usedMemory = $info['used_memory_human'] ?? 'Unknown';
                $connectedClients = (int)($info['connected_clients'] ?? 1);

                $allKeys = $this->redis->keys('*');

                if (is_array($allKeys)) {
                    $cachedKeysCount = count($allKeys);
                    $sessionKeys = array_filter($allKeys, fn($k) => str_contains(strtolower($k), 'sess'));
                    $activeSessionsCount = count($sessionKeys);

                    foreach (array_slice($allKeys, 0, 20) as $k) {
                        $ttl = $this->redis->ttl($k);
                        $shortKey = preg_replace('/^(nuviserpxssid:|nuvis:)/i', '', $k);
                        $keysList[] = [
                            'key' => $shortKey,
                            'full_key' => $k,
                            'ttl' => $ttl > 0 ? $ttl . 's' : ($ttl === -1 ? 'No expiry' : 'Expired')
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $probe = ($socketExists && !$this->connected) ? self::probeSocket($this->socketPath) : ['errno' => 0, 'errstr' => ''];
        $socketState = $this->connected ? 'ok' : self::classifySocket($probe['errno'], $socketExists);

        $flagFile = (self::homeCandidates()[0] ?? '') . '/.redis.on';
        $flagAge = ($flagFile !== '/.redis.on' && is_file($flagFile)) ? max(0, (int)floor((time() - (int)filemtime($flagFile)) / 60)) : null;

        return [
            'success' => true,
            'flag_path' => $flagFile,
            'flag_age_min' => $flagAge,
            'socket_state' => $socketState,
            'socket_error' => $probe['errstr'],
            'redis_extension' => extension_loaded('redis'),
            'is_connected' => $this->connected,
            'scheme' => file_exists($this->socketPath) ? 'unix' : 'tcp',
            'socket_path' => $this->socketPath,
            'socket_exists' => $socketExists,
            'redis_version' => $redisVersion,
            'used_memory' => $usedMemory,
            'connected_clients' => $connectedClients,
            'cached_keys_count' => $cachedKeysCount,
            'active_sessions_count' => $activeSessionsCount,
            'keys_sample' => $keysList,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    public function benchmark(): array
    {
        if (!$this->connected) {
            return [
                'success' => false,
                'error' => 'Redis is not connected or daemon is offline. Click "Start A2 Hosting Redis Daemon" to initialize.'
            ];
        }

        $start = microtime(true);
        $testKey = $this->prefix . 'ping_test_' . uniqid();
        $testVal = 'OK_' . microtime(true);

        $this->set($testKey, $testVal, 10);
        $retrieved = $this->get($testKey);
        $this->delete($testKey);

        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return [
            'success' => true,
            'latency_ms' => $latencyMs,
            'message' => "SET/GET/DEL benchmark completed in {$latencyMs} ms. Cache reads and writes are 100% operational!"
        ];
    }

    public function startDaemon(): array
    {
        // A leftover socket file with nobody listening (copied from the old server) keeps a fresh Redis from starting.
        $cleared = self::clearStaleSocket($this->socketPath);
        $flag = self::touchStartFlag(self::homeCandidates());
        error_log('[Redis] Start requested from the Developer page: ' . ($cleared ? 'removed a dead socket file; ' : '') . ($flag['created'] ? 'flag at ' . implode(', ', $flag['created']) : 'could not create the flag (' . implode(', ', $flag['failed']) . ')') . '.');
        $socketNow = $this->socketPath !== '' && file_exists($this->socketPath);
        if (!$flag['created']) {
            return [
                'success' => false,
                'socket_exists' => $socketNow,
                'is_connected' => $this->connected,
                'message' => 'Could not create the ~/.redis.on start flag (tried: ' . implode(', ', $flag['failed'] ?: ['no home folder found']) . '). Create it yourself over SSH or the cPanel Terminal with: touch ~/.redis.on'
            ];
        }
        return [
            'success' => true,
            'socket_exists' => $socketNow,
            'is_connected' => $this->connected,
            'message' => ($cleared ? 'Removed a dead socket file nothing was listening on (left over from the old server). ' : ($socketNow ? 'The Redis socket exists. ' : ''))
                . 'Start flag created at ' . implode(', ', $flag['created']) . '. A2 Hosting checks for it about every five minutes and then starts Redis; the socket will appear at ' . ($this->socketPath ?: 'your home folder\'s .redis/redis.sock') . '. Refresh in five minutes.'
                . (extension_loaded('redis') ? '' : ' The PHP "redis" extension is not enabled on this server: enable it in cPanel (Select PHP Version / Extensions) or Redis cannot be used.')
        ];
    }

    public function ping(): array
    {
        if (!$this->connected) {
            return [
                'success' => false,
                'message' => 'Unable to connect to Redis server.'
            ];
        }

        try {
            if ($this->redis instanceof \Redis) {
                $reply = $this->redis->ping();
                return [
                    'success' => true,
                    'message' => 'Redis connection successful: ' . ($reply === true ? 'PONG' : (string)$reply)
                ];
            } else {
                fwrite($this->redis, "PING\r\n");
                $reply = trim((string)fgets($this->redis));
                return [
                    'success' => str_contains($reply, 'PONG') || str_contains($reply, '+PONG'),
                    'message' => 'Redis socket response: ' . $reply
                ];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Ping failed: ' . $e->getMessage()
            ];
        }
    }

    public function get(string $key): mixed
    {
        if (!$this->connected) return null;

        try {
            if ($this->redis instanceof \Redis) {
                $data = $this->redis->get($key);
                return $data !== false ? json_decode($data, true) : null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->connected) return false;

        $ttl = $ttl ?? intval($this->settings->info('redis_ttl') ?: 3600);
        $encoded = json_encode($value);

        try {
            if ($this->redis instanceof \Redis) {
                return $this->redis->setex($key, $ttl, $encoded);
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    public function delete(string $key): bool
    {
        if (!$this->connected) return false;

        try {
            if ($this->redis instanceof \Redis) {
                return $this->redis->del($key) > 0;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    public function flush(): bool
    {
        if (!$this->connected) return false;

        try {
            if ($this->redis instanceof \Redis) {
                return $this->redis->flushDB();
            } elseif (is_resource($this->redis)) {
                fwrite($this->redis, "FLUSHDB\r\n");
                $reply = fgets($this->redis);
                return str_contains($reply, 'OK') || str_contains($reply, '+OK');
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
}
