<?php
/**
 * Display label for a tag. Pure (no WordPress calls) so it is unit-tested directly.
 *
 * Imported tags often store their slug as the name ("elss-tax-saving-funds"); those are
 * humanised: hyphens become spaces, the first letter is capitalised and known finance
 * acronyms are upper-cased. Properly written names ("SIP", "Tax saving") pass through.
 */

if (!function_exists('mf_tag_label')) {
    function mf_tag_label(string $name, string $slug): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, ' ') || ($name !== $slug && !str_contains($name, '-'))) {
            return $name !== '' ? $name : $slug;
        }
        if ($name !== $slug && strtolower($name) !== strtolower($slug)) {
            return $name;
        }
        $acronyms = ['sip', 'elss', 'nfo', 'nps', 'ppf', 'fd', 'etf', 'pms', 'gst', 'rbi', 'ipo'];
        $words    = array_map(
            static fn (string $w): string => in_array(strtolower($w), $acronyms, true) ? strtoupper($w) : $w,
            explode('-', $name)
        );
        $label = implode(' ', $words);
        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }
}
