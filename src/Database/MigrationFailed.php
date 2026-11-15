<?php

declare(strict_types=1);

namespace App\Database;

/** A migration statement failed for a real reason. Carries what an operator needs to fix it. */
final class MigrationFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $migration,
        public readonly string $statement,
        public readonly int $sqlErrno,
        public readonly string $sqlError
    ) {
        $short = mb_substr((string)preg_replace('/\s+/', ' ', $statement), 0, 300);
        parent::__construct("Migration {$migration} failed (MySQL error {$sqlErrno}: {$sqlError}) at: {$short}");
    }
}
