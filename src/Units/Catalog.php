<?php

namespace Vizuall\ComponentExporter\Units;

use Illuminate\Support\Str;
use MarioHamann\StatamicVisualEditor\CollectionPresets;
use MarioHamann\StatamicVisualEditor\CollectionViewTemplates;
use MarioHamann\StatamicVisualEditor\TailwindStore;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\YAML;
use Vizuall\ComponentExporter\Section\FieldsetChain;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Paths;

/**
 * What travels besides section types, as units an editor recognises.
 *
 * A collection is not one YAML file: it is its config, its blueprints, the
 * views that render it (`services/index`, `services/show`), the Tailwind the
 * dock baked for those views, the template entries the Visual Editor keeps for
 * them, its preset folder — and everything those blueprints and views pull in
 * (fieldsets, partials). A form is its config and blueprint; a global its
 * config and blueprint; the remaining blueprints (assets, default, user) stand
 * alone. Each unit lists its files with a role, marked own or shared exactly
 * like a section manifest, so export, review and import treat the two alike.
 *
 * Unit ids are `kind:handle` (`collection:services`, `form:contact_form`,
 * `global:site_head`, `blueprint:resources/blueprints/user.yaml`).
 */
final class Catalog
{
    public const KIND_COLLECTION = 'collection';

    public const KIND_FORM = 'form';

    public const KIND_GLOBAL = 'global';

    public const KIND_BLUEPRINT = 'blueprint';

    public const ROLE_CONFIG = 'config';

    public const ROLE_BLUEPRINT = 'blueprint';

    public const ROLE_VIEW = 'view';

    public const ROLE_TEMPLATE = 'template';

    public const ROLE_PRESET = 'preset';

