<?php

namespace Vizuall\ComponentExporter\Export;

use Illuminate\Support\Str;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Paths;
use Vizuall\ComponentExporter\Units\Catalog;
use ZipArchive;

/**
 * A ZIP of section types and units (collections, forms, globals, blueprints),
 * built from their manifests.
 *
 * Files sit in the archive at their site-relative paths, so the layout is the
 * site's own. `manifest.json` at the root says what the archive is: the format
 * version, where it came from, and per section or unit every file with its
 * role, whether it is shared, and a checksum — which is what lets the receiving
 * site tell "already have this" from "have a different one".
 *
 * Format 2 added `units`; format 1 had a flat `extras` list instead, and the
 * Inspector still reads those.
 */
final class Package
{
    public const FORMAT = 2;

    /**
     * @param  list<string>  $sections  section type handles
     * @param  list<string>  $units  unit ids (`collection:services`, `form:contact_form`, …)
     * @return array{path: string, filename: string, sections: int, units: int, files: int}
     *
     * @throws \InvalidArgumentException when nothing exportable was asked for
     * @throws \RuntimeException when the archive cannot be written
     */
    public static function build(array $sections, array $units = []): array
    {
        $manifests = Manifest::forSections($sections);
        $resolved = Catalog::resolveMany($units);

        if (! $manifests && ! $resolved) {
            throw new \InvalidArgumentException('Nothing to export.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'component-exporter-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the ZIP archive.');
        }

        $added = [];
        $sectionsOut = [];
        $unitsOut = [];

        foreach ($manifests as $manifest) {
            $sectionsOut[] = [
                'handle' => $manifest['handle'],
                'display' => $manifest['display'],
                'group' => $manifest['group'],
                'group_display' => $manifest['group_display'],
                'static' => $manifest['static'],
                'set' => $manifest['set'],
                'files' => static::addFiles($zip, $manifest['files'], $added),
                'missing' => $manifest['missing'],
            ];
        }

        foreach ($resolved as $unit) {
            $unitsOut[] = [
                'id' => $unit['id'],
                'kind' => $unit['kind'],
                'handle' => $unit['handle'],
                'display' => $unit['display'],
                'files' => static::addFiles($zip, $unit['files'], $added),
                'missing' => $unit['missing'],
            ];
        }

        $zip->addFromString('manifest.json', json_encode([
            'format' => self::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'source' => ['name' => (string) config('app.name'), 'url' => (string) config('app.url')],
            'sections' => $sectionsOut,
            'units' => $unitsOut,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $zip->close();

        return [
            'path' => $tmp,
            'filename' => static::filename($manifests, $resolved),
            'sections' => count($manifests),
            'units' => count($resolved),
            'files' => count($added),
        ];
    }

    /**
     * Adds each file once and returns the list with checksums.
     *
     * @param  list<array{path: string, role: string, shared: bool, label: string}>  $files
     * @param  array<string, string>  $added  path => sha1 of everything already in the archive
     * @return list<array>
     */
    private static function addFiles(ZipArchive $zip, array $files, array &$added): array
    {
        $out = [];

        foreach ($files as $file) {
            $absolute = Paths::absolute($file['path']);

            if (! is_file($absolute)) {
                continue;
            }

            if (! isset($added[$file['path']])) {
                $zip->addFile($absolute, $file['path']);
                $added[$file['path']] = sha1_file($absolute);
            }

            $out[] = $file + ['sha1' => $added[$file['path']]];
        }

        return $out;
    }

    /** One thing is named after itself; several are just "pakke". Dated either way. */
    public static function filename(array $manifests, array $units = []): string
    {
        $things = array_merge(array_column($manifests, 'handle'), array_column($units, 'id'));
        $name = count($things) === 1 ? Str::slug(str_replace(['/', ':'], '-', $things[0])) : 'pakke';

        return $name.'-'.date('Y-m-d').'.zip';
    }
}
