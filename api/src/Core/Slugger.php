<?php

namespace App\Core;

/**
 * Slugger — turns a name into a URL-safe slug used by clean URLs
 * (SEO requirement 3.4). Keeps an explicit allowlist of characters so
 * the result is always cross-platform stable.
 */
final class Slugger
{
    private function __construct()
    {
    }

    /**
     * Normalizes $text into a lowercase, dash-separated slug.
     *
     * @param string $text     Raw input (e.g. a product name).
     * @param string $fallback Used when the result would be empty.
     *
     * @return string The slug.
     */
    public static function make(string $text, string $fallback = 'item'): string
    {
        // Transliterate accented characters (é -> e) via iconv, since the
        // deploy PHP build has no mbstring; then lowercase.
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?? $text;
        $slug = strtolower(trim($slug));

        // Replace any run of characters that are not latin alphanumerics or a
        // dash with a single dash, then trim stray dashes.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim((string) $slug, '-');

        return $slug === '' ? $fallback : $slug;
    }
}