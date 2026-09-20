<?php

namespace Vizuall\ComponentExporter\Section;

use MarioHamann\StatamicVisualEditor\PreviewPartials;
use MarioHamann\StatamicVisualEditor\TailwindStore;

/**
 * THE contract: what belongs to a section type.
 *
 * A section is its registry entry plus four kinds of file —
 *
 *   fieldset  the fieldset its set imports and, transitively, every fieldset
 *             that one imports or borrows a field from (FieldsetChain);
 *   partial   its template and every partial the template pulls in, walked the
 *             way the Visual Editor walks it for preview fingerprints
 *             (PreviewPartials::forSection, so the two never disagree);
 *   css       the Tailwind the dock baked for the section and for any of those
 *             partials (`{{ sve_tw }}` / `{{ sve_tw handle="…" }}` in the text
 *             names the file in the store);
 *   preview   the picture the library shows for it, with its `.meta`.
 *
 * Each file is `shared` or not. A section's own files are the ones named after
 * it: its fieldset folder, its template, its baked CSS, its preview. Everything
 * else (blocks, components, the common settings tabs) is shared with other
 * sections, and an import should say so before it overwrites one.
 *
 * Everything that reads "what does this section consist of" — the ZIP export,
 * the import review, the starter-kit check — reads this and nothing else.
 */
final class Manifest
{
    public const FORMAT = 1;

    public const ROLE_FIELDSET = 'fieldset';

    public const ROLE_PARTIAL = 'partial';

    public const ROLE_CSS = 'css';

    public const ROLE_PREVIEW = 'preview';

    /**
     * @return array{
     *   handle: string, display: string, group: string, group_display: ?string,
     *   static: bool, set: array,
     *   files: list<array{path: string, role: string, shared: bool, label: string}>,
     *   missing: list<string>
     * }|null null when the handle is not a registered section type
     */
    public static function forSection(string $handle): ?array
    {
        if ($handle === static::globalSectionSet() || ! $entry = Registry::entry($handle)) {
            return null;
        }

        $set = $entry['set'];
        $files = [];
        $missing = [];

        static::fieldsets($set, $files, $missing);
        $partials = static::partials($handle, $files);
        static::css($handle, $partials, $files);
        static::preview($set, $files);

        return [
            'handle' => $handle,
            'display' => (string) ($set['display'] ?? $handle),
            'group' => $entry['group'],
            'group_display' => $entry['group_display'],
            'static' => ($set['static'] ?? false) === true,
            'set' => $set,
            'files' => array_values($files),
            'missing' => $missing,
        ];
    }

    /**
     * The set a page uses to place a saved ("global") section. It is the Visual
     * Editor's plumbing, shipped with every site, and its template renders
     * whatever section type the saved entry has — so as a "section" it would
     * claim every template on the site. Not a section type; never exported.
     */
    public static function globalSectionSet(): string
    {
        return (string) config('statamic-visual-editor.saved_sections.set', 'global_section');
    }

    /**
     * Manifests for several handles; unknown handles are left out.
     *
     * @param  list<string>  $handles
     * @return list<array>
     */
    public static function forSections(array $handles): array
    {
        $out = [];

        foreach (array_values(array_unique($handles)) as $handle) {
            if ($manifest = static::forSection((string) $handle)) {
                $out[] = $manifest;
            }
        }

        return $out;
    }

    /**
     * Every file across several manifests once, with the sections that use it.
     * A file is shared in the union when any manifest calls it shared.
     *
     * @param  list<array>  $manifests
     * @return list<array{path: string, role: string, shared: bool, label: string, used_by: list<string>}>
     */
    public static function union(array $manifests): array
    {
        $union = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest['files'] as $file) {
                $path = $file['path'];

                if (! isset($union[$path])) {
                    $union[$path] = $file + ['used_by' => []];
                }

                $union[$path]['shared'] = $union[$path]['shared'] || $file['shared'];
                $union[$path]['used_by'][] = $manifest['handle'];
            }
        }

        return array_values($union);
    }

    /** @param  array<string, array>  $files  keyed by path */
    private static function fieldsets(array $set, array &$files, array &$missing): void
    {
        $roots = FieldsetChain::rootsOf($set);
        $ownFolders = array_filter(array_map([FieldsetChain::class, 'folder'], $roots));
        $walk = FieldsetChain::walk($roots);

        foreach ($walk['handles'] as $handle) {
            $folder = FieldsetChain::folder($handle);
            $own = in_array($handle, $roots, true) || ($folder !== '' && in_array($folder, $ownFolders, true));

            static::add($files, Paths::relative(FieldsetChain::path($handle)), self::ROLE_FIELDSET, ! $own, $handle);
        }

        foreach ($walk['missing'] as $handle) {
            $missing[] = 'fieldset: '.$handle;
        }
    }

    /**
     * @param  array<string, array>  $files
     * @return list<string> absolute paths of the partials, for the CSS pass
     */
    private static function partials(string $handle, array &$files): array
    {
        $base = trim((string) config(
            'statamic-visual-editor.previews.section_partials',
            'resources/views/partials/page_sections'
        ), '/');

        $partials = PreviewPartials::forSection($handle);

        foreach ($partials as $absolute) {
            $relative = Paths::relative($absolute);
            $own = str_starts_with($relative, $base.'/'.$handle.'.') || Paths::isUnder($relative, $base.'/'.$handle);
            $label = preg_replace('/\.(antlers\.html|blade\.php)$/', '', Paths::relative($absolute));
            $label = preg_replace('#^resources/views/#', '', (string) $label);

            static::add($files, $relative, self::ROLE_PARTIAL, ! $own, (string) $label);
        }

        return $partials;
    }

    /**
     * @param  list<string>  $partials  absolute paths
     * @param  array<string, array>  $files
     */
    private static function css(string $handle, array $partials, array &$files): void
    {
        foreach ($partials as $absolute) {
            foreach (static::tailwindHandles((string) @file_get_contents($absolute), $handle) as $twHandle) {
                $path = TailwindStore::path($twHandle);

                if ($path !== null && is_file($path)) {
                    static::add($files, Paths::relative($path), self::ROLE_CSS, $twHandle !== $handle, $twHandle);
                }
            }
        }
    }

    /** @param  array<string, array>  $files */
    private static function preview(array $set, array &$files): void
    {
        foreach (Previews::filesFor($set['image'] ?? null) as $relative) {
            static::add($files, $relative, self::ROLE_PREVIEW, false, basename($relative));
        }
    }

    /**
     * The Tailwind store keys a template reads: a bare `{{ sve_tw }}` is the
     * section's own type, `{{ sve_tw handle="view/partials/blocks/headline" }}`
     * names a shared one.
     *
     * @return list<string>
     */
    public static function tailwindHandles(string $contents, string $sectionHandle): array
    {
        if (! preg_match_all('/\{\{-?\s*sve_tw\b([^}]*)\}\}/', $contents, $tags)) {
            return [];
        }

        $handles = [];

        foreach ($tags[1] as $params) {
            if (preg_match('/\bhandle\s*=\s*(["\'])(.*?)\1/', $params, $m)) {
                $handles[] = trim($m[2]);
            } else {
                $handles[] = $sectionHandle;
            }
        }

        return array_values(array_unique(array_filter($handles)));
    }

    /** @param  array<string, array>  $files */
    private static function add(array &$files, string $path, string $role, bool $shared, string $label): void
    {
        if (isset($files[$path])) {
            $files[$path]['shared'] = $files[$path]['shared'] && $shared;

            return;
        }

        $files[$path] = ['path' => $path, 'role' => $role, 'shared' => $shared, 'label' => $label];
    }
}
