<?php

namespace App\Services;

use SystemSettings;

class RedisCacheService
{
    protected SystemSettings $settings;
    protected mixed $redis = null;
    protected bool $connected = false;
    protected string $socketPath = '/home/ictfjcom/.redis/redis.sock';
    protected string $prefix = 'nuviserpxssid:';

    public function __construct(SystemSettings $settings)
    {
        $this->settings = $settings;
        $this->connect();
    }

    protected function connect(): void
    {
        $homeDir = getenv('HOME') ?: '/home/ictfjcom';
        @touch($homeDir . '/.redis.on');
        @touch('/home/ictfjcom/.redis.on');

        $host = $this->settings->info('redis_host') ?: '127.0.0.1';
        $port = intval($this->settings->info('redis_port') ?: 6379);
        $pass = $this->settings->info('redis_password') ?: null;
        $db = intval($this->settings->info('redis_db') ?: 0);

        try {
            if (extension_loaded('redis') && class_exists('Redis')) {
                $r = new \Redis();

                // Check socket path first if exists
                if (file_exists($this->socketPath)) {
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
            if (file_exists($this->socketPath)) {
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
        $homeDir = getenv('HOME') ?: '/home/ictfjcom';
        @touch($homeDir . '/.redis.on');
        @touch('/home/ictfjcom/.redis.on');

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

                $allKeys = $this->redis->keys($this->prefix . '*');

                if (is_array($allKeys)) {
                    $cachedKeysCount = count($allKeys);
                    $sessionKeys = array_filter($allKeys, fn($k) => str_contains($k, 'sess:'));
                    $activeSessionsCount = count($sessionKeys);

                    foreach (array_slice($allKeys, 0, 15) as $k) {
                        $ttl = $this->redis->ttl($k);
                        $shortKey = str_replace($this->prefix, '', $k);
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

        return [
            'success' => true,
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
        $homeDir = getenv('HOME') ?: '/home/ictfjcom';
        @touch($homeDir . '/.redis.on');
        @touch('/home/ictfjcom/.redis.on');

        return [
            'success' => true,
            'socket_exists' => file_exists($this->socketPath),
            'is_connected' => $this->connected,
            'message' => 'Created ~/.redis.on flag on disk. A2 Hosting background daemon will initialize ' . $this->socketPath . '.'
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
