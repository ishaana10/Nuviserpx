<?php

declare(strict_types=1);

namespace Tests\Unit;

use FijiPayroll;
use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../../classes/FijiPayroll.php');

class FijiPayrollTest extends TestCase
{
    public function testStandardResidentMonthlyPayroll(): void
    {
        $calc = FijiPayroll::calculate([
            'basic_salary' => 3000,
            'overtime' => 200,
            'allowances' => 100,
            'other_earnings' => 50,
            'deductions' => 50,
            'is_resident' => 1,
            'pay_frequency' => 'Monthly'
        ]);

        $this->assertEquals(3350, $calc['gross_salary']);
        $this->assertEquals(268, $calc['employee_fnpf']); // 8% of 3350
        $this->assertEquals(268, $calc['employer_fnpf']); // 8% of 3350
        $this->assertEquals(3082, $calc['taxable_income']); // 3350 - 268
        $this->assertGreaterThan(0, $calc['paye_tax']);
        $this->assertEquals(0, $calc['srt_tax']); // Under 270k annual
        $this->assertEquals(2927.24, $calc['net_salary']);
    }

    public function testHighEarnerSRTTax(): void
    {
        $calc = FijiPayroll::calculate_annual_tax_breakdown(300000);

        $this->assertGreaterThan(0, $calc['srt']);
        $this->assertEquals(57500, $calc['total']);
    }

    public function testNonResidentFlatTax(): void
    {
        $paye = FijiPayroll::calculate_paye(2000, 'Monthly', false);

        $this->assertEquals(0, $paye['srt']);
        $this->assertEquals(400, $paye['paye']); // 20% of 2000
    }

    public function testFnpfContributions(): void
    {
        $calc = FijiPayroll::calculate([
            'basic_salary' => 1000,
            'is_resident' => 1,
            'pay_frequency' => 'Monthly'
        ]);

        $this->assertEquals(80.0, $calc['employee_fnpf']);
        $this->assertEquals(80.0, $calc['employer_fnpf']);
    }

    public function testBankExports(): void
    {
        $records = [
            [
                'id' => 1,
                'payroll_run_ref' => 'RUN-001',
                'emp_name' => 'John Doe',
                'net_salary' => 2500.50,
                'bank_code' => 'bsp',
                'bank_account' => '123456789',
                'pay_date' => '2024-09-30'
            ]
        ];

        $bsp = FijiPayroll::generate_bank_export($records, 'bsp', 'RUN-001');
        $this->assertStringContainsString('John Doe', $bsp);
        $this->assertStringContainsString('2500.50', $bsp);

        $anz = FijiPayroll::generate_bank_export($records, 'anz', 'RUN-001');
        $this->assertStringContainsString('Payment Date', $anz);
        $this->assertStringContainsString('FJD', $anz);
        $this->assertStringContainsString('John Doe', $anz);
    }

    public function testFrcsTposExport(): void
    {
        $records = [
            [
                'payroll_run_ref' => 'RUN-001',
                'period_start' => '2024-09-01',
                'period_end' => '2024-09-30',
                'pay_date' => '2024-09-30',
                'tin' => '123456',
                'emp_name' => 'John Doe',
                'tax_code' => 'P',
                'is_resident' => 1,
                'gross_salary' => 3000,
                'employee_fnpf' => 240,
                'taxable_income' => 2760,
                'paye_tax' => 100,
                'deductions' => 0,
                'net_salary' => 2660,
                'employer_fnpf' => 240,
                'workcare_levy' => 30
            ]
        ];

        $tpos = FijiPayroll::generate_frcs_tpos_export($records, '100200300');
        $this->assertStringContainsString('Employer TIN', $tpos);
        $this->assertStringContainsString('100200300', $tpos);
        $this->assertStringContainsString('John Doe', $tpos);
    }
}
