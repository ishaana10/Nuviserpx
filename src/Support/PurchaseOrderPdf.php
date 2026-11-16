<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A purchase order as a PDF, with the company's logo and details in the header. Built and returned as a string
 * in memory; never written to disk (tests/test_no_pdf_storage.php enforces this).
 */
final class PurchaseOrderPdf
{
    /** "PurchaseOrder-PO-0001.pdf": letters, digits, dot and dash only. */
    public static function filename(string $poNo): string
    {
        $slug = trim((string)preg_replace('/[^A-Za-z0-9.]+/', '-', PdfDoc::t($poNo)), '-.');
        return 'PurchaseOrder-' . ($slug !== '' ? substr($slug, 0, 50) : 'document') . '.pdf';
    }

    private static function money(float $v): string
    {
        return number_format($v, 2);
    }

    private static function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param array  $po       purchase_order_list row + vendor_name, vendor_code, vendor_email, vendor_phone, vendor_address
     * @param array  $items    purchase_order_items rows
     * @param array  $brand    tenant_branding(): name, email, phone, address, logo
     * @param string $currency e.g. "USD" ('' for none)
     */
    public static function render(array $po, array $items, array $brand, string $currency = ''): string
    {
        $t = [PdfDoc::class, 't'];
        $pdf = new PdfDoc('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages('{nb}');
        $pdf->SetTitle($t('Purchase order ' . (string)$po['po_no']));
        $pdf->footerText = $t((string)($brand['name'] ?? '') . ' - Purchase order ' . (string)$po['po_no']);
        $pdf->AddPage();
        $pdf->letterhead($brand);

        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 10, 'PURCHASE ORDER', 0, 1);

        $top = $pdf->GetY();
        $pdf->SetTextColor(51, 65, 85);
        $meta = [['PO number', (string)$po['po_no']], ['Order date', date('d M Y', strtotime((string)$po['order_date']))]];
        if (!empty($po['expected_date'])) {
            $meta[] = ['Expected', date('d M Y', strtotime((string)$po['expected_date']))];
        }
        $meta[] = ['Status', strtoupper(str_replace('_', ' ', (string)($po['status'] ?? '')))];
        foreach ($meta as [$k, $v]) {
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(28, 5.5, $k, 0, 0);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->Cell(60, 5.5, $t($v), 0, 1);
        }
        $after = $pdf->GetY();
        $pdf->SetXY(110, $top);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 5, 'VENDOR', 0, 1);
        $pdf->SetX(110);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->MultiCell(85, 5.5, $t((string)$po['vendor_name']));
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 100, 115);
        $vlines = array_filter(array_map('trim', explode("\n", (string)($po['vendor_address'] ?? ''))));
        $contact = trim((string)($po['vendor_email'] ?? '') . '  ' . (string)($po['vendor_phone'] ?? ''));
        if ($contact !== '') {
            $vlines[] = $contact;
        }
        foreach (array_slice($vlines, 0, 5) as $line) {
            $pdf->SetX(110);
            $pdf->Cell(85, 4.5, $t($line), 0, 1);
        }
        $pdf->SetY(max($after, $pdf->GetY()) + 6);

        $w = [80, 20, 28, 20, 32];
        $head = function () use ($pdf, $w): void {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(30, 58, 138);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell($w[0], 7.5, 'Item', 0, 0, 'L', true);
            $pdf->Cell($w[1], 7.5, 'Qty', 0, 0, 'R', true);
            $pdf->Cell($w[2], 7.5, 'Unit cost', 0, 0, 'R', true);
            $pdf->Cell($w[3], 7.5, 'Tax', 0, 0, 'R', true);
            $pdf->Cell($w[4], 7.5, 'Amount', 0, 1, 'R', true);
        };
        $head();
        foreach ($items as $it) {
            if ($pdf->GetY() + 12 > $pdf->GetPageHeight() - 22) {
                $pdf->AddPage();
                $head();
            }
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->SetTextColor(30, 41, 59);
            $rate = (float)($it['tax_rate'] ?? 0);
            $pdf->Cell($w[0], 7, $t(mb_substr((string)$it['item_name'], 0, 60)), 'B', 0);
            $pdf->Cell($w[1], 7, self::qty((float)$it['qty']), 'B', 0, 'R');
            $pdf->Cell($w[2], 7, self::money((float)$it['unit_price']), 'B', 0, 'R');
            $pdf->Cell($w[3], 7, $rate > 0 ? self::qty($rate) . '%' : '-', 'B', 0, 'R');
            $pdf->Cell($w[4], 7, self::money((float)$it['amount']), 'B', 1, 'R');
            $desc = trim((string)($it['description'] ?? ''));
            if ($desc !== '') {
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->SetTextColor(100, 116, 139);
                $pdf->Cell($w[0], 4.5, $t(mb_substr($desc, 0, 90)), 0, 1);
            }
        }

        $pdf->Ln(2);
        $line = function (string $k, string $v, bool $bold = false) use ($pdf, $w): void {
            $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 11 : 9.5);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell($w[0] + $w[1] + $w[2], $bold ? 7.5 : 6, $k, 0, 0, 'R');
            $pdf->Cell($w[3] + $w[4], $bold ? 7.5 : 6, $v, 0, 1, 'R');
        };
        if ($pdf->GetY() + 35 > $pdf->GetPageHeight() - 22) {
            $pdf->AddPage();
        }
        $line('Subtotal', self::money((float)$po['subtotal']));
        if ((float)($po['discount'] ?? 0) > 0) {
            $line('Discount', '-' . self::money((float)$po['discount']));
        }
        if ((float)($po['tax_amount'] ?? 0) > 0) {
            $line('Tax', self::money((float)$po['tax_amount']));
        }
        $line('Total' . ($currency !== '' ? ' ' . $t($currency) : ''), self::money((float)$po['total']), true);

        if (trim((string)($po['notes'] ?? '')) !== '') {
            $pdf->Ln(5);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(51, 65, 85);
            $pdf->Cell(0, 5, 'Notes / terms', 0, 1);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->MultiCell(0, 4.8, $t((string)$po['notes']));
        }
        return $pdf->Output('S');
    }
}
