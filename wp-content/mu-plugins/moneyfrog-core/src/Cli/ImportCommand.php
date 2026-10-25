<?php

namespace MoneyfrogCore\Cli;

/**
 * `wp mf import <bundle> --media-dir=<dir> [--dry-run] [--only=<oldId>]`
 *
 * Imports the migration bundle (posts, bylines, topics, media). Safe to re-run:
 * existing items are updated in place. Makes no network calls; images come from
 * the local --media-dir produced by migration/src/media.mjs.
 */
final class ImportCommand
{
    /**
     * Import a migration bundle.
     *
     * ## OPTIONS
     *
     * <bundle>
     * : Path to bundle.json.
     *
     * [--media-dir=<dir>]
     * : Folder holding the downloaded images (media.mjs output). Without it every image counts as missing.
     *
     * [--dry-run]
     * : Report what would happen; write nothing.
     *
     * [--only=<oldId>]
     * : Import a single item by its old id.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $bundlePath = (string) ($args[0] ?? '');
        if ($bundlePath === '' || !is_file($bundlePath)) {
            \WP_CLI::error('Bundle not found: ' . $bundlePath);
        }
        $bundle = json_decode((string) file_get_contents($bundlePath), true);
        if (!is_array($bundle) || !isset($bundle['items']) || !is_array($bundle['items'])) {
            \WP_CLI::error('Not a bundle: expected { items, bylines, media }.');
        }
        $mediaDir = (string) ($assoc['media-dir'] ?? '');
        if ($mediaDir === '' || !is_dir($mediaDir)) {
            \WP_CLI::warning('No readable --media-dir: every image will be reported missing.');
            $mediaDir = '';
        }
        $dryRun = !empty($assoc['dry-run']);
        $only = isset($assoc['only']) ? (int) $assoc['only'] : null;

        $runner = new ImportRunner(new MediaImporter($mediaDir), $dryRun, $only);
        $report = $runner->run($bundle);

        $this->printReport($report['counts'], $dryRun);
        $logPath = dirname($bundlePath) . '/' . ($dryRun ? 'import-log-dry-run.json' : 'import-log.json');
        file_put_contents($logPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        \WP_CLI::line('log: ' . $logPath);

        $failed = array_sum(array_map(static fn (array $c): int => $c['failed'] ?? 0, $report['counts']));
        if ($failed > 0) {
            \WP_CLI::error(sprintf('%d item(s) failed; see the log.', $failed));
        }
        \WP_CLI::success($dryRun ? 'Dry run finished.' : 'Import finished.');
    }

    /** @param array<string,array<string,int>> $counts */
    private function printReport(array $counts, bool $dryRun): void
    {
        if ($dryRun) {
            \WP_CLI::line('(dry run: nothing was written)');
        }
        foreach (['posts', 'media', 'bylines', 'topics'] as $group) {
            $c = $counts[$group];
            \WP_CLI::line(sprintf('%s: created=%d updated=%d skipped=%d failed=%d', $group, $c['created'], $c['updated'], $c['skipped'], $c['failed']));
        }
        \WP_CLI::line('failed-image: ' . $counts['failed-image']['count']);
        \WP_CLI::line('missing-featured: ' . $counts['missing-featured']['count']);
    }
}
