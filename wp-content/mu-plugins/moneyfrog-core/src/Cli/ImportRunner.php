<?php

namespace MoneyfrogCore\Cli;

use MoneyfrogCore\Byline;
use MoneyfrogCore\Config;
use MoneyfrogCore\ServiceUser;

/**
 * Idempotent bundle import. Upsert keys: posts by meta `_mf_old_id` + `_mf_old_type`,
 * media by `_mf_old_media_id`, bylines and topics by term slug. Running it again
 * updates in place and never duplicates.
 */
final class ImportRunner
{
    private const HASH_META = '_mf_import_hash';
    private const HASH_VERSION = '2';

    /** @var array<string,array<string,int>> */
    private array $counts;
    /** @var list<array<string,mixed>> */
    private array $events = [];
    private int $authorId = 0;
    /** @var array<int,array{ok:bool,attachmentId:?int,url:?string}> */
    private array $mediaState = [];
    /** @var array<string,string> old upload URL => new URL, for media that is available */
    private array $urlMap = [];

    public function __construct(
        private readonly MediaImporter $media,
        private readonly bool $dryRun = false,
        private readonly ?int $only = null,
    ) {
        $zero = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $this->counts = ['posts' => $zero, 'media' => $zero, 'bylines' => $zero, 'topics' => $zero, 'failed-image' => ['count' => 0], 'missing-featured' => ['count' => 0]];
    }

    /**
     * @param array<string,mixed> $bundle
     * @return array{counts:array<string,array<string,int>>,events:list<array<string,mixed>>,dryRun:bool}
     */
    public function run(array $bundle): array
    {
        $this->quietenSideEffects();
        wp_defer_term_counting(true);
        $items = array_values(array_filter(
            (array) ($bundle['items'] ?? []),
            fn ($i) => $this->only === null || (int) ($i['oldId'] ?? 0) === $this->only
        ));

        try {
            $this->authorId = $this->dryRun ? 0 : ServiceUser::ensure();
            $this->importTopics();
        } catch (\Throwable $e) {
            $this->bump('topics', 'failed');
            $this->log('failed-setup', null, $this->safeMessage($e));
        }
        $this->importBylines((array) ($bundle['bylines'] ?? []));
        $this->importMedia($this->mediaFor((array) ($bundle['media'] ?? []), $items));
        foreach ($items as $item) {
            try {
                $this->importItem((array) $item);
            } catch (\Throwable $e) {
                $this->bump('posts', 'failed');
                $this->log('failed', (int) (((array) $item)['oldId'] ?? 0), $this->safeMessage($e));
            }
        }

        wp_defer_term_counting(false);
        return ['counts' => $this->counts, 'events' => $this->events, 'dryRun' => $this->dryRun];
    }

    /** The importer must never ping, enclose or otherwise reach out while publishing. */
    private function quietenSideEffects(): void
    {
        remove_action('do_pings', 'do_all_pings');
        remove_action('publish_post', '_publish_post_hook', 5);
        add_filter('pre_option_ping_sites', '__return_empty_string');
        // Bundle HTML was sanitised in the build step; core's save-time kses would strip it again.
        kses_remove_filters();
    }

    private function importTopics(): void
    {
        foreach (Config::TOPICS as $slug => $name) {
            if (term_exists($slug, 'category')) {
                $this->bump('topics', 'skipped');
                continue;
            }
            if ($this->dryRun) {
                $this->bump('topics', 'created');
                continue;
            }
            $res = wp_insert_term($name, 'category', ['slug' => $slug]);
            $this->bump('topics', is_wp_error($res) ? 'failed' : 'created');
            if (is_wp_error($res)) {
                $this->log('failed-topic', null, $slug . ': ' . $res->get_error_message());
            }
        }
    }

