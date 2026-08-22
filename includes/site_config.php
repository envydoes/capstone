<?php
/**
 * includes/site_config.php
 * ------------------------------------------------------------
 * Shared, site-wide settings loader. Require this near the top of
 * ANY page (admin dashboards, resident/non-resident landing pages,
 * login page, etc.) to get live access to the single settings row
 * in tbl_settings, plus the current hero images.
 *
 * Usage in any page:
 *   require_once __DIR__ . '/../includes/site_config.php'; // adjust path depth
 *   // Now available: $siteSettings (array), $siteHeroImages (array)
 *
 * To make colors/logo/title actually show up on a page, echo the
 * helper output this file provides:
 *   <title><?= e($siteSettings['site_title']) ?></title>
 *   <?= site_config_css_vars($siteSettings) ?>   // inside <style> or <head>
 *   <img src="<?= site_config_logo_url($siteSettings) ?>" ...>
 *
 * Then in your page's own CSS, swap hardcoded colors like #15803d
 * for var(--site-primary) wherever you want them to follow the
 * admin's Color Theme setting.
 * ------------------------------------------------------------
 */

if (!function_exists('site_config_load')) {
    function site_config_load(mysqli $conn): array
    {
        $defaults = [
            'id'                => 1,
            'barangay_name'     => 'Sumacab Este',
            'municipality'      => 'Cabanatuan City',
            'contact_number'    => '09942946442',
            'email'             => 'barangaysumacabeste@gmail.com',
            'facebook_link'     => 'https://www.facebook.com/profile.php?id=61572407528959',
            'our_reach_content' => 'Sumacab Este residents, or even non-residents, can access barangay services through one online portal.',
            'puroks_covered'    => 7,
            'area_served'       => 4,
            'map_query'         => 'Sumacab Este, La Fuente, Cabanatuan, Nueva Ecija, Central Luzon, 3101, Philippines',
            'site_title'        => 'SumEste Portal',
            'site_logo'         => null,
            'barangay_logo'     => null,
            'municipality_logo' => null,
            'color_theme'       => '#15803d',
        ];

        $result = mysqli_query($conn, "SELECT * FROM tbl_settings WHERE id = 1 LIMIT 1");
        $row = $result ? mysqli_fetch_assoc($result) : null;

        return $row ? array_merge($defaults, $row) : $defaults;
    }
}

if (!function_exists('site_config_hero_images')) {
    function site_config_hero_images(mysqli $conn): array
    {
        $images = [];
        $result = mysqli_query($conn, "SELECT id, filename, sort_order FROM tbl_hero_images ORDER BY sort_order ASC, id ASC");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $images[] = $row;
            }
        }
        return $images;
    }
}

if (!function_exists('site_config_logo_url')) {
    /**
     * Builds a URL to the uploaded site logo, or falls back to the
     * existing default logo asset if none has been uploaded yet.
     * $rootRelativePath = how many "../" are needed to reach the
     * project root from the current page (e.g. 1 for admin/*.php).
     */
    function site_config_logo_url(array $settings, string $rootRelativePath = ''): string
{
    if (!empty($settings['site_logo'])) {
        return $rootRelativePath . 'uploads/site/' . rawurlencode($settings['site_logo']);
    }

    return $rootRelativePath . 'assets/logo2.png';
    }
}

if (!function_exists('site_config_barangay_logo_url')) {
    /**
     * Barangay Logo - falls back to assets/sumacabLogo.jpg when none uploaded.
     */
    function site_config_barangay_logo_url(array $settings, string $rootRelativePath = ''): string
    {
        if (!empty($settings['barangay_logo'])) {
            return $rootRelativePath . 'uploads/site/' . rawurlencode($settings['barangay_logo']);
        }

        return $rootRelativePath . 'assets/sumacabLogo.jpg';
    }
}

if (!function_exists('site_config_municipality_logo_url')) {
    /**
     * Municipality Logo - falls back to assets/cabanatuan.png when none uploaded.
     */
    function site_config_municipality_logo_url(array $settings, string $rootRelativePath = ''): string
    {
        if (!empty($settings['municipality_logo'])) {
            return $rootRelativePath . 'uploads/site/' . rawurlencode($settings['municipality_logo']);
        }

        return $rootRelativePath . 'assets/cabanatuan.png';
    }
}

if (!function_exists('site_config_hex_to_rgb')) {
    function site_config_hex_to_rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '21,128,61'; // fallback: matches default #15803d
        }
        return implode(',', [
            hexdec(substr($hex, 0, 2)), 
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ]);
    }
}

if (!function_exists('site_config_css_vars')) {
    function site_config_css_vars(array $settings): string
    {
        $primary = $settings['color_theme'] ?: '#15803d';
        $rgb = site_config_hex_to_rgb($primary);
        return "<style>\n:root {\n"
            . "  --site-primary: {$primary};\n"
            . "  --site-primary-rgb: {$rgb};\n"
            . "  --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);\n"
            . "  --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);\n"
            . "  --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);\n"
            . "  --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);\n"
            . "}\n</style>";
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
