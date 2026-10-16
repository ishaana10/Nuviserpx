<?php

use App\Support\AppVersion as BaseAppVersion;

if (!class_exists('AppVersion')) {
    class AppVersion {
        public static function load(): array {
            return BaseAppVersion::load();
        }

        public static function get(string $key = 'full'): string {
            return BaseAppVersion::get($key);
        }
    }
}
