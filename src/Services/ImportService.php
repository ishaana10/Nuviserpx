<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Bulk import of customers and suppliers, products and chart-of-accounts rows from a CSV file.
 *
 * Every row is validated first and the file can be previewed without writing anything. Rows are then saved through the
 * same services the screens use, so each one gets the normal checks, numbering and audit entry. Existing records are
 * matched by code: they are skipped, or updated (only the cells that are filled in change; blank cells keep their value).
 */
class ImportService
{
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 2097152;

    /** Entity => [field => header aliases]. The first field of each entity is its code. Header matching ignores case, spaces and punctuation. */
    public const ENTITIES = [
        'parties' => [
            'label' => 'Customers & Suppliers',
            'required' => ['name'],
            'fields' => [
                'party_code' => ['partycode', 'code', 'customercode', 'suppliercode', 'vendorcode', 'accountnumber'],
                'name' => ['name', 'company', 'companyname', 'customername', 'suppliername', 'vendorname', 'contactname'],
                'type' => ['type', 'partytype', 'kind', 'customerorsupplier'],
                'email' => ['email', 'emailaddress'],
                'phone' => ['phone', 'phonenumber', 'mobile', 'telephone'],
                'tin' => ['tin', 'taxid', 'taxnumber', 'vatnumber', 'taxidentificationnumber'],
                'address' => ['address', 'postaladdress', 'streetaddress'],
                'credit_limit' => ['creditlimit', 'limit'],
                'payment_terms_days' => ['paymenttermsdays', 'paymentterms', 'terms', 'termsdays'],
                'status' => ['status', 'active'],
            ],
            'sample' => ['CUS-0001', 'Acme Traders Ltd', 'customer', 'accounts@acme.example', '3301234', '12-345-6-7', '12 Main Street, Suva', '5000', '30', 'active'],
        ],
        'products' => [
            'label' => 'Products',
            'required' => ['product_code', 'name'],
            'fields' => [
                'product_code' => ['productcode', 'code', 'sku', 'itemcode', 'itemno'],
                'name' => ['name', 'productname', 'item', 'itemname', 'title'],
                'category' => ['category', 'group', 'productcategory'],
                'unit' => ['unit', 'uom', 'unitofmeasure'],
                'cost_price' => ['costprice', 'cost', 'unitcost', 'purchaseprice'],
                'selling_price' => ['sellingprice', 'price', 'sellprice', 'salesprice', 'unitprice', 'retailprice'],
                'stock_quantity' => ['stockquantity', 'openingstock', 'quantity', 'qty', 'stock', 'onhand'],
                'reorder_level' => ['reorderlevel', 'reorderpoint', 'minstock', 'minimumstock'],
                'description' => ['description', 'notes', 'details'],
                'status' => ['status', 'active'],
            ],
            'sample' => ['SKU-001', 'Printer paper A4', 'Stationery', 'ream', '6.50', '9.90', '0', '10', '80gsm white', 'active'],
        ],
        'accounts' => [
            'label' => 'Chart of Accounts',
            'required' => ['name'],
            'fields' => [
                'code' => ['code', 'accountcode', 'accountnumber', 'no'],
                'name' => ['name', 'accountname', 'account'],
                'type' => ['type', 'accounttype', 'class'],
                'tax_rate' => ['taxrate', 'tax', 'vatrate'],
                'description' => ['description', 'notes'],
                'bank_name' => ['bankname', 'bank'],
                'account_number' => ['banknumber', 'bankaccountnumber', 'bankaccount'],
                'currency' => ['currency', 'currencycode'],
                'status' => ['status', 'active'],
            ],
            'sample' => ['6100', 'Office supplies', 'Expense', '', 'Stationery and consumables', '', '', '', 'active'],
        ],
    ];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    // ------------------------------------------------------------------ pure helpers

