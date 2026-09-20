<?php

namespace Vizuall\ComponentExporter\Export;

use Illuminate\Support\Facades\File;
use Statamic\Facades\YAML;
use Vizuall\ComponentExporter\Section\Paths;

/**
 * What can travel besides section types: blueprints, and collection
 * configuration (never entries). Listed for the picker, and the one rule for
 * which of those paths a ZIP may write back.
 */
final class Extras
{
    public const ROLE_BLUEPRINT = 'blueprint';

    public const ROLE_COLLECTION = 'collection';

    /** @return list<array{handle: string, title: string, path: string, category: string}> */
    public static function blueprints(): array
    {
        $base = resource_path('blueprints');

        if (! is_dir($base)) {
            return [];
        }

        $out = [];

        foreach (File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'yaml') {
                continue;
            }

            $parts = array_filter(explode(DIRECTORY_SEPARATOR, $file->getRelativePath()));
            $yaml = static::yaml($file->getPathname());

            $out[] = [
                'handle' => $file->getFilenameWithoutExtension(),
                'title' => (string) ($yaml['title'] ?? $file->getFilenameWithoutExtension()),
                'path' => Paths::relative($file->getPathname()),
                'category' => implode(' / ', array_map('ucfirst', $parts)) ?: 'Generelt',
            ];
        }

        usort($out, fn ($a, $b) => [$a['category'], $a['title']] <=> [$b['category'], $b['title']]);

        return $out;
    }

    /** @return list<array{handle: string, title: string, path: string}> */
    public static function collections(): array
    {
        $out = [];

        foreach (glob(base_path('content/collections/*.yaml')) ?: [] as $path) {
            $handle = pathinfo($path, PATHINFO_FILENAME);
            $yaml = static::yaml($path);

            $out[] = [
                'handle' => $handle,
                'title' => (string) ($yaml['title'] ?? ucfirst($handle)),
                'path' => Paths::relative($path),
            ];
        }

        usort($out, fn ($a, $b) => $a['title'] <=> $b['title']);

        return $out;
    }

    /** A blueprint file, or a collection's own config file — nothing else. */
    public static function isAllowedPath(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        if (Paths::isAllowed($relative, ['resources/blueprints/'])) {
            return str_ends_with($relative, '.yaml');
        }

        return (bool) preg_match('#^content/collections/[^/]+\.yaml$#', $relative);
    }

    public static function roleFor(string $relative): string
    {
        return str_starts_with($relative, 'resources/blueprints/') ? self::ROLE_BLUEPRINT : self::ROLE_COLLECTION;
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
