<?php

namespace App\Support;

class AppVersion {
    private static ?array $data = null;

    public static function load(): array {
        if (self::$data !== null) {
            return self::$data;
        }

        $root = dirname(__DIR__, 2);
        $file = $root . '/version.json';

        if (file_exists($file)) {
            self::$data = json_decode(file_get_contents($file), true) ?? [];
        } else {
            self::$data = [];
        }

        $version = self::$data['version'] ?? '1.0.0';
        $build   = self::$data['build'] ?? 'local';
        $commit  = self::$data['commit'] ?? self::getGitCommit($root);
        $date    = self::$data['released_at'] ?? date('Y-m-d');

        return self::$data = [
            'version'     => $version,
            'build'       => $build,
            'commit'      => $commit,
            'released_at' => $date,
            'full'        => sprintf('v%s (Build %s · %s)', $version, $build, $commit),
        ];
    }

    public static function get(string $key = 'full'): string {
        $data = self::load();
        return $data[$key] ?? '';
    }

    private static function getGitCommit(string $root = ''): string {
        if (empty($root)) {
            $root = dirname(__DIR__, 2);
        }
        $headFile = $root . '/.git/HEAD';
        if (!file_exists($headFile)) {
            return 'dev';
        }
        $head = trim(file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $refFile = $root . '/.git/' . substr($head, 5);
            if (file_exists($refFile)) {
                return substr(trim(file_get_contents($refFile)), 0, 7);
            }
        }
        return substr($head, 0, 7);
    }
}
