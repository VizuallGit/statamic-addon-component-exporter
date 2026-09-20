<?php

namespace Vizuall\ComponentExporter\Units;

use MarioHamann\StatamicVisualEditor\PreviewPartials\Includes;

/**
 * The view files a set of templates renders through: the templates themselves
 * and, transitively, every partial they include — resolved with the Visual
 * Editor's own reading of `{{ partial }}` (Includes), the same one section
 * manifests use, so a collection's views and a section's template agree on
 * what a partial tag means.
 */
final class Views
{
    /** Partial chains this deep are a cycle, not a design. */
    private const MAX_DEPTH = 12;

    /**
     * @param  list<string>  $roots  absolute paths
     * @return list<string> absolute paths that exist, roots first
     */
    public static function reachable(array $roots): array
    {
        $seen = [];
        $queue = [];

        foreach ($roots as $root) {
            if (($real = realpath($root)) !== false && is_file($real)) {
                $queue[] = [$real, 0];
            }
        }

        while ($queue) {
            [$file, $depth] = array_shift($queue);

            if (isset($seen[$file])) {
                continue;
            }

            $seen[$file] = true;

            if ($depth >= self::MAX_DEPTH) {
                continue;
            }

            foreach (Includes::sources((string) @file_get_contents($file)) as $src) {
                foreach (Includes::resolve($src) as $next) {
                    if (! isset($seen[$next])) {
                        $queue[] = [$next, $depth + 1];
                    }
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Every template file in a folder under `resources/views`.
     *
     * @return list<string> absolute paths
     */
    public static function inFolder(string $folder): array
    {
        $dir = resource_path('views/'.trim($folder, '/'));

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(antlers\.html|blade\.php)$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
