<?php

declare(strict_types=1);

namespace App\Support;

require_once dirname(__DIR__, 2) . '/libs/fpdf/fpdf.php';

/**
 * Shared page frame for the generated PDFs (statements, invoices): a running footer with page numbers.
 * PDFs are always built in memory and returned as a string; see tests/test_no_pdf_storage.php.
 */
final class PdfDoc extends \FPDF
{
    public string $footerText = '';

    public function Footer(): void
    {
        $this->SetY(-14);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(120, 130, 145);
        $this->Cell(0, 5, $this->footerText, 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    /** Latin-1/Windows-1252 text for the core PDF fonts; anything it cannot show becomes '?'. */
    public static function t(string $s): string
    {
        $o = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        return $o === false ? (string)preg_replace('/[^\x20-\x7E]/', '?', $s) : $o;
    }

    /** "#1e3a8a" to [30, 58, 138]; anything that is not a 6-digit hex colour gives the fallback. */
    public static function rgb(string $hex, array $fallback = [30, 58, 138]): array
    {
        return preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', trim($hex), $m)
            ? [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])] : $fallback;
    }

    /** Company letterhead: optional logo, name, address, contact. Leaves the cursor below it. */
    public function letterhead(array $brand): void
    {
        $logo = (string)($brand['logo'] ?? '');
        $file = ($logo !== '' && defined('base_app')) ? base_app . ltrim(explode('?', $logo)[0], '/') : '';
        $this->SetY(15);
        if ($file !== '' && is_file($file) && filesize($file) <= 1572864
            && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'], true) && @getimagesize($file)) {
            try {
                $this->Image($file, 15, 14, 0, 16);
                $this->SetY(32);
            } catch (\Throwable $e) {
                $this->SetY(15);
            }
        }
        $this->SetX(15);
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(110, 7, self::t((string)($brand['name'] ?? '')), 0, 1);
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(90, 100, 115);
        foreach (array_slice(array_filter(array_map('trim', explode("\n", (string)($brand['address'] ?? '')))), 0, 4) as $line) {
            $this->Cell(110, 4.5, self::t($line), 0, 1);
        }
        $contact = trim((string)($brand['email'] ?? '') . '  ' . (string)($brand['phone'] ?? ''));
        if ($contact !== '') {
            $this->Cell(110, 4.5, self::t($contact), 0, 1);
        }
    }
}
