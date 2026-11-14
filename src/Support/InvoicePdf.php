<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An invoice as a PDF, using the company's letterhead and invoice colour.
 *
 * Built and returned as a string in memory; never written to disk by this class or any caller. It is streamed to
 * the browser or attached to an email and then gone (tests/test_no_pdf_storage.php enforces this).
 */
final class InvoicePdf
{
    private static function money(float $v): string
    {
        return number_format($v, 2);
    }

    /** "Invoice-INV-0001.pdf": letters, digits, dot and dash only. */
    public static function filename(string $invoiceNo): string
    {
        $slug = trim((string)preg_replace('/[^A-Za-z0-9.]+/', '-', PdfDoc::t($invoiceNo)), '-.');
        return 'Invoice-' . ($slug !== '' ? substr($slug, 0, 50) : 'document') . '.pdf';
    }

    /**
     * @param array $invoice   invoice_list row: invoice_no, invoice_date, due_date, subtotal, discount, tax_amount, total, paid_amount, balance, status, notes
     * @param array $items     invoice_items rows: item_name, description, qty, unit_price, tax_rate, amount
     * @param array $customer  party: name, party_code, address, tin, email
     * @param array $brand     tenant_branding(): name, email, phone, address, logo
     * @param array $tmpl      invoice template: label_color, footer_notes
     */
    public static function render(array $invoice, array $items, array $customer, array $brand, array $tmpl, string $currency): string
    {
        $t = [PdfDoc::class, 't'];
        $label = PdfDoc::rgb((string)($tmpl['label_color'] ?? ''), [30, 58, 138]);
        $pdf = new PdfDoc('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages('{nb}');
        $pdf->SetTitle($t('Invoice ' . (string)$invoice['invoice_no']));
        $pdf->footerText = $t((string)($brand['name'] ?? '') . ' - Invoice ' . (string)$invoice['invoice_no']);
        $pdf->AddPage();
        $pdf->letterhead($brand);

        // Title block, right-aligned on the first page below the letterhead
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetTextColor($label[0], $label[1], $label[2]);
        $pdf->Cell(0, 10, ((float)$invoice['tax_amount'] > 0 ? 'TAX INVOICE' : 'INVOICE'), 0, 1);

        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(51, 65, 85);
        $meta = [
            ['Invoice no.', '#' . (string)$invoice['invoice_no']],
            ['Date', date('d M Y', strtotime((string)$invoice['invoice_date']))],
            ['Due', !empty($invoice['due_date']) ? date('d M Y', strtotime((string)$invoice['due_date'])) : '-'],
            ['Status', ucwords(str_replace('_', ' ', (string)$invoice['status']))],
        ];
        $top = $pdf->GetY();
        foreach ($meta as [$k, $v]) {
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(26, 5.5, $k, 0, 0);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->Cell(60, 5.5, $t($v), 0, 1);
        }
        $after = $pdf->GetY();

        // Bill to, beside the meta block
        $pdf->SetXY(110, $top);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 5, 'BILL TO', 0, 1);
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
        if (trim((string)($customer['tin'] ?? '')) !== '') {
            $pdf->SetX(110);
            $pdf->Cell(85, 4.5, $t('TIN: ' . (string)$customer['tin']), 0, 1);
        }
        $pdf->SetY(max($after, $pdf->GetY()) + 6);

        // Items
        $w = [78, 18, 30, 24, 30];
        $head = function () use ($pdf, $w, $label): void {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor($label[0], $label[1], $label[2]);
            $pdf->SetTextColor(255, 255, 255);
            foreach (['Description', 'Qty', 'Unit price', 'Tax', 'Amount'] as $i => $h) {
                $pdf->Cell($w[$i], 7.5, $h, 0, 0, $i === 0 ? 'L' : 'R', true);
            }
            $pdf->Ln();
        };
        $head();
        foreach ($items as $it) {
            $name = mb_substr((string)$it['item_name'], 0, 160);
            $desc = mb_substr(trim((string)($it['description'] ?? '')), 0, 300);
            $lines = max(1, (int)ceil($pdf->GetStringWidth($t($name)) / ($w[0] - 3))) + ($desc !== '' ? max(1, (int)ceil($pdf->GetStringWidth($t($desc)) / ($w[0] - 3))) : 0);
            if ($pdf->GetY() + $lines * 5 + 3 > $pdf->GetPageHeight() - 22) {
                $pdf->AddPage();
                $head();
            }
            $y = $pdf->GetY();
            $pdf->SetXY(15, $y);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->MultiCell($w[0], 5, $t($name), 0, 'L');
            if ($desc !== '') {
                $pdf->SetFont('Helvetica', '', 8.5);
                $pdf->SetTextColor(100, 116, 139);
                $pdf->MultiCell($w[0], 4.5, $t($desc), 0, 'L');
            }
            $end = $pdf->GetY() + 1.5;
            $pdf->SetXY(15 + $w[0], $y);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell($w[1], 5, rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.'), 0, 0, 'R');
            $pdf->Cell($w[2], 5, self::money((float)$it['unit_price']), 0, 0, 'R');
            $rate = (float)($it['tax_rate'] ?? 0);
            $pdf->Cell($w[3], 5, $rate > 0 ? rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%' : '-', 0, 0, 'R');
            $pdf->Cell($w[4], 5, self::money((float)$it['amount']), 0, 0, 'R');
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->Line(15, $end, 195, $end);
            $pdf->SetY($end + 1);
        }

        // Totals
        $pdf->Ln(2);
        $line = function (string $k, string $v, bool $bold = false, ?array $color = null) use ($pdf, $w): void {
            $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 11 : 9.5);
            $pdf->SetTextColor($color[0] ?? 30, $color[1] ?? 41, $color[2] ?? 59);
            $pdf->Cell($w[0] + $w[1] + $w[2], $bold ? 7.5 : 6, $k, 0, 0, 'R');
            $pdf->Cell($w[3] + $w[4], $bold ? 7.5 : 6, $v, 0, 1, 'R');
        };
        if ($pdf->GetY() + 40 > $pdf->GetPageHeight() - 22) {
            $pdf->AddPage();
        }
        $line('Subtotal', self::money((float)$invoice['subtotal']));
        if ((float)($invoice['discount'] ?? 0) > 0) {
            $line('Discount', '-' . self::money((float)$invoice['discount']));
        }
        if ((float)$invoice['tax_amount'] > 0) {
            $line('Tax', self::money((float)$invoice['tax_amount']));
        }
        $line('Total ' . trim($t($currency)), self::money((float)$invoice['total']), true, $label);
        if ((float)($invoice['paid_amount'] ?? 0) > 0) {
            $line('Paid', '-' . self::money((float)$invoice['paid_amount']));
        }
        if ((float)$invoice['balance'] > 0) {
            $line('Balance due', self::money((float)$invoice['balance']), true, [185, 28, 28]);
        } elseif ((string)$invoice['status'] === 'paid') {
            $line('Paid in full', '', true, [22, 163, 74]);
        }

        // Notes and the company's footer text
        foreach ([['Notes', (string)($invoice['notes'] ?? '')], ['', (string)($tmpl['footer_notes'] ?? '')]] as [$h, $txt]) {
            if (trim($txt) === '') {
                continue;
            }
            $pdf->Ln(5);
            if ($h !== '') {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetTextColor(51, 65, 85);
                $pdf->Cell(0, 5, $h, 0, 1);
            }
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->MultiCell(0, 4.8, $t(mb_substr(trim($txt), 0, 1500)));
        }

        return $pdf->Output('S');
    }
}
