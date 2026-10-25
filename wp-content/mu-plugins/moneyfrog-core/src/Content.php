<?php

namespace MoneyfrogCore;

/**
 * Pure content helpers (reading time, heading ids, table of contents, cover
 * phrase). Makes no WordPress calls.
 */
final class Content
{
    private const COVER_MAX = 22;

    public static function readingTime(string $html, int $wpm = 200): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return max(1, (int) ceil(count($words) / max(1, $wpm)));
    }

    /** Give every <h2> a stable, unique id (existing ids are kept). */
    public static function addHeadingIds(string $html): string
    {
        if (stripos($html, '<h2') === false) {
            return $html;
        }
        [$doc, $body] = self::load($html);
        self::assignIds($doc);
        return self::export($doc, $body);
    }

    /** @return list<array{id:string,text:string}> */
    public static function toc(string $html): array
    {
        if (stripos($html, '<h2') === false) {
            return [];
        }
        [$doc] = self::load($html);
        $items = self::assignIds($doc);
        return count($items) < 2 ? [] : $items;
    }

    public static function coverPhrase(string $title, string $topicName): string
    {
        $title = trim($title);
        $parts = preg_split('/[–-]|[:?]/u', $title, 2) ?: [$title];
        $head = trim($parts[0]);
        if ($head !== '' && mb_strlen($head) <= self::COVER_MAX) {
            return $head;
        }
        $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstThree = implode(' ', array_slice($words, 0, 3));
        if ($firstThree !== '' && mb_strlen($firstThree) <= self::COVER_MAX) {
            return $firstThree;
        }
        return $topicName;
    }

    /** @return array{0:\DOMDocument,1:\DOMElement} */
    private static function load(string $html): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        /** @var \DOMElement $body */
        $body = $doc->getElementsByTagName('body')->item(0);
        return [$doc, $body];
    }

    private static function export(\DOMDocument $doc, \DOMElement $body): string
    {
        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= $doc->saveHTML($node);
        }
        return $out;
    }

    /** @return list<array{id:string,text:string}> */
    private static function assignIds(\DOMDocument $doc): array
    {
        $used = [];
        foreach ($doc->getElementsByTagName('h2') as $h2) {
            if ($h2->getAttribute('id') !== '') {
                $used[$h2->getAttribute('id')] = true;
            }
        }
        $items = [];
        foreach ($doc->getElementsByTagName('h2') as $h2) {
            $text = trim(preg_replace('/\s+/u', ' ', $h2->textContent) ?? '');
            $id = $h2->getAttribute('id');
            if ($id === '') {
                $base = self::slugify($text);
                $id = $base;
                for ($n = 2; isset($used[$id]); $n++) {
                    $id = $base . '-' . $n;
                }
                $used[$id] = true;
                $h2->setAttribute('id', $id);
            }
            $items[] = ['id' => $id, 'text' => $text];
        }
        return $items;
    }

    private static function slugify(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        return $slug === '' ? 'section' : $slug;
    }
}
