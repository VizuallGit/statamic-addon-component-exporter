<?php

namespace Vizuall\ComponentExporter\Import;

use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Paths;
use Vizuall\ComponentExporter\Section\Registry;
use ZipArchive;

/**
 * Reads a package against this site before anything is written.
 *
 * For every file the manifest lists: is it new here, the same as ours, or
 * different? For every section: is it already registered, and is the entry the
 * same? The review the editor sees is this, and the default choice follows
 * from it — a new file comes in, an identical one has nothing to do, a changed
 * file comes in when it is the section's own and stays when it is shared with
 * sections the editor did not ask about.
 */
final class Inspector
{
    public const STATUS_NEW = 'new';

    public const STATUS_SAME = 'same';

    public const STATUS_CHANGED = 'changed';

    /**
     * @return array{
     *   legacy: bool, format: ?int, exported_at: ?string, source: array,
     *   sections: list<array>, extras: list<array>, files: int
     * }
     *
     * @throws \InvalidArgumentException when the file is not a readable ZIP
     */
    public static function inspect(string $zipPath): array
    {
        $zip = static::open($zipPath);
        $manifest = static::manifest($zip);

        if ($manifest === null) {
            return static::legacy($zip);
        }

        $usedBy = [];

        foreach ($manifest['sections'] as $section) {
            foreach ((array) ($section['files'] ?? []) as $file) {
                $usedBy[$file['path']][] = (string) $section['handle'];
            }
        }

        $sections = [];
        $count = 0;

        foreach ($manifest['sections'] as $section) {
            $handle = (string) $section['handle'];
            $current = Registry::entry($handle);
            $files = [];

            foreach ((array) ($section['files'] ?? []) as $file) {
                $files[] = static::describe(
                    (string) $file['path'],
                    $file['sha1'] ?? null,
                    (string) ($file['role'] ?? 'file'),
                    (bool) ($file['shared'] ?? false),
                    (string) ($file['label'] ?? basename((string) $file['path'])),
                    $usedBy[$file['path']] ?? []
                );
            }

            $count += count($files);

            $sections[] = [
                'handle' => $handle,
                'display' => (string) ($section['display'] ?? $handle),
                'group' => (string) ($section['group'] ?? ''),
                'group_display' => $section['group_display'] ?? null,
                'static' => (bool) ($section['static'] ?? false),
                'missing' => array_values((array) ($section['missing'] ?? [])),
                'registered' => $current !== null,
                'registry_same' => $current !== null && $current['set'] == ($section['set'] ?? null),
                'files' => $files,
            ];
        }

        $extras = [];

        foreach ((array) ($manifest['extras'] ?? []) as $extra) {
            $extras[] = static::describe(
                (string) $extra['path'],
                $extra['sha1'] ?? null,
                (string) ($extra['role'] ?? 'file'),
                false,
                basename((string) $extra['path']),
                []
            );
        }

        $zip->close();

        return [
            'legacy' => false,
            'format' => (int) $manifest['format'],
            'exported_at' => $manifest['exported_at'] ?? null,
            'source' => (array) ($manifest['source'] ?? []),
            'sections' => $sections,
            'extras' => $extras,
            'files' => $count + count($extras),
        ];
    }

    /**
     * The manifest inside a package, or null for an archive without one (made
     * by the previous version of this tool, or by hand).
     *
     * @return array{format: int, sections: list<array>, extras?: list<array>, exported_at?: string, source?: array}|null
     */
    public static function manifest(ZipArchive $zip): ?array
    {
        $raw = $zip->getFromName('manifest.json');

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['format'], $data['sections']) || ! is_array($data['sections'])) {
            return null;
        }

        if ((int) $data['format'] > Manifest::FORMAT) {
            throw new \InvalidArgumentException(sprintf(
                'Pakken er lavet med en nyere udgave af Komponent Eksport (format %d). Opdatér addonet her først.',
                (int) $data['format']
            ));
        }

        return $data;
    }

    /** @throws \InvalidArgumentException */
    public static function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Ugyldig ZIP-fil.');
        }

        return $zip;
    }

    /** An archive without a manifest: every file on its own, nothing to register. */
    private static function legacy(ZipArchive $zip): array
    {
        $extras = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $extras[] = static::describe($name, null, 'file', false, basename($name), []);
        }

        $zip->close();

        return [
            'legacy' => true,
            'format' => null,
            'exported_at' => null,
            'source' => [],
            'sections' => [],
            'extras' => $extras,
            'files' => count($extras),
        ];
    }

    /**
     * @param  list<string>  $usedBy
     * @return array{path: string, role: string, shared: bool, label: string, used_by: list<string>, status: string, suggested: bool}
     */
    private static function describe(string $path, ?string $sha1, string $role, bool $shared, string $label, array $usedBy): array
    {
        $absolute = Paths::absolute($path);

        if (! is_file($absolute)) {
            $status = self::STATUS_NEW;
        } elseif ($sha1 !== null && sha1_file($absolute) === $sha1) {
            $status = self::STATUS_SAME;
        } else {
            $status = self::STATUS_CHANGED;
        }

        return [
            'path' => $path,
            'role' => $role,
            'shared' => $shared,
            'label' => $label,
            'used_by' => array_values(array_unique($usedBy)),
            'status' => $status,
            'suggested' => $status === self::STATUS_NEW || ($status === self::STATUS_CHANGED && ! $shared),
        ];
    }
}
