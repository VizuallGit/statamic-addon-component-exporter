<?php

namespace Vizuall\ComponentExporter\Section;

/**
 * Paths as the package writes them: relative to the site root, forward
 * slashes, never above the root.
 *
 * Every file a section owns or borrows is recorded this way in the manifest,
 * so a ZIP made on one site lands in the same place on another regardless of
 * where either site is installed.
 */
final class Paths
{
    /** An absolute path on this machine, relative to the site root. */
    public static function relative(string $absolute): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $path = str_replace('\\', '/', $absolute);

        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return ltrim($path, '/');
    }

    /** A manifest path back on this machine. */
    public static function absolute(string $relative): string
    {
        return base_path(str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/')));
    }

    /**
     * Whether a path from a ZIP may be written at all: relative, no `..`,
     * nothing hidden, and under one of the given roots.
     *
     * @param  list<string>  $roots  relative directory prefixes with a trailing slash
     */
    public static function isAllowed(string $relative, array $roots): bool
    {
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $relative)) {
            return false;
        }

        foreach (explode('/', $relative) as $segment) {
            // `.meta` is the one dotted folder Statamic itself writes, next to the preview images.
            if ($segment !== '.meta' && str_starts_with($segment, '.')) {
                return false;
            }
        }

        foreach ($roots as $root) {
            if (str_starts_with($relative, $root)) {
                return true;
            }
        }

        return false;
    }

    /** `resources/fieldsets/hero/style_2.yaml` is under `resources/fieldsets/hero`. */
    public static function isUnder(string $relative, string $directory): bool
    {
        return str_starts_with($relative, rtrim($directory, '/').'/');
    }
}
