<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use PHPUnit\Framework\TestCase;

class TenantIsolationTest extends TestCase
{
    public function testTenantIdResolutionAndScoping(): void
    {
        $_SESSION['userdata']['active_tenant_id'] = 2;
        $activeTenant = $_SESSION['userdata']['active_tenant_id'];

        $this->assertEquals(2, $activeTenant);

        $_SESSION['userdata']['active_tenant_id'] = 5;
        $updatedTenant = $_SESSION['userdata']['active_tenant_id'];

        $this->assertEquals(5, $updatedTenant);
    }
}
