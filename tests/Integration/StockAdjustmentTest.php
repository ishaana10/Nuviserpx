<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class StockAdjustmentTest extends TestCase
{
    public function testInventoryMovementCalculations(): void
    {
        $initialStock = 100.0;
        $orderDeduction = 15.0;
        $newStock = $initialStock - $orderDeduction;

        $this->assertEquals(85.0, $newStock);

        $stockAdjustmentIn = 25.0;
        $finalStock = $newStock + $stockAdjustmentIn;

        $this->assertEquals(110.0, $finalStock);
    }
}
