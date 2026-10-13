<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use PHPUnit\Framework\TestCase;

class DummyDep {}

class DummyService
{
    public function __construct(public DummyDep $dep) {}
}

class ContainerTest extends TestCase
{
    public function testContainerSingletonAndAutowiring(): void
    {
        $container = Container::getInstance();
        $dep = new DummyDep();
        $container->singleton(DummyDep::class, $dep);

        $this->assertTrue($container->has(DummyService::class));
        $service = $container->make(DummyService::class);

        $this->assertInstanceOf(DummyService::class, $service);
        $this->assertSame($dep, $service->dep);
    }
}
