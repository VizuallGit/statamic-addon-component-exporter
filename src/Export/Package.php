<?php

namespace Vizuall\ComponentExporter\Export;

use Illuminate\Support\Str;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Paths;
use ZipArchive;

/**
 * A ZIP of section types, built from their manifests.
 *
 * Files sit in the archive at their site-relative paths, so the layout is the
 * site's own. `manifest.json` at the root says what the archive is: the format
 * version, where it came from, and per section the registry entry and every
 * file with its role, whether it is shared, and a checksum — which is what lets
 * the receiving site tell "already have this" from "have a different one".
 */
final class Package
{
    /**
     * @param  list<string>  $sections  section type handles
     * @param  list<string>  $extras  blueprint / collection config paths, site-relative
     * @return array{path: string, filename: string, sections: int, files: int}
     *
     * @throws \InvalidArgumentException when nothing exportable was asked for
     * @throws \RuntimeException when the archive cannot be written
     */
    public static function build(array $sections, array $extras = []): array
    {
        $manifests = Manifest::forSections($sections);
        $extras = array_values(array_filter(
            array_unique($extras),
            fn ($path) => is_string($path) && Extras::isAllowedPath($path) && is_file(Paths::absolute($path))
        ));

        if (! $manifests && ! $extras) {
            throw new \InvalidArgumentException('Nothing to export.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'component-exporter-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the ZIP archive.');
        }

        $added = [];
        $sectionsOut = [];

        foreach ($manifests as $manifest) {
            $files = [];

            foreach ($manifest['files'] as $file) {
                $absolute = Paths::absolute($file['path']);

                if (! is_file($absolute)) {
                    continue;
                }

                if (! isset($added[$file['path']])) {
                    $zip->addFile($absolute, $file['path']);
                    $added[$file['path']] = sha1_file($absolute);
                }

                $files[] = $file + ['sha1' => $added[$file['path']]];
            }

            $sectionsOut[] = [
                'handle' => $manifest['handle'],
                'display' => $manifest['display'],
                'group' => $manifest['group'],
                'group_display' => $manifest['group_display'],
                'static' => $manifest['static'],
                'set' => $manifest['set'],
                'files' => $files,
                'missing' => $manifest['missing'],
            ];
        }

        $extrasOut = [];

        foreach ($extras as $path) {
            $absolute = Paths::absolute($path);

            if (! isset($added[$path])) {
                $zip->addFile($absolute, $path);
                $added[$path] = sha1_file($absolute);
            }

            $extrasOut[] = ['path' => $path, 'role' => Extras::roleFor($path), 'sha1' => $added[$path]];
        }

        $zip->addFromString('manifest.json', json_encode([
            'format' => Manifest::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'source' => ['name' => (string) config('app.name'), 'url' => (string) config('app.url')],
            'sections' => $sectionsOut,
            'extras' => $extrasOut,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $zip->close();

        return [
            'path' => $tmp,
            'filename' => static::filename($manifests),
            'sections' => count($manifests),
            'files' => count($added),
        ];
    }

    /** One section is named after itself; several are just "sektioner". Dated either way. */
    public static function filename(array $manifests): string
    {
        $name = count($manifests) === 1 ? Str::slug(str_replace('/', '-', $manifests[0]['handle'])) : 'sektioner';

        return $name.'-'.date('Y-m-d').'.zip';
    }
}