    /**
     * Every unit on the site, by kind, files resolved.
     *
     * @return array{collections: list<array>, forms: list<array>, globals: list<array>, blueprints: list<array>}
     */
    public static function all(): array
    {
        return [
            'collections' => static::resolveMany(static::ids(self::KIND_COLLECTION)),
            'forms' => static::resolveMany(static::ids(self::KIND_FORM)),
            'globals' => static::resolveMany(static::ids(self::KIND_GLOBAL)),
            'blueprints' => static::resolveMany(static::ids(self::KIND_BLUEPRINT)),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array>
     */
    public static function resolveMany(array $ids): array
    {
        $out = [];

        foreach (array_values(array_unique($ids)) as $id) {
            if ($unit = static::resolve((string) $id)) {
                $out[] = $unit;
            }
        }

        return $out;
    }

    /**
     * @return array{id: string, kind: string, handle: string, display: string, files: list<array{path: string, role: string, shared: bool, label: string}>, missing: list<string>}|null
     */
    public static function resolve(string $id): ?array
    {
        if (! str_contains($id, ':')) {
            return null;
        }

        [$kind, $handle] = explode(':', $id, 2);

        return match ($kind) {
            self::KIND_COLLECTION => static::collection($handle),
            self::KIND_FORM => static::form($handle),
            self::KIND_GLOBAL => static::global($handle),
            self::KIND_BLUEPRINT => static::blueprint($handle),
            default => null,
        };
    }

    /**
     * The ids of every unit of one kind, from what is on disk.
     *
     * @return list<string>
     */
    public static function ids(string $kind): array
    {
        $ids = [];

        switch ($kind) {
            case self::KIND_COLLECTION:
                foreach (glob(base_path('content/collections/*.yaml')) ?: [] as $path) {
                    $ids[] = $kind.':'.pathinfo($path, PATHINFO_FILENAME);
                }
                break;

            case self::KIND_FORM:
                foreach (glob(resource_path('forms/*.yaml')) ?: [] as $path) {
                    $ids[] = $kind.':'.pathinfo($path, PATHINFO_FILENAME);
                }
                break;

            case self::KIND_GLOBAL:
                foreach (glob(base_path('content/globals/*.yaml')) ?: [] as $path) {
                    $ids[] = $kind.':'.pathinfo($path, PATHINFO_FILENAME);
                }
                break;

            case self::KIND_BLUEPRINT:
                foreach (glob(resource_path('blueprints/*.yaml')) ?: [] as $path) {
                    $ids[] = $kind.':'.Paths::relative($path);
                }
                foreach (glob(resource_path('blueprints/*/*.yaml')) ?: [] as $path) {
                    $folder = basename(dirname($path));

                    if (! in_array($folder, ['collections', 'forms', 'globals'], true)) {
                        $ids[] = $kind.':'.Paths::relative($path);
                    }
                }
                break;
        }

        sort($ids);

        return $ids;
    }

    private static function collection(string $handle): ?array
    {
        if (! static::validHandle($handle)) {
            return null;
        }

        $config = base_path("content/collections/{$handle}.yaml");

        if (! is_file($config)) {
            return null;
        }

        $yaml = static::yaml($config);
        $files = [];
        $missing = [];

        static::add($files, Paths::relative($config), self::ROLE_CONFIG, false, $handle.'.yaml');

        $blueprints = glob(resource_path("blueprints/collections/{$handle}/*.yaml")) ?: [];

        foreach ($blueprints as $path) {
            static::add($files, Paths::relative($path), self::ROLE_BLUEPRINT, false, pathinfo($path, PATHINFO_FILENAME));
        }

        static::fieldsets($blueprints, $files, $missing);

        // The views: the collection's own folder, plus the template its config
        // names when that lives elsewhere.
        $views = Views::inFolder($handle);

        if (is_string($template = $yaml['template'] ?? null) && $template !== '') {
            foreach (['.antlers.html', '.blade.php'] as $extension) {
                if (is_file($path = resource_path('views/'.trim($template, '/').$extension))) {
                    $views[] = $path;
                }
            }
        }

        $views = array_values(array_unique($views));
        $own = array_flip(array_map('realpath', $views));

        static::views($views, $own, 'view/'.$handle.'/', $files);

        foreach (static::templateEntries($handle) as $path => $title) {
            static::add($files, $path, self::ROLE_TEMPLATE, false, $title);
        }

        $presets = rtrim(CollectionPresets::directory(), '/\\').DIRECTORY_SEPARATOR.$handle;

        if (is_dir($presets)) {
            foreach (glob($presets.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    static::add($files, Paths::relative($path), self::ROLE_PRESET, false, 'preset/'.basename($path));
                }
            }
        }

        return static::unit(self::KIND_COLLECTION, $handle, (string) ($yaml['title'] ?? Str::title($handle)), $files, $missing);
    }

    private static function form(string $handle): ?array
    {
        if (! static::validHandle($handle) || ! is_file($config = resource_path("forms/{$handle}.yaml"))) {
            return null;
        }

        $files = [];
        $missing = [];

        static::add($files, Paths::relative($config), self::ROLE_CONFIG, false, $handle.'.yaml');

        if (is_file($blueprint = resource_path("blueprints/forms/{$handle}.yaml"))) {
            static::add($files, Paths::relative($blueprint), self::ROLE_BLUEPRINT, false, $handle);
            static::fieldsets([$blueprint], $files, $missing);
        }

        $yaml = static::yaml($config);

        return static::unit(self::KIND_FORM, $handle, (string) ($yaml['title'] ?? Str::title(str_replace('_', ' ', $handle))), $files, $missing);
    }

    private static function global(string $handle): ?array
    {
        if (! static::validHandle($handle) || ! is_file($config = base_path("content/globals/{$handle}.yaml"))) {
            return null;
        }

        $files = [];
        $missing = [];

        static::add($files, Paths::relative($config), self::ROLE_CONFIG, false, $handle.'.yaml');

        if (is_file($blueprint = resource_path("blueprints/globals/{$handle}.yaml"))) {
            static::add($files, Paths::relative($blueprint), self::ROLE_BLUEPRINT, false, $handle);
            static::fieldsets([$blueprint], $files, $missing);
        }

        $yaml = static::yaml($config);

        return static::unit(self::KIND_GLOBAL, $handle, (string) ($yaml['title'] ?? Str::title(str_replace('_', ' ', $handle))), $files, $missing);
    }

    private static function blueprint(string $relative): ?array
    {
        $relative = str_replace('\\', '/', $relative);

        if (! Paths::isAllowed($relative, ['resources/blueprints/']) || ! str_ends_with($relative, '.yaml')) {
            return null;
        }

        $path = Paths::absolute($relative);

        if (! is_file($path)) {
            return null;
        }

        $files = [];
        $missing = [];
        $label = preg_replace('#^resources/blueprints/#', '', substr($relative, 0, -5));

        static::add($files, $relative, self::ROLE_BLUEPRINT, false, (string) $label);
        static::fieldsets([$path], $files, $missing);

        $yaml = static::yaml($path);

        return static::unit(self::KIND_BLUEPRINT, $relative, (string) ($yaml['title'] ?? Str::title(basename((string) $label))), $files, $missing);
    }

    /**
     * The fieldsets a set of blueprints imports or borrows from, all shared.
     *
     * @param  list<string>  $blueprints  absolute paths
     */
    private static function fieldsets(array $blueprints, array &$files, array &$missing): void
    {
        $roots = [];

        foreach ($blueprints as $path) {
            $roots = array_merge($roots, FieldsetChain::dependencies(static::yaml($path)));
        }

        $walk = FieldsetChain::walk(array_values(array_unique($roots)));

        foreach ($walk['handles'] as $handle) {
            static::add($files, Paths::relative(FieldsetChain::path($handle)), Manifest::ROLE_FIELDSET, true, $handle);
        }

        foreach ($walk['missing'] as $handle) {
            $missing[] = 'fieldset: '.$handle;
        }
    }

    /**
     * Views and every partial they include, with the Tailwind baked for each.
     *
     * @param  list<string>  $roots  absolute paths
     * @param  array<string, int>  $own  realpath => anything, for the files that are the unit's own
     */
    private static function views(array $roots, array $own, string $ownTailwindPrefix, array &$files): void
    {
        foreach (Views::reachable($roots) as $absolute) {
            $relative = Paths::relative($absolute);
            $isOwn = isset($own[$absolute]);
            $label = preg_replace('#^resources/views/#', '', (string) preg_replace('/\.(antlers\.html|blade\.php)$/', '', $relative));

            static::add($files, $relative, $isOwn ? self::ROLE_VIEW : Manifest::ROLE_PARTIAL, ! $isOwn, (string) $label);

            foreach (Manifest::tailwindHandles((string) @file_get_contents($absolute), '') as $twHandle) {
                $path = TailwindStore::path($twHandle);

                if ($path !== null && is_file($path)) {
                    static::add($files, Paths::relative($path), Manifest::ROLE_CSS, ! str_starts_with($twHandle, $ownTailwindPrefix), $twHandle);
                }
            }
        }
    }

    /**
     * The Visual Editor's template entries for a collection's views, as
     * site-relative path => title.
     *
     * @return array<string, string>
     */
    private static function templateEntries(string $handle): array
    {
        $store = (string) config('statamic-visual-editor.collection_templates.collection', 'templates');

        if (! Collection::findByHandle($store)) {
            return [];
        }

        $out = [];

        foreach (Entry::query()->where('collection', $store)->get() as $entry) {
            if (CollectionViewTemplates::belongsToSource($entry, $handle) && is_string($path = $entry->path()) && is_file($path)) {
                $out[Paths::relative($path)] = (string) ($entry->get('title') ?: $entry->slug());
            }
        }

        return $out;
    }

    private static function unit(string $kind, string $handle, string $display, array $files, array $missing): array
    {
        return [
            'id' => $kind.':'.$handle,
            'kind' => $kind,
            'handle' => $handle,
            'display' => $display,
            'files' => array_values($files),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    private static function add(array &$files, string $path, string $role, bool $shared, string $label): void
    {
        if (isset($files[$path])) {
            $files[$path]['shared'] = $files[$path]['shared'] && $shared;

            return;
        }

        $files[$path] = ['path' => $path, 'role' => $role, 'shared' => $shared, 'label' => $label];
    }

    private static function validHandle(string $handle): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $handle);
    }

    /** @return array<string, mixed> */
    private static function yaml(string $path): array
    {
        try {
            $parsed = YAML::parse((string) file_get_contents($path));
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }
}
