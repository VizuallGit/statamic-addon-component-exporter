<?php

namespace Vizuall\ComponentExporter\Import;

use MarioHamann\StatamicVisualEditor\CollectionPresets;
use MarioHamann\StatamicVisualEditor\PreviewPartials;
use MarioHamann\StatamicVisualEditor\SetPreviewImages;
use MarioHamann\StatamicVisualEditor\TailwindStore;
use Statamic\Facades\Blink;
use Statamic\Facades\Git;
use Statamic\Facades\Stache;
use Statamic\Facades\StaticCache;
use Vizuall\ComponentExporter\Section\Paths;
use Vizuall\ComponentExporter\Section\Previews;
use Vizuall\ComponentExporter\Section\Registry;
use Vizuall\ComponentExporter\Theme\Tokens;
use Vizuall\ComponentExporter\Theme\Writer;

/**
 * Writes what the editor chose from a package, and nothing else.
 *
 * Files are written only where a section or unit can legitimately live —
 * fieldsets, views, the Tailwind store, blueprints, forms, collection and
 * global config, the Visual Editor's template entries and presets, the preview
 * folder — and never above the site root. Chosen sections are merged into the
 * registry, group and all. Afterwards the caches that would otherwise keep
 * showing the old site are dropped, and when the site commits its own edits to
 * git, this commit goes the same way.
 *
 * A section the editor renamed in the review is written under its new name:
 * its own files land where Rename moves them and it is registered under the
 * new handle. A rename that cannot be done stops the import before anything
 * is written — the files would otherwise land under the old name unasked.
 *
 * Chosen theme tokens are made in site.css — only the ones this site is
 * missing, never one it already has, however different its value (Theme\Writer).
 */
final class Importer
{
    /**
     * @param  array{files?: array<string, bool>, sections?: list<string>, tokens?: list<string>, renames?: array<string, array{handle?: string, display?: string}>}  $choices
     *   files and sections by the paths and handles in the package, whatever they are renamed to
     * @return array{written: list<string>, skipped: list<string>, registered: list<string>, rejected: list<string>, tokens: array}
     *
     * @throws \InvalidArgumentException when the file is not a readable ZIP, or a rename cannot be done
     */
    public static function apply(string $zipPath, array $choices): array
    {
        $zip = Inspector::open($zipPath);
        $manifest = Inspector::manifest($zip);
        $plans = [];

        if ($manifest !== null) {
            $plans = Rename::plans($manifest['sections'], (array) ($choices['renames'] ?? []), Inspector::usedBy($manifest), Inspector::reader($zip));

            foreach ($plans as $handle => $plan) {
                if ($plan['error'] !== null) {
                    $zip->close();

                    throw new \InvalidArgumentException($handle.': '.$plan['error']);
                }
            }
        }

        $targets = Rename::targets($plans);
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

            $target = $targets[$path] ?? $path;

            if (! static::isWritable($target)) {
                $rejected[] = $path;

                continue;
            }

            $contents = $zip->getFromName($path);

            if ($contents === false) {
                $rejected[] = $path;

                continue;
            }

            $absolute = Paths::absolute($target);
            $directory = dirname($absolute);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $rejected[] = $path;

                continue;
            }

            if (@file_put_contents($absolute, $contents) === false) {
                $rejected[] = $path;

                continue;
            }

            $written[] = $target;
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
                $plan = $plans[$handle];

                Registry::merge(
                    (string) ($section['group'] ?: 'sections'),
                    $section['group_display'] ?? null,
                    $plan['handle'],
                    $plan['set']
                );

                $registered[] = $plan['handle'];
            }
        }

        $tokens = static::makeTokens($manifest, (array) ($choices['tokens'] ?? []));

        if ($written || $registered || $tokens['written']) {
            static::refresh($written, $registered);
        }

        return compact('written', 'skipped', 'registered', 'rejected', 'tokens');
    }

    /**
     * Makes the chosen theme tokens.
     *
     * The package's list is held against this site again here, not trusted
     * from the review: only what is still missing can be made, so a colour
     * that arrived in the meantime is never written over.
     *
     * @param  list<string>  $names
     * @return array{written: list<string>, skipped: list<string>, reason: ?string, build: ?string}
     */
    private static function makeTokens(?array $manifest, array $names): array
    {
        $names = array_values(array_filter($names, 'is_string'));

        if ($manifest === null || ! $names) {
            return ['written' => [], 'skipped' => [], 'reason' => null, 'build' => null];
        }

        $makeable = array_values(array_filter(
            Inspector::tokens($manifest),
            fn ($token) => in_array($token['status'], [Tokens::MISSING, Tokens::STEP_MISSING], true)
        ));

        $result = Writer::add($makeable, $names);

        // The site's own stylesheet is built again for the same reason the
        // theme panel does it: `{{ theme_tokens }}` puts the new values on
        // :root at once, but a utility class that reads them is only in
        // public/build after a build. A server without node says so and the
        // token is still made.
        $build = null;

        if ($result['written'] && class_exists(\MarioHamann\StatamicVisualEditor\SiteBuild::class)) {
            $outcome = \MarioHamann\StatamicVisualEditor\SiteBuild::run();
            $build = ($outcome['ok'] ?? false) ? 'ok' : (string) ($outcome['reason'] ?? 'failed');
        }

        return $result + ['build' => $build];
    }

    /**
     * Whether a package path may be written here at all.
     *
     * Folders where anything goes (relative, no `..`, nothing hidden):
     * fieldsets, views, the Tailwind store, blueprints, forms, the Visual
     * Editor's collection presets, the preview folder. Content is narrower:
     * a collection's or global's own config file, and entries of the
     * collection the Visual Editor keeps view templates in.
     */
    public static function isWritable(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        if (Paths::isAllowed($path, static::allowedRoots())) {
            return true;
        }

        if (preg_match('#^content/(collections|globals)/[A-Za-z0-9_-]+\.yaml$#', $path)) {
            return true;
        }

        $templates = preg_quote((string) config('statamic-visual-editor.collection_templates.collection', 'templates'), '#');

        return (bool) preg_match('#^content/collections/'.$templates.'/[A-Za-z0-9_.-]+\.md$#', $path);
    }

    /** The folders a package may write into freely, site-relative with a trailing slash. */
    public static function allowedRoots(): array
    {
        $roots = [
            'resources/fieldsets/',
            'resources/views/',
            'resources/blueprints/',
            'resources/forms/',
            rtrim(Paths::relative(TailwindStore::directory()), '/').'/',
            rtrim(Paths::relative(CollectionPresets::directory()), '/').'/',
        ];

        if ($previews = Previews::relativeDirectory()) {
            $roots[] = rtrim($previews, '/').'/';
        }

        return $roots;
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

        // Collection config, globals and template entries live in the Stache;
        // without this the new collection is on disk and not in the CP.
        if (array_filter($written, fn ($path) => str_starts_with($path, 'content/'))) {
            try {
                Stache::clear();
            } catch (\Throwable $e) {
                report($e);
            }
        }

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
