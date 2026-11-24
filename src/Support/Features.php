<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Features that are built but not part of the current release. Switching one on here (and nowhere else) brings back its
 * menu entries, settings screens and actions.
 */
final class Features
{
    /** FRCS VMS v3.0 integration: deferred to a later version. */
    public static function vms(): bool
    {
        return false;
    }

    /** Pages that belong to a feature that is switched off. */
    private const PAGES = [
        'settings/vms_settings'    => 'vms',
        'developer/vms_inspector'  => 'vms',
    ];

    public static function pageAvailable(string $page): bool
    {
        $page = trim($page, '/');
        return !isset(self::PAGES[$page]) || self::vms();
    }

    public const REFUSED = 'This feature is not part of this release.';
}
