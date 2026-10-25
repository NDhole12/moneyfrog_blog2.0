<?php

namespace MoneyfrogCore;

/**
 * Post body filters. Heading ids are added on the single-post render only, so the
 * table of contents (computed by the template from the same filtered content) and
 * the anchors in the article always agree.
 */
final class ContentHooks
{
    /** After wpautop (10), shortcodes (11) and do_blocks (9) have run. */
    private const PRIORITY = 20;

    public static function register(): void
    {
        add_filter('the_content', [self::class, 'addHeadingIds'], self::PRIORITY);
    }

    public static function addHeadingIds(string $html): string
    {
        if (!is_singular('post') || !is_main_query() || !in_the_loop()) {
            return $html;
        }
        return Content::addHeadingIds($html);
    }
}
