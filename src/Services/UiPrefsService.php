<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Per-user look and feel: colour mode, accent colour, side menu style, density, text size, pinned menu items.
 *
 * Stored once per user (not per company) so a person's choices follow them when they switch tenant. Everything that
 * reaches the page is passed through clean(), which only lets known values through, so a saved preference can never
 * inject markup or CSS.
 */
final class UiPrefsService
{
    public const MODES = ['light', 'dark', 'auto'];
    public const SIDEBARS = ['dark', 'light', 'accent'];
    public const DENSITIES = ['compact', 'comfortable', 'spacious'];
    public const TEXT_SIZES = ['small', 'medium', 'large'];
    public const MAX_PINS = 8;

    /** Accent presets offered in the picker (name => hex). */
    public const ACCENTS = [
        'blue' => '#2563eb', 'indigo' => '#4f46e5', 'violet' => '#7c3aed', 'rose' => '#e11d48',
        'orange' => '#ea580c', 'amber' => '#d97706', 'emerald' => '#059669', 'teal' => '#0d9488', 'slate' => '#475569',
    ];

    public const DEFAULTS = [
        'mode' => 'light',
        'accent' => '#2563eb',
        'sidebar' => 'dark',
        'density' => 'comfortable',
        'text' => 'medium',
        'collapsed' => false,
        'pins' => [],
    ];

    private $conn;
    private int $userId;

    public function __construct($conn, int $userId)
    {
        $this->conn = $conn;
        $this->userId = $userId;
    }

    /** Whitelist anything posted or stored; unknown keys and values fall back to the defaults. */
    public static function clean($in): array
    {
        $in = is_array($in) ? $in : [];
        $d = self::DEFAULTS;
        $accent = strtolower(trim((string)($in['accent'] ?? '')));
        if (isset(self::ACCENTS[$accent])) {
            $accent = self::ACCENTS[$accent];
        }
        $pins = [];
        foreach ((array)($in['pins'] ?? []) as $p) {
            $p = (string)$p;
            // Menu keys look like "invoices" or "reports_pnl" (the nav-<key> class on the link).
            if (preg_match('/^[a-z0-9_]{1,60}$/i', $p) && !in_array($p, $pins, true)) {
                $pins[] = $p;
            }
            if (count($pins) >= self::MAX_PINS) {
                break;
            }
        }
        return [
            'mode' => in_array($in['mode'] ?? '', self::MODES, true) ? $in['mode'] : $d['mode'],
            'accent' => preg_match('/^#[0-9a-f]{6}$/', $accent) ? $accent : $d['accent'],
            'sidebar' => in_array($in['sidebar'] ?? '', self::SIDEBARS, true) ? $in['sidebar'] : $d['sidebar'],
            'density' => in_array($in['density'] ?? '', self::DENSITIES, true) ? $in['density'] : $d['density'],
            'text' => in_array($in['text'] ?? '', self::TEXT_SIZES, true) ? $in['text'] : $d['text'],
            'collapsed' => filter_var($in['collapsed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'pins' => $pins,
        ];
    }

    /** Mix a #rrggbb colour toward black (negative $pct) or white (positive), e.g. shade('#2563eb', -12). */
    public static function shade(string $hex, int $pct): string
    {
        $hex = ltrim($hex, '#');
        $out = '#';
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2));
            $c = $pct < 0 ? (int)round($c * (100 + $pct) / 100) : (int)round($c + (255 - $c) * $pct / 100);
            $out .= str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** "#ffffff" or "#0f172a", whichever reads better on the given background (WCAG relative luminance). */
    public static function contrastText(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $lin = static function (int $v): float {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int)hexdec(substr($hex, 0, 2))) + 0.7152 * $lin((int)hexdec(substr($hex, 2, 2))) + 0.0722 * $lin((int)hexdec(substr($hex, 4, 2)));
        return $l > 0.4 ? '#0f172a' : '#ffffff';
    }

    /** The :root custom properties for a cleaned preference set (accent colours only; the rest is attribute driven). */
    public static function cssVars(array $p): string
    {
        $a = $p['accent'];
        $rgb = hexdec(substr($a, 1, 2)) . ',' . hexdec(substr($a, 3, 2)) . ',' . hexdec(substr($a, 5, 2));
        return ':root{--nv-accent:' . $a . ';--nv-accent-rgb:' . $rgb . ';--nv-accent-hover:' . self::shade($a, -12)
            . ';--nv-accent-dark:' . self::shade($a, -55) . ';--nv-accent-soft:' . self::shade($a, 88)
            . ';--nv-on-accent:' . self::contrastText($a) . ';}';
    }

    /** data-* attributes for the <html> element. data-nv-eff is the colour scheme in force; 'auto' is settled in the browser. */
    public static function htmlAttrs(array $p): string
    {
        return 'data-nv-mode="' . $p['mode'] . '" data-nv-eff="' . ($p['mode'] === 'dark' ? 'dark' : 'light') . '" data-nv-sidebar="' . $p['sidebar'] . '" data-nv-density="' . $p['density']
            . '" data-nv-text="' . $p['text'] . '"';
    }

    public function load(): array
    {
        if (!$this->conn || $this->userId <= 0) {
            return self::DEFAULTS;
        }
        $stmt = @$this->conn->prepare('SELECT prefs FROM user_ui_prefs WHERE user_id = ?');
        if (!$stmt) {
            return self::DEFAULTS;
        }
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return self::clean($row ? json_decode((string)$row['prefs'], true) : []);
    }

    /** Merge the posted fields over what is stored, clean the result and save it. Returns the saved set. */
    public function save(array $changes): array
    {
        $merged = self::clean(array_merge($this->load(), $changes));
        $json = json_encode($merged);
        $stmt = $this->conn->prepare('INSERT INTO user_ui_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)');
        $stmt->bind_param('is', $this->userId, $json);
        $stmt->execute();
        $stmt->close();
        return $merged;
    }

    public function reset(): array
    {
        $stmt = $this->conn->prepare('DELETE FROM user_ui_prefs WHERE user_id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $stmt->close();
        return self::DEFAULTS;
    }
}
