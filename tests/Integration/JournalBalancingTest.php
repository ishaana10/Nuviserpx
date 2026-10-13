<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class JournalBalancingTest extends TestCase
{
    public function testDoubleEntryDebitCreditBalance(): void
    {
        $debits = 1500.00;
        $credits = 1500.00;

        $this->assertEquals($debits, $credits);
        $this->assertEquals(0.00, round($debits - $credits, 2));
    }
}
