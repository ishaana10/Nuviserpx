<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A customer's unpaid-invoices statement as a PDF.
 *
 * The document is built and returned as a string in memory. It is never written to disk by this class or by any
 * caller: it is streamed to the browser to view, or attached to an email, and then it is gone. Do not add a file
 * output here (tests/test_no_pdf_storage.php fails the build if anything in the app writes a PDF).
 */
final class StatementPdf
{
    private static function t(string $s): string
    {
        return PdfDoc::t($s);
    }

    private static function money(float $v): string
    {
        return number_format($v, 2);
    }

    /** Download/attachment name: "Statement-Acme-Ltd-2026-11-14.pdf". Letters, digits and dashes only. */
    public static function filename(string $customerName, string $asOf): string
    {
        $slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', self::t($customerName)), '-');
        return 'Statement-' . ($slug !== '' ? substr($slug, 0, 40) : 'customer') . '-' . $asOf . '.pdf';
    }

    /**
     * @param array $c      a customer row from LedgerService::agedReceivables (name, code, email, total, invoices[], buckets)
     * @param array $brand  tenant_branding(): name, email, phone, address, logo
     */
    public static function render(array $c, string $asOf, array $brand, string $currency, string $note = ''): string
    {
        $pdf = new PdfDoc('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages('{nb}');
        $pdf->SetTitle(self::t('Statement - ' . (string)$c['name']));
        $pdf->footerText = self::t('Generated ' . date('j M Y') . ' - ' . (string)($brand['name'] ?? ''));
        $pdf->AddPage();

        $pdf->letterhead($brand);

        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 9, 'Statement of Unpaid Invoices', 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(60, 70, 85);
        $pdf->Cell(0, 5.5, 'As at ' . date('j M Y', strtotime($asOf)), 0, 1);
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 6, self::t((string)$c['name']) . (($c['code'] ?? '') !== '' ? '  (' . self::t((string)$c['code']) . ')' : ''), 0, 1);
        $pdf->Ln(3);

        $w = [38, 28, 28, 30, 34, 22];
        $head = function () use ($pdf, $w): void {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->SetTextColor(51, 65, 85);
            foreach (['Invoice', 'Date', 'Due', 'Invoice total', 'Balance due', 'Age'] as $i => $h) {
                $pdf->Cell($w[$i], 7, $h, 'B', 0, $i >= 3 ? 'R' : 'L', true);
            }
            $pdf->Ln();
        };
        $head();
        $buckets = \App\Services\LedgerService::AGING_BUCKETS;
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($c['invoices'] as $i) {
            if ($pdf->GetY() + 8 > $pdf->GetPageHeight() - 22) {
                $pdf->AddPage();
                $head();
                $pdf->SetFont('Helvetica', '', 9);
            }
            $late = $i['bucket'] !== 'current';
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell($w[0], 6.5, self::t('#' . (string)$i['invoice_no']), 'B');
            $pdf->Cell($w[1], 6.5, date('d M Y', strtotime((string)$i['invoice_date'])), 'B');
            $pdf->Cell($w[2], 6.5, !empty($i['due_date']) ? date('d M Y', strtotime((string)$i['due_date'])) : '-', 'B');
            $pdf->Cell($w[3], 6.5, self::money((float)$i['total']), 'B', 0, 'R');
            $late ? $pdf->SetTextColor(185, 28, 28) : $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell($w[4], 6.5, self::money((float)$i['balance']), 'B', 0, 'R');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($w[5], 6.5, self::t((string)($buckets[$i['bucket']] ?? '')), 'B', 1, 'R');
        }

        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 8, self::t('Total outstanding ' . $currency), 0, 0, 'R');
        $pdf->Cell($w[4], 8, self::money((float)$c['total']), 0, 1, 'R');

        $pdf->Ln(3);
        $any = false;
        foreach ($buckets as $k => $label) {
            if (($c[$k] ?? 0) > 0) {
                if (!$any) {
                    $pdf->SetFont('Helvetica', 'B', 9);
                    $pdf->SetTextColor(51, 65, 85);
                    $pdf->Cell(0, 6, 'By age', 0, 1);
                    $any = true;
                }
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->SetTextColor(60, 70, 85);
                $pdf->Cell(40, 5.5, self::t($label), 0, 0);
                $pdf->Cell(30, 5.5, self::money((float)$c[$k]), 0, 1, 'R');
            }
        }

        if (trim($note) !== '') {
            $pdf->Ln(5);
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->MultiCell(0, 5.5, self::t(trim($note)));
        }
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'I', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->MultiCell(0, 4.5, 'If you have already paid, please disregard this statement. Balances are as they stand on the date of issue.');

        return $pdf->Output('S');
    }
}