    /** @param list<array<string,mixed>> $bylines */
    private function importBylines(array $bylines): void
    {
        foreach ($bylines as $b) {
            try {
                $this->importByline((array) $b);
            } catch (\Throwable $e) {
                $this->bump('bylines', 'failed');
                $this->log('failed-byline', null, $this->safeMessage($e));
            }
        }
    }

    /** @param array<string,mixed> $b */
    private function importByline(array $b): void
    {
        $slug = (string) ($b['slug'] ?? '');
        $name = (string) ($b['name'] ?? '');
        if ($slug === '' || $name === '') {
            $this->bump('bylines', 'failed');
            $this->log('failed-byline', null, 'byline without slug or name');
            return;
        }
        $bio = (string) ($b['bio'] ?? '');
        $role = (string) ($b['role'] ?? '');
        $term = get_term_by('slug', $slug, Byline::TAXONOMY);
        if ($term instanceof \WP_Term) {
            $same = $term->name === $name
                && (string) get_term_meta($term->term_id, 'mf_bio', true) === $bio
                && (string) get_term_meta($term->term_id, 'mf_role', true) === $role;
            if ($same || $this->dryRun) {
                $this->bump('bylines', $same ? 'skipped' : 'updated');
                return;
            }
            wp_update_term($term->term_id, Byline::TAXONOMY, ['name' => $name]);
            $this->saveBylineMeta((int) $term->term_id, $bio, $role);
            $this->bump('bylines', 'updated');
            return;
        }
        if ($this->dryRun) {
            $this->bump('bylines', 'created');
            return;
        }
        $res = wp_insert_term($name, Byline::TAXONOMY, ['slug' => $slug]);
        if (is_wp_error($res)) {
            $this->bump('bylines', 'failed');
            $this->log('failed-byline', null, $slug . ': ' . $res->get_error_message());
            return;
        }
        $this->saveBylineMeta((int) $res['term_id'], $bio, $role);
        $this->bump('bylines', 'created');
    }

    private function saveBylineMeta(int $termId, string $bio, string $role): void
    {
        update_term_meta($termId, 'mf_bio', $bio);
        update_term_meta($termId, 'mf_role', $role);
    }

    /**
     * With --only, restrict media to what that post uses.
     *
     * @param list<array<string,mixed>> $media
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function mediaFor(array $media, array $items): array
    {
        if ($this->only === null) {
            return $media;
        }
        $haystack = '';
        $featured = [];
        foreach ($items as $item) {
            $haystack .= rawurldecode(html_entity_decode((string) ($item['html'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!empty($item['featuredMediaOldId'])) {
                $featured[(int) $item['featuredMediaOldId']] = true;
            }
        }
        return array_values(array_filter($media, static function ($m) use ($haystack, $featured): bool {
            $stem = preg_replace('/\.[A-Za-z0-9]+$/', '', (string) ($m['uploadPath'] ?? ''));
            return isset($featured[(int) ($m['oldId'] ?? 0)]) || ($stem !== '' && str_contains($haystack, (string) $stem));
        }));
    }

    /** @param list<array<string,mixed>> $media */
    private function importMedia(array $media): void
    {
        foreach ($media as $m) {
            try {
                $this->importOneMedia((array) $m);
            } catch (\Throwable $e) {
                $this->bump('media', 'failed');
                $this->log('failed-media', (int) (((array) $m)['oldId'] ?? 0), $this->safeMessage($e));
            }
        }
    }

