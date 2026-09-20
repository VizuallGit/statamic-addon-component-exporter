<?php

namespace Vizuall\ComponentExporter\Import;

use MarioHamann\StatamicVisualEditor\PreviewPartials;
use MarioHamann\StatamicVisualEditor\SetPreviewImages;
use MarioHamann\StatamicVisualEditor\TailwindStore;
use Statamic\Facades\Blink;
use Statamic\Facades\Git;
use Statamic\Facades\StaticCache;
use Vizuall\ComponentExporter\Export\Extras;
use Vizuall\ComponentExporter\Section\Paths;
use Vizuall\ComponentExporter\Section\Previews;
use Vizuall\ComponentExporter\Section\Registry;

/**
 * Writes what the editor chose from a package, and nothing else.
 *
 * Files are written only under the folders a section can legitimately live
 * in — fieldsets, views, the Tailwind store, blueprints, collection config,
 * the preview folder — and never above the site root. Chosen sections are
 * merged into the registry, group and all. Afterwards the caches that would
 * otherwise keep showing the old site are dropped, and when the site commits
 * its own edits to git, this commit goes the same way.
 */
final class Importer
{
    /**
     * @param  array{files?: array<string, bool>, sections?: list<string>}  $choices
     * @return array{written: list<string>, skipped: list<string>, registered: list<string>, rejected: list<string>}
     *
     * @throws \InvalidArgumentException when the file is not a readable ZIP
     */
    public static function apply(string $zipPath, array $choices): array
    {
        $zip = Inspector::open($zipPath);
        $manifest = Inspector::manifest($zip);

        $written = [];
        $skipped = [];
        $rejected = [];
        $registered = [];

        foreach ((array) ($choices['files'] ?? []) as $path => $wanted) {
            $path = str_replace('\\', '/', (string) $path);

            if (! $wanted) {
                $skipped[] = $path;

                continue;
            }

            if (! static::isWritable($path)) {
                $rejected[] = $path;

                continue;
            }

            $contents = $zip->getFromName($path);

            if ($contents === false) {
                $rejected[] = $path;

                continue;
            }

            $absolute = Paths::absolute($path);
            $directory = dirname($absolute);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $rejected[] = $path;

                continue;
            }

            if (@file_put_contents($absolute, $contents) === false) {
                $rejected[] = $path;

                continue;
            }

            $written[] = $path;
        }

        $zip->close();

        if ($manifest !== null) {
            $byHandle = [];

            foreach ($manifest['sections'] as $section) {
                $byHandle[(string) $section['handle']] = $section;
            }

            foreach ((array) ($choices['sections'] ?? []) as $handle) {
                if (! isset($byHandle[$handle]) || ! is_array($byHandle[$handle]['set'] ?? null)) {
                    continue;
                }

                $section = $byHandle[$handle];

                Registry::merge(
                    (string) ($section['group'] ?: 'sections'),
                    $section['group_display'] ?? null,
                    (string) $handle,
                    $section['set']
                );

                $registered[] = (string) $handle;
            }
        }

        if ($written || $registered) {
            static::refresh($written, $registered);
        }

        return compact('written', 'skipped', 'registered', 'rejected');
    }

    /** The folders a package may write into, site-relative with a trailing slash. */
    public static function allowedRoots(): array
    {
        $roots = [
            'resources/fieldsets/',
            'resources/views/',
            rtrim(Paths::relative(TailwindStore::directory()), '/').'/',
        ];

        if ($previews = Previews::relativeDirectory()) {
            $roots[] = rtrim($previews, '/').'/';
        }

        return $roots;
    }

    public static function isWritable(string $path): bool
    {
        return Paths::isAllowed($path, static::allowedRoots()) || Extras::isAllowedPath($path);
    }

    /**
     * @param  list<string>  $written
     * @param  list<string>  $registered
     */
    private static function refresh(array $written, array $registered): void
    {
        Blink::flush();
        PreviewPartials::flush();
        SetPreviewImages::flush();

        if (config('statamic.static_caching.strategy')) {
            try {
                StaticCache::flush();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (config('statamic.git.enabled') && config('statamic.git.automatic')) {
            try {
                Git::dispatchCommit(sprintf(
                    'Komponent Eksport: importerede %d fil(er)%s',
                    count($written),
                    $registered ? ', registrerede '.implode(', ', $registered) : ''
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
