<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/** Find-or-create customers for API clients: by lower-cased email, then TIN, else a new party. */
final class ExternalPartyService
{
    public static function find(mysqli $c, int $tenant, ?string $email, ?string $tin): ?array
    {
        $email = $email !== null ? strtolower(trim($email)) : '';
        $tin = $tin !== null ? trim($tin) : '';
        if ($email !== '') {
            $e = $c->real_escape_string($email);
            $r = $c->query("SELECT * FROM `party_list` WHERE tenant_id='{$tenant}' AND delete_flag=0 AND LOWER(email)='{$e}' ORDER BY id LIMIT 1");
            if ($r && ($row = $r->fetch_assoc())) return $row;
        }
        if ($tin !== '') {
            $t = $c->real_escape_string($tin);
            $r = $c->query("SELECT * FROM `party_list` WHERE tenant_id='{$tenant}' AND delete_flag=0 AND tin='{$t}' ORDER BY id LIMIT 1");
            if ($r && ($row = $r->fetch_assoc())) return $row;
        }
        return null;
    }

    /** @return array{id:int,party_code:string,created:bool} */
    public static function findOrCreate(mysqli $c, int $tenant, array $in): array
    {
        $email = isset($in['email']) ? strtolower(trim((string)$in['email'])) : '';
        $tin = isset($in['tin']) ? trim((string)$in['tin']) : '';
        $name = trim((string)($in['name'] ?? ''));
        if ($email === '' && $tin === '') throw new \InvalidArgumentException('email or tin is required.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('email is not valid.');
        if ($name === '') throw new \InvalidArgumentException('name is required.');

        $hit = self::find($c, $tenant, $email, $tin);
        if ($hit) return ['id' => (int)$hit['id'], 'party_code' => $hit['party_code'], 'created' => false];

        $n = $c->real_escape_string($name);
        $em = $email !== '' ? "'" . $c->real_escape_string($email) . "'" : 'NULL';
        $ti = $tin !== '' ? "'" . $c->real_escape_string($tin) . "'" : 'NULL';
        $ph = isset($in['phone']) ? "'" . $c->real_escape_string((string)$in['phone']) . "'" : 'NULL';
        $ad = isset($in['address']) ? "'" . $c->real_escape_string((string)$in['address']) . "'" : 'NULL';
        for ($i = 0; $i < 5; $i++) {
            $code = 'CUST-' . date('Ym') . '-' . random_int(1000, 99999);
            $c->query("INSERT INTO `party_list` SET tenant_id='{$tenant}', party_code='{$code}', name='{$n}', type='customer',
                email={$em}, phone={$ph}, tin={$ti}, address={$ad}");
            if (!$c->errno) return ['id' => (int)$c->insert_id, 'party_code' => $code, 'created' => true];
            if ($c->errno !== 1062) break;
            // A concurrent request may have created the same customer: use theirs.
            $hit = self::find($c, $tenant, $email, $tin);
            if ($hit) return ['id' => (int)$hit['id'], 'party_code' => $hit['party_code'], 'created' => false];
        }
        throw new \RuntimeException('Could not create party.');
    }
}
