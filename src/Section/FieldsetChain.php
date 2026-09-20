<?php

namespace Vizuall\ComponentExporter\Section;

use Illuminate\Support\Str;
use Statamic\Facades\Fieldset;
use Statamic\Facades\YAML;

/**
 * The fieldsets a section's fields are built from.
 *
 * A section set names one fieldset (`fields: [{import: hero.style_2}]`). That
 * fieldset imports others (`import: blocks.headline`) and borrows single fields
 * (`field: common.content_tab`). Every one of those is a file the section
 * cannot load without, so the walk follows both spellings, transitively, and
 * reports the handles it could not find rather than dropping them: a reference
 * to a fieldset that does not exist is something an exporter should say out
 * loud, not paper over.
 */
final class FieldsetChain
{
    /** Chains this long are a loop, not a design. */
    private const MAX_DEPTH = 20;

    /**
     * The fieldset handles a set imports directly.
     *
     * @param  array<string, mixed>  $set  a set definition from the page-builder registry
     * @return list<string>
     */
    public static function rootsOf(array $set): array
    {
        $roots = [];

        foreach ((array) ($set['fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['import']) && is_string($field['import'])) {
                $roots[] = $field['import'];
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Every fieldset handle reachable from the roots, roots first, in the order
     * they were met.
     *
     * @param  list<string>  $roots
     * @return array{handles: list<string>, missing: list<string>}
     */
    public static function walk(array $roots): array
    {
        $handles = [];
        $missing = [];
        $seen = [];
        $queue = array_map(fn ($handle) => [$handle, 0], $roots);

        while ($queue) {
            [$handle, $depth] = array_shift($queue);

            if (isset($seen[$handle])) {
                continue;
            }

            $seen[$handle] = true;
            $path = static::path($handle);

            if (! is_file($path)) {
                $missing[] = $handle;

                continue;
            }

            $handles[] = $handle;

            if ($depth >= self::MAX_DEPTH) {
                continue;
            }

            foreach (static::dependencies(static::contents($path)) as $next) {
                if (! isset($seen[$next])) {
                    $queue[] = [$next, $depth + 1];
                }
            }
        }

        return ['handles' => $handles, 'missing' => $missing];
    }

    /**
     * The fieldset handles one fieldset's contents refer to.
     *
     * @param  array<string, mixed>  $contents
     * @return list<string>
     */
    public static function dependencies(array $contents): array
    {
        $found = [];

        array_walk_recursive($contents, function ($value, $key) use (&$found) {
            if ($key === 'import' && is_string($value) && $value !== '') {
                $found[] = $value;
            }

            // `field: common.content_tab` — the fieldset is everything before the last dot.
            if ($key === 'field' && is_string($value) && str_contains($value, '.')) {
                $found[] = Str::beforeLast($value, '.');
            }
        });

        return array_values(array_unique($found));
    }

    /** Where Statamic keeps a fieldset: dots are folders. */
    public static function path(string $handle): string
    {
        return rtrim(Fieldset::directory(), '/\\').DIRECTORY_SEPARATOR
            .str_replace('.', DIRECTORY_SEPARATOR, $handle).'.yaml';
    }

    /** The folder part of a handle: `hero.style_2` → `hero`, `event_list` → ``. */
    public static function folder(string $handle): string
    {
        return str_contains($handle, '.') ? Str::beforeLast($handle, '.') : '';
    }

    /** @return array<string, mixed> */
    private static function contents(string $path): array
    {
        try {
            $parsed = YAML::parse((string) file_get_contents($path));
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }
}
