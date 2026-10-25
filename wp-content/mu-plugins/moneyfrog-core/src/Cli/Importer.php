<?php

namespace MoneyfrogCore\Cli;

/**
 * Pure helpers for `wp mf import`: URL and path handling, content rewriting and
 * the change-detection hash. Makes no WordPress calls and no network calls.
 */
final class Importer
{
    public const OLD_HOST = 'blog.moneyfrog.in';
    public const OLD_UPLOADS_URL = 'https://blog.moneyfrog.in/wp-content/uploads/';
    private const UPLOADS_PREFIX = '/wp-content/uploads/';

    /**
     * Normalise an upload path from a bundle. Returns null for anything that could
     * leave the uploads folder (parent segments, absolute or drive paths, NUL).
     */
    public static function safeUploadPath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        return $path;
    }

    /** New public URL for an upload: base URL plus the same path, each segment percent-encoded. */
    public static function uploadUrl(string $uploadsBaseUrl, string $uploadPath): string
    {
        return rtrim($uploadsBaseUrl, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $uploadPath)));
    }

    /** Stable hash of an import payload (key order does not matter). */
    public static function contentHash(array $payload): string
    {
        return sha1((string) json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Rewrite old-blog image and file URLs. $oldToNew maps old URLs to new URLs.
     *
     * @param array<string,string> $oldToNew
     */
    public static function rewriteImageUrls(string $html, array $oldToNew): string
    {
        return self::rewriteContent($html, $oldToNew)['html'];
    }

    /**
     * Full content pass: images (src/srcset, size variants; failed or off-host
     * sources removed), links to uploaded files, and absolute old-blog post
     * links turned into relative paths.
     *
     * @param array<string,string> $oldToNew
     * @return array{html:string,failedImages:list<string>}
     */
    public static function rewriteContent(string $html, array $oldToNew): array
    {
        $index = [];
        foreach ($oldToNew as $old => $new) {
            $key = self::uploadKey((string) $old);
            if ($key !== null) {
                $index[$key] = (string) $new;
            }
        }
        $failed = [];
        $html = (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $m) use ($index, &$failed): string {
                return self::processImg($m[0], $index, $failed);
            },
            $html
        );
        if ($failed !== []) {
            $html = self::dropEmptyWrappers($html);
        }
        $html = (string) preg_replace_callback(
            '/<a\b[^>]*>/i',
            static fn (array $m): string => self::processAnchor($m[0], $index),
            $html
        );
        return ['html' => $html, 'failedImages' => $failed];
    }

    public const YOUTUBE_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com'];
    public const IFRAME_ATTRS = ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'frameborder'];
    public const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'ico'];

    /** Only these extensions may be written into uploads. */
    public static function isAllowedMediaExtension(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MEDIA_EXTENSIONS, true);
    }

    /** An SVG is copied only when it carries no script, event handlers or script URLs. */
    public static function isSafeSvg(string $svg): bool
    {
        if (stripos($svg, '<svg') === false) {
            return false;
        }
        return preg_match('/<script|<foreignObject|<!ENTITY|javascript:|\bon[a-z]+\s*=/i', $svg) !== 1;
    }

    /** True when the site clock is IST (+5:30), the old blog's offset. */
    public static function isIndiaOffset(string $timezoneString, mixed $gmtOffset): bool
    {
        if ($timezoneString !== '') {
            return in_array($timezoneString, ['Asia/Kolkata', 'Asia/Calcutta'], true);
        }
        return abs((float) $gmtOffset - 5.5) < 0.0001;
    }

    /** "2025-04-11T14:59:37" -> "2025-04-11 14:59:37"; null for empty or impossible dates. */
    public static function normalizeDate(string $iso): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $iso, $m) !== 1) {
            return null;
        }
        $value = $m[1] . ' ' . $m[2];
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        return ($dt !== false && $dt->format('Y-m-d H:i:s') === $value) ? $value : null;
    }

    /**
     * Allow-list clean-up of bundle HTML before saving: no script, no on* handlers, no
     * javascript: URLs, only YouTube iframes with a fixed attribute set. Runs wp_kses with
     * the "post" tag set (plus that iframe) when WordPress is loaded.
     */
    public static function cleanHtml(string $html): string
    {
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $html);
        $html = (string) preg_replace('#</?script\b[^>]*>#i', '', $html);
        $html = (string) preg_replace_callback('/<[a-zA-Z][^>]*>/', static fn (array $m): string => self::cleanTag($m[0]), $html);
        $html = (string) preg_replace_callback('#<iframe\b[^>]*>(?:.*?</iframe\s*>)?#is', static fn (array $m): string => self::cleanIframe($m[0]), $html);
        if (function_exists('wp_kses') && function_exists('wp_kses_allowed_html')) {
            $allowed = wp_kses_allowed_html('post');
            $allowed['iframe'] = array_fill_keys(self::IFRAME_ATTRS, true);
            $html = wp_kses($html, $allowed);
        }
        return $html;
    }

    private static function cleanTag(string $tag): string
    {
        $tag = (string) preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        return (string) preg_replace_callback(
            '/\s(?:href|src|xlink:href|action|formaction)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i',
            static function (array $m): string {
                $value = html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
                $bad = str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:')
                    || (str_starts_with($value, 'data:') && !str_starts_with($value, 'data:image/'));
                return $bad ? '' : $m[0];
            },
            $tag
        );
    }

    private static function cleanIframe(string $block): string
    {
        if (preg_match('/^<iframe\b([^>]*)>/i', $block, $open) !== 1) {
            return '';
        }
        preg_match_all('/([a-zA-Z][a-zA-Z-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $open[1], $all, PREG_SET_ORDER);
        $attrs = [];
        foreach ($all as $a) {
            $name = strtolower($a[1]);
            if (in_array($name, self::IFRAME_ATTRS, true)) {
                $attrs[$name] = html_entity_decode($a[2] ?? ($a[3] ?? ($a[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $src = trim($attrs['src'] ?? '');
        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }
        $parts = parse_url($src);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), self::YOUTUBE_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $out = '<iframe';
        foreach ($attrs as $name => $value) {
            $out .= $value === '' && $name !== 'src' ? ' ' . $name : ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $out . '></iframe>';
    }

    /** "2019/01/x-300x200.jpg" lookup key for a URL on the old host, else null. */
    public static function uploadKey(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('#^(?:https?:)?//([^/?\#]+)(/[^?\#]*)?#i', $url, $m) === 1) {
            if (strtolower($m[1]) !== self::OLD_HOST) {
                return null;
            }
            $path = $m[2] ?? '';
        } elseif (str_starts_with($url, '/')) {
            $path = (string) preg_replace('/[?#].*$/s', '', $url);
        } else {
            return null;
        }
        $path = rawurldecode($path);
        if (!str_starts_with($path, self::UPLOADS_PREFIX) || strlen($path) === strlen(self::UPLOADS_PREFIX)) {
            return null;
        }
        return substr($path, strlen(self::UPLOADS_PREFIX));
    }

    /** @param array<string,string> $index */
    private static function lookup(array $index, string $key): ?string
    {
        if (isset($index[$key])) {
            return $index[$key];
        }
        $full = (string) preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $key);
        return $index[$full] ?? null;
    }

    /**
     * @param array<string,string> $index
     * @param list<string> $failed
     */
    private static function processImg(string $tag, array $index, array &$failed): string
    {
        $src = self::getAttr($tag, 'src');
        if ($src !== null) {
            $decoded = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!str_starts_with($decoded, 'data:')) {
                $key = self::uploadKey($decoded);
                if ($key !== null) {
                    $new = self::lookup($index, $key);
                    if ($new === null) {
                        $failed[] = $decoded;
                        return '';
                    }
                    $tag = self::setAttr($tag, 'src', $new);
                } elseif (preg_match('#^(?:https?:)?//#i', $decoded) === 1) {
                    $failed[] = $decoded;
                    return '';
                }
            }
        }
        $tag = self::rewriteSrcset($tag, $index);
        return self::dropOldImageClass($tag);
    }

    /** @param array<string,string> $index */
    private static function rewriteSrcset(string $tag, array $index): string
    {
        $srcset = self::getAttr($tag, 'srcset');
        if ($srcset === null) {
            return $tag;
        }
        $entries = [];
        $list = preg_split('/\s*,\s*/', trim(html_entity_decode($srcset, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [];
        foreach ($list as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
            $key = ($parts[0] ?? '') !== '' ? self::uploadKey($parts[0]) : null;
            $new = $key !== null ? self::lookup($index, $key) : null;
            if ($new === null) {
                return self::removeAttr(self::removeAttr($tag, 'srcset'), 'sizes');
            }
            $entries[] = trim($new . ' ' . ($parts[1] ?? ''));
        }
        return self::setAttr($tag, 'srcset', implode(', ', $entries));
    }

    /** `wp-image-N` points at an attachment id of the old site; the new ids differ. */
    private static function dropOldImageClass(string $tag): string
    {
        $class = self::getAttr($tag, 'class');
        if ($class === null) {
            return $tag;
        }
        $kept = array_filter(
            preg_split('/\s+/', trim($class)) ?: [],
            static fn (string $c): bool => $c !== '' && preg_match('/^wp-image-\d+$/', $c) !== 1
        );
        return $kept === [] ? self::removeAttr($tag, 'class') : self::setAttr($tag, 'class', implode(' ', $kept));
    }

    /** @param array<string,string> $index */
    private static function processAnchor(string $tag, array $index): string
    {
        $href = self::getAttr($tag, 'href');
        if ($href === null) {
            return $tag;
        }
        $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $key = self::uploadKey($decoded);
        if ($key !== null) {
            $new = self::lookup($index, $key);
            return $new === null ? $tag : self::setAttr($tag, 'href', $new);
        }
        if (preg_match('#^(?:https?:)?//' . preg_quote(self::OLD_HOST, '#') . '(/[^\s]*)?$#i', $decoded, $m) === 1) {
            return self::setAttr($tag, 'href', ($m[1] ?? '') === '' ? '/' : $m[1]);
        }
        return $tag;
    }

    /** After a removed image, drop wrappers it leaves empty. */
    private static function dropEmptyWrappers(string $html): string
    {
        $patterns = ['#<a\b[^>]*>\s*</a>#i', '#<figure\b[^>]*>\s*</figure>#i', '#<p>\s*</p>#i'];
        foreach ($patterns as $pattern) {
            $html = (string) preg_replace($pattern, '', $html);
        }
        return $html;
    }

    private static function attrPattern(string $name): string
    {
        return '/(\s)' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
    }

    private static function getAttr(string $tag, string $name): ?string
    {
        if (preg_match(self::attrPattern($name), $tag, $m) !== 1) {
            return null;
        }
        return ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
    }

    private static function setAttr(string $tag, string $name, string $value): string
    {
        $attr = ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        $count = 0;
        $out = preg_replace_callback(self::attrPattern($name), static fn (): string => $attr, $tag, 1, $count);
        if ($count === 0) {
            return (string) preg_replace('#\s*/?>$#', $attr . '$0', $tag, 1);
        }
        return (string) $out;
    }

    private static function removeAttr(string $tag, string $name): string
    {
        return (string) preg_replace(self::attrPattern($name), '', $tag, 1);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'sortKeys'], $value);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