    /** Pure: split CSV text into rows. Handles a BOM, Windows line endings, quoted commas and newlines, and comma, semicolon or tab separators. */
    public static function parseCsv(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($text, "\n") ?: '';
        $best = ',';
        $bestN = -1;
        foreach ([',', ';', "\t"] as $d) {
            $n = substr_count($firstLine, $d);
            if ($n > $bestN) {
                $best = $d;
                $bestN = $n;
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $best, '"', '')) !== false) {
            if ($r === [null] || !array_filter($r, fn($c) => trim((string)$c) !== '')) {
                continue;
            }
            $rows[] = array_map(fn($c) => trim((string)$c), $r);
        }
        fclose($h);
        return $rows;
    }

    private static function key(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
    }

    /** Pure: which column holds which field. Returns [map: column index => field, unknown: headers not recognised, missing: required fields not found]. */
    public static function mapHeaders(array $header, string $entity): array
    {
        $def = self::ENTITIES[$entity];
        $lookup = [];
        foreach ($def['fields'] as $field => $aliases) {
            $lookup[self::key($field)] = $field;
            foreach ($aliases as $a) {
                $lookup[self::key($a)] ??= $field;
            }
        }
        $map = [];
        $unknown = [];
        foreach ($header as $i => $h) {
            $f = $lookup[self::key((string)$h)] ?? null;
            if ($f === null) {
                if (trim((string)$h) !== '') {
                    $unknown[] = (string)$h;
                }
            } elseif (!in_array($f, $map, true)) {
                $map[$i] = $f;
            }
        }
        $missing = array_values(array_diff($def['required'], array_values($map)));
        return ['map' => $map, 'unknown' => $unknown, 'missing' => $missing];
    }

    /** Pure: "$1,250.50" becomes 1250.5. Null when the text is not a number. */
    public static function number(string $s): ?float
    {
        $t = trim($s);
        if ($t === '') {
            return null;
        }
        if (!preg_match('/^[\s$€£]*-?[\d,]*\.?\d+\s*$/', $t)) {
            return null;
        }
        return (float)str_replace([',', '$', '€', '£', ' '], '', $t);
    }

    /** Pure: active/inactive in the ways people write it. Null when blank, false when unrecognised. */
    public static function status(string $s): int|null|false
    {
        $t = strtolower(trim($s));
        if ($t === '') {
            return null;
        }
        if (in_array($t, ['1', 'active', 'yes', 'y', 'true', 'enabled'], true)) {
            return 1;
        }
        if (in_array($t, ['0', 'inactive', 'no', 'n', 'false', 'disabled'], true)) {
            return 0;
        }
        return false;
    }

    /** Pure: the canonical account type for what was typed (case-insensitive), or null. */
    public static function accountType(string $s): ?string
    {
        $t = strtolower(trim($s));
        foreach (AccountService::ACCOUNT_TYPES as $types) {
            foreach ($types as $known) {
                if (strtolower($known) === $t) {
                    return $known;
                }
            }
        }
        return null;
    }

    /**
     * Pure: clean and check one row. $row maps field => raw text for the columns present in the file.
     * @return array{0: array, 1: string[]} the cleaned values (only fields that were filled in) and the problems
     */
    public static function cleanRow(string $entity, array $row): array
    {
        $out = [];
        $err = [];
        $t = fn(string $f): string => trim((string)($row[$f] ?? ''));
        $text = function (string $f, int $max, bool $required = false) use (&$out, &$err, $t): void {
            $v = $t($f);
            if ($v === '') {
                if ($required) {
                    $err[] = str_replace('_', ' ', $f) . ' is required';
                }
                return;
            }
            if (mb_strlen($v) > $max) {
                $err[] = str_replace('_', ' ', $f) . " is longer than {$max} characters";
                return;
            }
            $out[$f] = $v;
        };
        $num = function (string $f, float $min = 0.0, ?float $max = null) use (&$out, &$err, $t): void {
            $v = $t($f);
            if ($v === '') {
                return;
            }
            $n = self::number($v);
            if ($n === null) {
                $err[] = str_replace('_', ' ', $f) . " '{$v}' is not a number";
            } elseif ($n < $min || ($max !== null && $n > $max)) {
                $err[] = str_replace('_', ' ', $f) . " must be " . ($max !== null ? "between {$min} and {$max}" : "{$min} or more");
            } else {
                $out[$f] = $n;
            }
        };
        $stat = function () use (&$out, &$err, $t): void {
            $s = self::status($t('status'));
            if ($s === false) {
                $err[] = "status '" . $t('status') . "' should be active or inactive";
            } elseif ($s !== null) {
                $out['status'] = $s;
            }
        };

        if ($entity === 'parties') {
            $text('party_code', 50);
            if (isset($out['party_code']) && !preg_match('/^[A-Za-z0-9._\-\/]+$/', $out['party_code'])) {
                $err[] = 'party code may only use letters, numbers and . _ - /';
            }
            $text('name', 250, true);
            $ty = strtolower($t('type'));
            if ($ty !== '') {
                $ty = in_array($ty, ['supplier', 'vendor', 'creditor'], true) ? 'vendor' : (in_array($ty, ['customer', 'client', 'debtor'], true) ? 'customer' : '');
                if ($ty === '') {
                    $err[] = "type '" . $t('type') . "' should be customer or supplier";
                } else {
                    $out['type'] = $ty;
                }
            }
            $text('email', 150);
            if (isset($out['email']) && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) {
                $err[] = "email '{$out['email']}' is not valid";
            }
            $text('phone', 50);
            $text('tin', 50);
            $text('address', 1000);
            $num('credit_limit');
            $num('payment_terms_days', 0, 365);
            if (isset($out['payment_terms_days']) && floor($out['payment_terms_days']) != $out['payment_terms_days']) {
                $err[] = 'payment terms must be whole days';
            }
            $stat();
        } elseif ($entity === 'products') {
            $text('product_code', 50, true);
            $text('name', 250, true);
            $text('category', 100);
            $text('unit', 20);
            $num('cost_price');
            $num('selling_price');
            $num('stock_quantity');
            $num('reorder_level');
            $text('description', 2000);
            $stat();
        } elseif ($entity === 'accounts') {
            $text('code', 20);
            if (isset($out['code']) && !preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $out['code'])) {
                $err[] = 'code may only use letters, numbers, dots and dashes';
            }
            $text('name', 150, true);
            if ($t('type') !== '') {
                $canon = self::accountType($t('type'));
                if ($canon === null) {
                    $err[] = "type '" . $t('type') . "' is not an account type";
                } else {
                    $out['type'] = $canon;
                }
            }
            $num('tax_rate', 0, 100);
            $text('description', 500);
            $text('bank_name', 100);
            $text('account_number', 50);
            $text('currency', 3);
            if (isset($out['currency'])) {
                $out['currency'] = strtoupper($out['currency']);
            }
            $stat();
        }
        return [$out, $err];
    }

    // ------------------------------------------------------------------ running an import

    /**
     * @param string $mode 'skip' leaves records that already exist alone, 'update' changes them
     * @return array{fatal: ?string, rows: array, counts: array, unknown: string[]}
     */
    public function run(string $entity, string $csv, string $mode, bool $dryRun): array
    {
        $res = ['fatal' => null, 'rows' => [], 'counts' => ['new' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0], 'unknown' => []];
        if (!isset(self::ENTITIES[$entity])) {
            $res['fatal'] = 'Choose what you are importing.';
            return $res;
        }
        $mode = $mode === 'update' ? 'update' : 'skip';
        $rows = self::parseCsv($csv);
        if (count($rows) < 2) {
            $res['fatal'] = 'The file has no data rows. The first row must be the column headings.';
            return $res;
        }
        if (count($rows) - 1 > self::MAX_ROWS) {
            $res['fatal'] = 'The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.';
            return $res;
        }
        $h = self::mapHeaders(array_shift($rows), $entity);
        $res['unknown'] = $h['unknown'];
        if ($h['missing']) {
            $res['fatal'] = 'These columns are required but were not found: ' . implode(', ', array_map(fn($f) => str_replace('_', ' ', $f), $h['missing'])) . '. Download the template to see the headings.';
            return $res;
        }
        $existing = $this->existing($entity);
        $seen = [];
        $plan = [];
        foreach ($rows as $i => $cells) {
            $line = $i + 2;
            $raw = [];
            foreach ($h['map'] as $col => $field) {
                $raw[$field] = $cells[$col] ?? '';
            }
            [$clean, $problems] = self::cleanRow($entity, $raw);
            $label = $clean['name'] ?? ($raw['name'] ?? '');
            $match = null;
            if (!$problems) {
                [$match, $dupe] = $this->match($entity, $clean, $existing, $seen, $line);
                if ($dupe) {
                    $problems[] = $dupe;
                }
            }
            if ($problems) {
                $res['rows'][] = ['line' => $line, 'status' => 'error', 'label' => $label, 'messages' => $problems];
                $res['counts']['error']++;
                continue;
            }
            if ($match !== null && $mode === 'skip') {
                $res['rows'][] = ['line' => $line, 'status' => 'skip', 'label' => $label, 'messages' => ['already exists, left unchanged']];
                $res['counts']['skip']++;
                continue;
            }
            $status = $match !== null ? 'update' : 'new';
            $res['counts'][$status]++;
            $res['rows'][] = ['line' => $line, 'status' => $status, 'label' => $label, 'messages' => []];
            $plan[] = [count($res['rows']) - 1, $clean, $match];
        }
        if ($dryRun) {
            return $res;
        }
        foreach ($plan as [$idx, $clean, $match]) {
            $out = $this->save($entity, $clean, $match);
            $isNew = $match === null;
            if ($out === null) {
                $res['rows'][$idx]['status'] = $isNew ? 'created' : 'updated';
                $res['counts'][$isNew ? 'created' : 'updated']++;
            } else {
                $res['rows'][$idx]['status'] = 'failed';
                $res['rows'][$idx]['messages'][] = $out;
                $res['counts']['failed']++;
            }
        }
        $c = $res['counts'];
        $this->settings->log_audit('Imported ' . self::ENTITIES[$entity]['label'], 'Data Import', "{$c['created']} created, {$c['updated']} updated, {$c['skip']} skipped, " . ($c['error'] + $c['failed']) . ' not imported (mode ' . $mode . ').');
        return $res;
    }

    /** Existing records of the entity for this company, for matching: code => row, plus name => row for accounts. */
    private function existing(string $entity): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $byCode = [];
        $byName = [];
        if ($entity === 'parties') {
            $sql = "SELECT * FROM party_list WHERE tenant_id = ? AND delete_flag = 0";
            $codeCol = 'party_code';
        } elseif ($entity === 'products') {
            $sql = "SELECT * FROM product_list WHERE tenant_id = ? AND delete_flag = 0";
            $codeCol = 'product_code';
        } else {
            $sql = "SELECT * FROM account_list WHERE tenant_id = ? AND delete_flag = 0";
            $codeCol = 'code';
        }
        $q = $this->conn->prepare($sql);
        $q->bind_param('i', $t);
        $q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if (trim((string)$r[$codeCol]) !== '') {
                $byCode[strtolower(trim((string)$r[$codeCol]))] = $r;
            }
            $byName[strtolower(trim((string)$r['name']))] = $r;
        }
        return ['code' => $byCode, 'name' => $byName];
    }

    /**
     * Find the existing record a row refers to and catch repeats inside the file.
     * @return array{0: ?array, 1: ?string} the existing row (or null) and a problem message
     */
    private function match(string $entity, array $clean, array $existing, array &$seen, int $line): array
    {
        $codeField = $entity === 'parties' ? 'party_code' : ($entity === 'products' ? 'product_code' : 'code');
        $code = strtolower((string)($clean[$codeField] ?? ''));
        $name = strtolower((string)($clean['name'] ?? ''));
        // Repeats inside the file
        foreach (array_filter(['code:' . $code => $code !== '', 'name:' . $name => $entity === 'accounts' && $name !== '']) as $k => $_) {
            if (isset($seen[$k])) {
                return [null, "repeats row {$seen[$k]} (same " . strtok($k, ':') . ')'];
            }
            $seen[$k] = $line;
        }
        $row = null;
        if ($code !== '') {
            $row = $existing['code'][$code] ?? null;
            if ($row === null && $entity === 'accounts' && $name !== '' && isset($existing['name'][$name])) {
                return [null, "the name '" . $clean['name'] . "' is already used by account " . ($existing['name'][$name]['code'] ?: '#' . $existing['name'][$name]['id']) . ', which has a different code'];
            }
        } elseif ($entity === 'accounts' && $name !== '') {
            $row = $existing['name'][$name] ?? null;
        }
        return [$row, null];
    }

    /** Save one cleaned row through the normal service. Returns null on success or the reason it failed. */
    private function save(string $entity, array $clean, ?array $match): ?string
    {
        $t = (int)$this->settings->active_tenant_id();
        if ($entity === 'parties') {
            $post = $match ? array_merge($this->pick($match, ['party_code', 'name', 'type', 'email', 'phone', 'tin', 'address', 'credit_limit', 'payment_terms_days', 'status']), $clean, ['id' => (int)$match['id']]) : $clean + ['type' => 'customer'];
            if (!$match && empty($post['party_code'])) {
                $post['party_code'] = SequenceService::documentNo($this->conn, $t, 'party_list', 'party_code', $post['type'] === 'vendor' ? 'VEN-' : 'CUS-', $post['type'] === 'vendor' ? 'party-ven' : 'party-cus');
            }
            $r = json_decode((new PartyService($this->conn, $this->settings))->saveParty($post), true);
        } elseif ($entity === 'products') {
            if ($match) {
                $post = array_merge($this->pick($match, ['product_code', 'name', 'category', 'unit', 'cost_price', 'selling_price', 'reorder_level', 'description', 'status']), array_diff_key($clean, ['stock_quantity' => 1]), ['id' => (int)$match['id']]);
            } else {
                $post = $clean;
            }
            $r = json_decode((new InventoryService($this->conn, $this->settings))->saveProduct($post), true);
        } else {
            $post = $match ? array_merge($this->pick($match, ['code', 'name', 'type', 'tax_rate', 'description', 'status', 'bank_name', 'account_number', 'currency']), $clean, ['id' => (int)$match['id']]) : $clean;
            $r = json_decode((new AccountService($this->conn, $this->settings))->saveAccount($post), true);
        }
        if (($r['status'] ?? '') === 'success') {
            return null;
        }
        return trim((string)($r['msg'] ?? 'could not be saved'));
    }

    private function pick(array $row, array $fields): array
    {
        $o = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $o[$f] = $row[$f];
            }
        }
        return $o;
    }

    /** The heading row and an example row for the template download. */
    public static function template(string $entity): array
    {
        $def = self::ENTITIES[$entity] ?? null;
        return $def ? [array_keys($def['fields']), $def['sample']] : [[], []];
    }
}
