<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PHPUnit bridge over the standalone test scripts in tests/. Each script is
 * one PHPUnit test case, so the whole suite gets PHPUnit's reporting and CI
 * integration without rewriting the scripts. New tests can be written either
 * way; proper PHPUnit tests placed in tests/phpunit/ run natively.
 */
final class LegacySuiteTest extends TestCase
{
    public static function scripts(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/test_*.php') as $f) {
            $out[basename($f, '.php')] = [$f];
        }
        return $out;
    }

    #[DataProvider('scripts')]
    public function testScriptPasses(string $script): void
    {
        $output = [];
        $code = 1;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, basename($script) . " failed:\n" . implode("\n", $output));
    }
}