    /** @param array<string,mixed> $m */
    private function importOneMedia(array $m): void
    {
        {
            $oldId = (int) ($m['oldId'] ?? 0);
            $res = $this->media->import($m, $this->dryRun);
            $this->bump('media', $res['status']);
            $this->mediaState[$oldId] = ['ok' => $res['ok'], 'attachmentId' => $res['attachmentId'], 'url' => $res['url']];
            if ($res['ok'] && $res['url'] !== null) {
                $this->urlMap[Importer::OLD_UPLOADS_URL . (string) $m['uploadPath']] = $res['url'];
            }
            if ($res['status'] === 'failed') {
                $blocked = in_array($res['detail'], ['blocked-extension', 'unsafe-svg'], true);
                $this->log($blocked ? $res['detail'] : 'failed-media', $oldId, $blocked ? (string) ($m['uploadPath'] ?? '') : $res['detail']);
            } elseif (!$res['ok']) {
                $this->log('missing-media', $oldId, (string) ($m['uploadPath'] ?? ''));
            } elseif (in_array($res['detail'], ['copied-as-is'], true)) {
                $this->log('copied-as-is', $oldId, (string) ($m['uploadPath'] ?? ''));
            }
        }
    }

    /** @param array<string,mixed> $item */
    private function importItem(array $item): void
    {
        $oldId = (int) ($item['oldId'] ?? 0);
        $oldType = (string) ($item['oldType'] ?? 'post');
        $slug = (string) ($item['newSlug'] ?? '');
        if ($oldId === 0 || $slug === '' || (string) ($item['title'] ?? '') === '') {
            $this->bump('posts', 'failed');
            $this->log('failed', $oldId, 'item without id, slug or title');
            return;
        }
        foreach (['title', 'html', 'excerpt', 'newSlug', 'topic', 'byline', 'date', 'modified'] as $field) {
            if (isset($item[$field]) && !is_scalar($item[$field])) {
                throw new \InvalidArgumentException('field "' . $field . '" is not text');
            }
        }
        $date = Importer::normalizeDate((string) ($item['date'] ?? ''));
        if ($date === null) {
            throw new \RuntimeException('missing or invalid date');
        }
        $modifiedRaw = (string) ($item['modified'] ?? '');
        $modified = $modifiedRaw === '' ? $date : Importer::normalizeDate($modifiedRaw);
        if ($modified === null) {
            throw new \RuntimeException('invalid modified date');
        }
        $topic = get_term_by('slug', (string) ($item['topic'] ?? ''), 'category');
        $byline = get_term_by('slug', (string) ($item['byline'] ?? ''), Byline::TAXONOMY);
        if (!$this->dryRun && (!$topic instanceof \WP_Term || !$byline instanceof \WP_Term)) {
            $this->bump('posts', 'failed');
            $this->log('failed', $oldId, 'unknown topic or byline: ' . ($item['topic'] ?? '') . ' / ' . ($item['byline'] ?? ''));
            return;
        }

        $rewritten = Importer::rewriteContent((string) ($item['html'] ?? ''), $this->urlMap);
        $rewritten['html'] = Importer::cleanHtml($rewritten['html']);
        foreach ($rewritten['failedImages'] as $url) {
            $this->counts['failed-image']['count']++;
            $this->log('failed-image', $oldId, $url);
        }

        $featuredOld = isset($item['featuredMediaOldId']) ? (int) $item['featuredMediaOldId'] : 0;
        $thumbId = null;
        if ($featuredOld > 0) {
            $state = $this->mediaState[$featuredOld] ?? null;
            if ($state !== null && $state['ok']) {
                $thumbId = $state['attachmentId'];
            } else {
                $this->counts['missing-featured']['count']++;
                $this->log('missing-featured', $oldId, 'featured media ' . $featuredOld . ' unavailable');
            }
        }

        $tags = array_values(array_map('strval', (array) ($item['tags'] ?? [])));
        $hash = Importer::contentHash([
            'v' => self::HASH_VERSION, 'tz' => (string) get_option('timezone_string') . '|' . (string) get_option('gmt_offset'),
            'title' => (string) $item['title'], 'content' => $rewritten['html'], 'excerpt' => (string) ($item['excerpt'] ?? ''),
            'slug' => $slug, 'date' => $date, 'modified' => $modified, 'topic' => (string) ($item['topic'] ?? ''),
            'tags' => $tags, 'byline' => (string) ($item['byline'] ?? ''), 'featured' => $featuredOld, 'thumb' => $thumbId,
        ]);

        $existing = $this->findPost($oldId, $oldType);
        if ($existing !== null && (string) get_post_meta($existing, self::HASH_META, true) === $hash
            && (int) get_post_thumbnail_id($existing) === (int) $thumbId) {
            $this->bump('posts', 'skipped');
            return;
        }
        $owner = $this->slugOwner($slug, $existing);
        if ($owner !== null) {
            $this->bump('posts', 'failed');
            $this->log('slug-taken', $oldId, 'slug "' . $slug . '" is owned by post ' . $owner);
            return;
        }
        if ($this->dryRun) {
            $this->bump('posts', $existing === null ? 'created' : 'updated');
            return;
        }

        $postId = wp_insert_post(wp_slash([
            'ID' => $existing ?? 0,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => (string) $item['title'],
            'post_content' => $rewritten['html'],
            'post_excerpt' => (string) ($item['excerpt'] ?? ''),
            'post_name' => $slug,
            'post_date' => $date,
            'post_date_gmt' => get_gmt_from_date($date),
            'post_author' => $this->authorId,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'meta_input' => ['_mf_old_id' => $oldId, '_mf_old_type' => $oldType],
        ]), true);
        if (is_wp_error($postId) || (int) $postId === 0) {
            $this->bump('posts', 'failed');
            $this->log('failed', $oldId, is_wp_error($postId) ? $postId->get_error_message() : 'insert returned 0');
            return;
        }
        $postId = (int) $postId;
        if ((string) get_post_field('post_name', $postId) !== $slug) {
            $this->bump('posts', 'failed');
            $this->log('failed', $oldId, 'slug taken, wanted "' . $slug . '" got "' . get_post_field('post_name', $postId) . '"');
            return;
        }

        wp_set_object_terms($postId, [(int) $topic->term_id], 'category');
        wp_set_object_terms($postId, $tags, 'post_tag');
        wp_set_object_terms($postId, [(int) $byline->term_id], Byline::TAXONOMY);
        if ($thumbId !== null) {
            set_post_thumbnail($postId, $thumbId);
        }
        $this->restoreModified($postId, $modified);
        update_post_meta($postId, self::HASH_META, $hash);
        $this->bump('posts', $existing === null ? 'created' : 'updated');
    }

