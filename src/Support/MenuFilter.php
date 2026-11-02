<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes menu entries the current user may not use, so the sidebar and the
 * "Create new" shortcuts only ever show what the role AND the tenant's
 * assigned modules allow. Server-side enforcement still happens in
 * admin/index.php and Master.php; this is the matching presentation layer.
 */
final class MenuFilter
{
    /**
     * @param string $html   rendered menu markup
     * @param object $settings SystemSettings
     * @param bool   $create  true = require the "create" action of the module
     */
    public static function filter(string $html, $settings, bool $create = false): string
    {
        if ($settings->is_super_admin() || trim($html) === '' || !class_exists('DOMDocument')) {
            return $html;
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="__mf">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($dom);

        $remove = [];
        foreach ($xp->query('//a[@href]') as $a) {
            $q = [];
            parse_str((string)parse_url($a->getAttribute('href'), PHP_URL_QUERY), $q);
            if (empty($q['page']) || !is_string($q['page'])) {
                continue;
            }
            if (!self::allowed($q['page'], $settings, $create)) {
                $node = $a;
                while ($node && !($node->nodeName === 'li' || ($create && $node->nodeName === 'a' && $node === $a))) {
                    $node = $node->parentNode;
                }
                $remove[] = $create ? $a : $node;
            }
        }
        foreach ($remove as $n) {
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }

        // Drop section headings whose submenu emptied out.
        foreach (iterator_to_array($xp->query('//li[contains(@class,"has-treeview")]')) as $li) {
            if ($xp->query('.//ul[contains(@class,"nav-treeview")]//a[@href]', $li)->length === 0 && $li->parentNode) {
                $li->parentNode->removeChild($li);
            }
        }

        $root = $dom->getElementById('__mf') ?: $xp->query('//div[@id="__mf"]')->item(0);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    /** Does the menu target page pass role + tenant-module checks? */
    public static function allowed(string $page, $settings, bool $create = false): bool
    {
        $perm = Permissions::forPage($page);
        if ($perm === Permissions::SUPER_ONLY) {
            return false; // super admins never reach here (filter() bypasses them)
        }
        if (!is_string($perm)) {
            return true; // open or unlisted page
        }
        if ($create) {
            $perm = preg_replace('/_view$/', '_create', $perm);
        }
        return (bool)$settings->can_access($perm);
    }

    /** True when at least one anchor remains in the markup. */
    public static function hasLinks(string $html): bool
    {
        return stripos($html, '<a ') !== false && (bool)preg_match('/<a\s[^>]*href=/i', $html);
    }
}
