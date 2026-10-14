<?php

namespace App\Services;

use App\Core\Container;
use SystemSettings;

class RedisCacheService
{
    protected SystemSettings $settings;
    protected mixed $redis = null;
    protected bool $connected = false;

    public function __construct(SystemSettings $settings)
    {
        $this->settings = $settings;
        $this->connect();
    }

    protected function connect(): void
    {
        $enabled = $this->settings->info('redis_enabled');
        if ($enabled === '0') {
            return;
        }

        $host = $this->settings->info('redis_host') ?: '127.0.0.1';
        $port = intval($this->settings->info('redis_port') ?: 6379);
        $pass = $this->settings->info('redis_password') ?: null;
        $db = intval($this->settings->info('redis_db') ?: 0);

        try {
            if (class_exists('Redis')) {
                $r = new \Redis();
                if (@$r->connect($host, $port, 1.5)) {
                    if (!empty($pass)) {
                        $r->auth($pass);
                    }
                    if ($db > 0) {
                        $r->select($db);
                    }
                    $this->redis = $r;
                    $this->connected = true;
                }
            } else {
                // Fallback to socket / stream connection
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
            }
        } catch (\Throwable $e) {
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
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