    private function findPost(int $oldId, string $oldType): ?int
    {
        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'],
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_mf_old_id', 'value' => $oldId],
                ['key' => '_mf_old_type', 'value' => $oldType],
            ],
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        return $ids === [] ? null : (int) $ids[0];
    }

    /** WordPress overwrites post_modified on every save; put the bundle's value back. */
    private function restoreModified(int $postId, string $modified): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_modified' => $modified, 'post_modified_gmt' => get_gmt_from_date($modified)],
            ['ID' => $postId]
        );
        clean_post_cache($postId);
    }

    /** ID of another post that owns the slug (0 when the slug is only reserved), else null. */
    private function slugOwner(string $slug, ?int $existing): ?int
    {
        global $wpdb;
        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post' AND post_status <> 'auto-draft' AND ID <> %d LIMIT 1",
            $slug,
            (int) $existing
        ));
        if ($owner > 0) {
            return $owner;
        }
        return wp_unique_post_slug($slug, (int) $existing, 'publish', 'post', 0) === $slug ? null : 0;
    }

    /** Message only: no stack trace, no file paths. */
    private function safeMessage(\Throwable $e): string
    {
        return substr(str_replace([ABSPATH, "\n"], ['', ' '], $e->getMessage()), 0, 300);
    }

    private function bump(string $group, string $key): void
    {
        $key = isset($this->counts[$group][$key]) ? $key : 'failed';
        $this->counts[$group][$key]++;
    }

    private function log(string $type, ?int $oldId, string $detail): void
    {
        $this->events[] = ['type' => $type, 'oldId' => $oldId, 'detail' => $detail];
    }
}
