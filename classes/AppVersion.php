<?php

if (file_exists(__DIR__ . '/../src/Support/AppVersion.php')) {
    require_once __DIR__ . '/../src/Support/AppVersion.php';
}

use App\Support\AppVersion as BaseAppVersion;

if (!class_exists('AppVersion')) {
    class AppVersion {
        public static function load(): array {
            if (class_exists('App\Support\AppVersion')) {
                return BaseAppVersion::load();
            }
            return [
                'version' => '1.0.0',
                'build' => 'local',
                'commit' => 'dev',
                'released_at' => date('Y-m-d'),
                'full' => 'v1.0.0 (Build local · dev)'
            ];
        }

        public static function get(string $key = 'full'): string {
            $data = self::load();
            return $data[$key] ?? '';
        }
    }
}
