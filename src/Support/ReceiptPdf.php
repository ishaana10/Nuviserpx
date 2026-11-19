<?php

declare(strict_types=1);

namespace App\Support;

/** A payment receipt as a PDF: who paid, how much, how, and which invoice it settled. Built in memory. */
final class ReceiptPdf
{
    private static function money(float $v): string
    {
        return number_format($v, 2);
    }

    public static function filename(string $no): string
    {
        return 'Receipt-' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', $no) ?: 'payment') . '.pdf';
    }

    /**
     * @param array<string,mixed> $payment payment_no, payment_date, method, reference, amount
     * @param array<string,mixed> $invoice invoice_no, total, balance (after this payment), status
     * @param array<string,mixed> $customer name, address, tin
     * @param array<string,mixed> $brand   letterhead fields
     */
    public static function render(array $payment, array $invoice, array $customer, array $brand, string $currency): string
    {
        $t = [PdfDoc::class, 't'];
        $label = [30, 58, 138];
        $pdf = new PdfDoc('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages('{nb}');
        $pdf->SetTitle($t('Receipt ' . (string)($payment['payment_no'] ?? '')));
        $pdf->footerText = $t((string)($brand['name'] ?? '') . ' - Receipt ' . (string)($payment['payment_no'] ?? ''));
        $pdf->AddPage();
        $pdf->letterhead($brand);

        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetTextColor($label[0], $label[1], $label[2]);
        $pdf->Cell(0, 10, 'RECEIPT', 0, 1);

        $pdf->SetTextColor(51, 65, 85);
        $meta = [
            ['Receipt no.', '#' . (string)($payment['payment_no'] ?? '')],
            ['Date', date('d M Y', strtotime((string)$payment['payment_date']))],
            ['Method', ucwords((string)($payment['method'] ?? '')) ?: '-'],
            ['Reference', (string)($payment['reference'] ?? '') ?: '-'],
        ];
        $top = $pdf->GetY();
        foreach ($meta as [$k, $v]) {
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(26, 5.5, $k, 0, 0);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->Cell(60, 5.5, $t($v), 0, 1);
        }
        $after = $pdf->GetY();

        $pdf->SetXY(110, $top);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 5, 'RECEIVED FROM', 0, 1);
        $pdf->SetX(110);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->MultiCell(85, 5.5, $t((string)$customer['name']));
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 100, 115);
        foreach (array_slice(array_filter(array_map('trim', explode("\n", (string)($customer['address'] ?? '')))), 0, 4) as $line) {
            $pdf->SetX(110);
            $pdf->Cell(85, 4.5, $t($line), 0, 1);
        }
        $pdf->SetY(max($after, $pdf->GetY()) + 8);

        // Amount box
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(180, 8, $t('  Amount received'), 0, 1, 'L', true);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetTextColor($label[0], $label[1], $label[2]);
        $pdf->Cell(180, 14, $t('  ' . trim($currency) . ' ' . self::money((float)$payment['amount'])), 0, 1, 'L', true);
        $pdf->Ln(6);

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->Cell(0, 6, 'Applied to', 0, 1);
        $rows = [
            ['Invoice', '#' . (string)$invoice['invoice_no']],
            ['Invoice total', trim($currency) . ' ' . self::money((float)$invoice['total'])],
            ['Balance after this payment', trim($currency) . ' ' . self::money(max(0.0, (float)$invoice['balance']))],
            ['Status', ucwords(str_replace('_', ' ', (string)$invoice['status']))],
        ];
        foreach ($rows as [$k, $v]) {
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->Cell(60, 6, $t($k), 'B', 0);
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(120, 6, $t($v), 'B', 1, 'R');
        }
        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 5, 'Thank you for your payment.', 0, 1);

        return $pdf->Output('S');
    }
}
