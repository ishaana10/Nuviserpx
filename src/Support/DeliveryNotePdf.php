<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A delivery note as a PDF (no prices: it travels with the goods), including the receiver's typed name and
 * signature once the note has been signed. Built and returned as a string in memory; never written to disk
 * (tests/test_no_pdf_storage.php enforces this).
 */
final class DeliveryNotePdf
{
    /** "DeliveryNote-DN-0001.pdf": letters, digits, dot and dash only. */
    public static function filename(string $dnNo): string
    {
        $slug = trim((string)preg_replace('/[^A-Za-z0-9.]+/', '-', PdfDoc::t($dnNo)), '-.');
        return 'DeliveryNote-' . ($slug !== '' ? substr($slug, 0, 50) : 'document') . '.pdf';
    }

    private static function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param array $note   delivery note row + order_no, party_name, party_address, received_by, receiver_signature, received_at
     * @param array $items  delivery_note_items rows: item_name, qty
     * @param array $brand  tenant_branding(): name, email, phone, address, logo
     */
    public static function render(array $note, array $items, array $brand): string
    {
        $t = [PdfDoc::class, 't'];
        $pdf = new PdfDoc('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages('{nb}');
        $pdf->SetTitle($t('Delivery note ' . (string)$note['dn_no']));
        $pdf->footerText = $t((string)($brand['name'] ?? '') . ' - Delivery note ' . (string)$note['dn_no']);
        $pdf->AddPage();
        $pdf->letterhead($brand);

        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 10, 'DELIVERY NOTE', 0, 1);

        $top = $pdf->GetY();
        $pdf->SetTextColor(51, 65, 85);
        foreach ([['Delivery note', (string)$note['dn_no']], ['Date', date('d M Y', strtotime((string)$note['delivery_date']))], ['Order', (string)($note['order_no'] ?? '')]] as [$k, $v]) {
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(28, 5.5, $k, 0, 0);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->Cell(60, 5.5, $t($v), 0, 1);
        }
        $after = $pdf->GetY();
        $pdf->SetXY(110, $top);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 5, 'DELIVER TO', 0, 1);
        $pdf->SetX(110);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->MultiCell(85, 5.5, $t((string)$note['party_name']));
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 100, 115);
        foreach (array_slice(array_filter(array_map('trim', explode("\n", (string)($note['party_address'] ?? '')))), 0, 4) as $line) {
            $pdf->SetX(110);
            $pdf->Cell(85, 4.5, $t($line), 0, 1);
        }
        $pdf->SetY(max($after, $pdf->GetY()) + 6);

        $head = function () use ($pdf): void {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(30, 58, 138);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(150, 7.5, 'Item', 0, 0, 'L', true);
            $pdf->Cell(30, 7.5, 'Quantity', 0, 1, 'R', true);
        };
        $head();
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(30, 41, 59);
        foreach ($items as $it) {
            if ($pdf->GetY() + 10 > $pdf->GetPageHeight() - 22) {
                $pdf->AddPage();
                $head();
                $pdf->SetFont('Helvetica', '', 9.5);
                $pdf->SetTextColor(30, 41, 59);
            }
            $pdf->Cell(150, 7, $t(mb_substr((string)$it['item_name'], 0, 120)), 'B', 0);
            $pdf->Cell(30, 7, self::qty((float)$it['qty']), 'B', 1, 'R');
        }
        if (trim((string)($note['notes'] ?? '')) !== '') {
            $pdf->Ln(4);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->MultiCell(0, 5, $t('Notes: ' . (string)$note['notes']));
        }

        // Proof of delivery
        if ($pdf->GetY() + 45 > $pdf->GetPageHeight() - 20) {
            $pdf->AddPage();
        }
        $pdf->Ln(10);
        $y = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(51, 65, 85);
        $by = trim((string)($note['delivered_by'] ?? ''));
        $pdf->Cell(90, 6, 'Delivered by: ' . ($by !== '' ? $by : '______________________'), 0, 0);
        $signed = !empty($note['received_at']) && trim((string)($note['received_by'] ?? '')) !== '';
        if ($signed) {
            $sig = (string)($note['receiver_signature'] ?? '');
            $drawn = false;
            if (ProposalLink::validSignatureImage($sig)) {
                $drawn = $pdf->pngFromString((string)base64_decode(substr($sig, strlen('data:image/png;base64,')), true), 110, $y - 2, 60, 24);
            }
            $pdf->SetXY(105, $y + 24);
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(90, 5.5, $t('Received by: ' . (string)$note['received_by']), 'T', 1);
            $pdf->SetX(105);
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(90, 5, 'Signed electronically on ' . date('d M Y, H:i', strtotime((string)$note['received_at'])) . ($drawn ? '' : ' (signature image not available)'), 0, 1);
        } else {
            $pdf->Cell(90, 6, 'Received by (name & signature): ______________________', 0, 1, 'R');
        }
        return $pdf->Output('S');
    }
}
