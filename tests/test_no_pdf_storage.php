<?php
// House rule: PDFs are generated on the fly to view or email and are NEVER stored on the server.
// This scans the application code for anything that would write a PDF to disk.
$root = dirname(__DIR__);
$fail = 0;
function check($n, $c) { global $fail; echo ($c ? 'PASS' : 'FAIL') . " - $n\n"; if (!$c) { $fail++; } }

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$bad = [];
foreach ($it as $f) {
    $p = str_replace('\\', '/', (string)$f);
    if (substr($p, -4) !== '.php' || preg_match('~/(vendor|libs|tests|node_modules|\.git)/~', $p)) { continue; }
    foreach (file($p) as $n => $line) {
        // FPDF Output() with anything but 'S' (string) saves a file or sends one; file writes mentioning pdf.
        if (preg_match('~->Output\((?!\s*[\'"]S[\'"])~', $line)) { $bad[] = "$p:" . ($n + 1) . ' Output() is not in-memory'; }
        if (preg_match('~(file_put_contents|fwrite|fputs|move_uploaded_file|copy|rename)\(.*pdf~i', $line)) { $bad[] = "$p:" . ($n + 1) . ' writes a pdf'; }
        if (preg_match('~->Output\([^)]*\.pdf~i', $line)) { $bad[] = "$p:" . ($n + 1) . ' saves a pdf'; }
    }
}
check('no application code writes a PDF to disk' . ($bad ? ' - ' . implode('; ', $bad) : ''), !$bad);
check('the statement PDF is returned as a string', strpos(file_get_contents("$root/src/Support/StatementPdf.php"), "return \$pdf->Output('S');") !== false);
exit($fail ? 1 : 0);
