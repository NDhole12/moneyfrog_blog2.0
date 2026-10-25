<?php

namespace MoneyfrogCore\Cli;

/**
 * Turns locally downloaded files into attachments. Reads only from the local
 * media directory; makes no network calls. Each image is re-encoded through the
 * WordPress image editor and written at its ORIGINAL upload path, so old file
 * URLs keep working on the new site. Non-images are copied as-is.
 */
final class MediaImporter
{
    private string $mediaDir;
    private string $uploadsBase;
    private string $uploadsUrl;

    public function __construct(string $mediaDir)
    {
        $real = $mediaDir !== '' ? realpath($mediaDir) : false;
        $this->mediaDir = $real !== false ? $real : '';
        $uploads = wp_upload_dir(null, false);
        $this->uploadsBase = rtrim((string) $uploads['basedir'], '/');
        $this->uploadsUrl = rtrim((string) $uploads['baseurl'], '/');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // Keep the original path: no "-scaled" replacement for very large images.
        add_filter('big_image_size_threshold', '__return_false');
    }

    /** New URL the file will be served from (deterministic, no attachment needed). */
    public function urlFor(string $uploadPath): string
    {
        return Importer::uploadUrl($this->uploadsUrl, $uploadPath);
    }

    /**
     * @param array<string,mixed> $media bundle entry
     * @return array{status:string,ok:bool,attachmentId:?int,detail:string,url:?string}
     */
    public function import(array $media, bool $dryRun): array
    {
        $oldId = (int) ($media['oldId'] ?? 0);
        $rel = Importer::safeUploadPath((string) ($media['uploadPath'] ?? ''));
        if ($rel === null) {
            return $this->result('failed', false, null, 'unsafe upload path', null);
        }
        if (!Importer::isAllowedMediaExtension($rel)) {
            return $this->result('failed', false, null, 'blocked-extension', null);
        }
        $source = $this->sourceFile($rel);
        if ($source === null) {
            return $this->result('skipped', false, null, 'no local file (missing-media)', null);
        }
        $blocked = $this->blockReason($rel, $source);
        if ($blocked !== null) {
            return $this->result('failed', false, null, $blocked, null);
        }
        $url = $this->urlFor($rel);
        $dest = $this->uploadsBase . '/' . $rel;
        $alt = (string) ($media['alt'] ?? '');
        $existing = $this->findAttachment($oldId);

        if ($dryRun) {
            return $this->result($existing ? 'skipped' : 'created', true, $existing, 'dry-run', $url);
        }
        if ($existing !== null) {
            return $this->refresh($existing, $source, $dest, $alt, $url);
        }
        $written = $this->writeFile($source, $dest);
        if ($written === null) {
            return $this->result('failed', false, null, 'could not write ' . $rel, null);
        }
        $mime = (string) (wp_check_filetype($dest)['type'] ?: 'application/octet-stream');
        $id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title' => pathinfo($rel, PATHINFO_FILENAME),
            'post_name' => 'mf-media-' . $oldId,
            'post_status' => 'inherit',
            'post_content' => '',
        ], $dest, 0, true);
        if (is_wp_error($id)) {
            return $this->result('failed', false, null, $id->get_error_message(), null);
        }
        update_post_meta((int) $id, '_mf_old_media_id', $oldId);
        wp_update_attachment_metadata((int) $id, wp_generate_attachment_metadata((int) $id, $dest));
        if ($alt !== '') {
            update_post_meta((int) $id, '_wp_attachment_image_alt', wp_slash($alt));
        }
        return $this->result('created', true, (int) $id, $written, $url);
    }

    /** @return array{status:string,ok:bool,attachmentId:?int,detail:string,url:?string} */
    private function refresh(int $id, string $source, string $dest, string $alt, string $url): array
    {
        $changed = false;
        $detail = 'unchanged';
        if (!is_file($dest)) {
            $written = $this->writeFile($source, $dest);
            if ($written === null) {
                return $this->result('failed', false, $id, 'could not restore file', null);
            }
            wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $dest));
            $changed = true;
            $detail = 'file restored';
        }
        if ((string) get_post_meta($id, '_wp_attachment_image_alt', true) !== $alt) {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
            $changed = true;
            $detail = 'alt updated';
        }
        return $this->result($changed ? 'updated' : 'skipped', true, $id, $detail, $url);
    }

    /** Why a file must not enter uploads, or null. */
    private function blockReason(string $rel, string $source): ?string
    {
        if (!Importer::isAllowedMediaExtension($rel)) {
            return 'blocked-extension';
        }
        $handle = fopen($source, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 16);
        if ($handle !== false) {
            fclose($handle);
        }
        return Importer::magicMatches($rel, $head) ? null : 'bad-content';
    }

    /** Absolute path of the downloaded file when it exists inside the media dir. */
    private function sourceFile(string $rel): ?string
    {
        if ($this->mediaDir === '') {
            return null;
        }
        $real = realpath($this->mediaDir . '/' . $rel);
        if ($real === false || !is_file($real) || filesize($real) === 0) {
            return null;
        }
        $root = $this->mediaDir . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $root) ? $real : null;
    }

    /** Re-encode images; copy anything else. Returns a log note, or null on failure. */
    private function writeFile(string $source, string $dest): ?string
    {
        if (!wp_mkdir_p(dirname($dest))) {
            return null;
        }
        if (wp_get_image_mime($source) !== false) {
            $editor = wp_get_image_editor($source);
            if (!is_wp_error($editor)) {
                $saved = $editor->save($dest);
                if (!is_wp_error($saved) && is_file($dest)) {
                    return 're-encoded';
                }
            }
        }
        // Not an image the editor can handle (pdf, ico, ...): copied unchanged, and logged as such.
        return copy($source, $dest) ? 'copied-as-is' : null;
    }

    private function findAttachment(int $oldId): ?int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'meta_key' => '_mf_old_media_id',
            'meta_value' => $oldId,
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);
        return $ids === [] ? null : (int) $ids[0];
    }

    /** @return array{status:string,ok:bool,attachmentId:?int,detail:string,url:?string} */
    private function result(string $status, bool $ok, ?int $id, string $detail, ?string $url): array
    {
        return ['status' => $status, 'ok' => $ok, 'attachmentId' => $id, 'detail' => $detail, 'url' => $url];
    }
}
