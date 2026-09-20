<?php

namespace Vizuall\ComponentExporter\Console;

use MarioHamann\StatamicVisualEditor\TailwindStore;
use Statamic\Facades\Fieldset;
use Vizuall\ComponentExporter\Section\FieldsetChain;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Paths;
use Vizuall\ComponentExporter\Section\Previews;
use Vizuall\ComponentExporter\Section\Registry;

/**
 * Section manifests → the folders a starter-kit module lists.
 *
 * A kit cannot list "every file of every section" and stay readable, and it
 * has no excludes, so the module lists the folders the sections live in: the
 * fieldset folder per group, the section partials folder, the Tailwind store
 * folder per group, the preview folder, the registry. New sections in an
 * existing group are then covered without touching the kit; a new group is
 * what `component-exporter:kit` catches.
 */
final class KitPaths
{
    /**
     * The files the sections own — the ones the module has to carry.
     *
     * @param  list<array>  $manifests
     * @return list<string>
     */
    public static function ownFiles(array $manifests): array
    {
        $files = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest['files'] as $file) {
                if (! $file['shared']) {
                    $files[$file['path']] = true;
                }
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * The paths that would cover every own file, as folders where the site's
     * layout allows it.
     *
     * @param  list<array>  $manifests
     * @return list<string>
     */
    public static function derive(array $manifests): array
    {
        $paths = [Registry::relativePath() => true];
        $fieldsets = rtrim(Paths::relative(Fieldset::directory()), '/');
        $partials = trim((string) config('statamic-visual-editor.previews.section_partials', 'resources/views/partials/page_sections'), '/');
        $tailwind = rtrim(Paths::relative(TailwindStore::directory()), '/');
        $previews = Previews::relativeDirectory();

        foreach ($manifests as $manifest) {
            foreach ($manifest['files'] as $file) {
                if ($file['shared']) {
                    continue;
                }

                $path = $file['path'];

                switch ($file['role']) {
                    case Manifest::ROLE_FIELDSET:
                        $folder = FieldsetChain::folder($file['label']);
                        $paths[$folder === '' ? $path : $fieldsets.'/'.str_replace('.', '/', $folder)] = true;
                        break;

                    case Manifest::ROLE_PARTIAL:
                        $paths[$partials] = true;
                        break;

                    case Manifest::ROLE_CSS:
                        $directory = dirname($path);
                        $paths[$directory === $tailwind ? $path : $directory] = true;
                        break;

                    case Manifest::ROLE_PREVIEW:
                        $paths[$previews ?? dirname(str_replace('/.meta/', '/', $path))] = true;
                        break;

                    default:
                        $paths[$path] = true;
                }
            }
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    /**
     * @param  list<string>  $listed
     */
    public static function covers(array $listed, string $file): bool
    {
        foreach ($listed as $path) {
            if ($path === $file || Paths::isUnder($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The existing list plus what the manifests need, without paths another
     * entry already covers, in a stable order: registry first, then folders as
     * they were, then additions.
     *
     * @param  list<string>  $kept
     * @param  list<string>  $wanted
     * @return list<string>
     */
    public static function mergeLists(array $kept, array $wanted): array
    {
        $out = [];

        foreach (array_merge($kept, $wanted) as $path) {
            if (in_array($path, $out, true) || static::covers($out, $path)) {
                continue;
            }

            // A folder that now covers earlier, narrower entries replaces them.
            $out = array_values(array_filter($out, fn ($existing) => ! Paths::isUnder($existing, $path)));
            $out[] = $path;
        }

        return $out;
    }

    /**
     * Rewrites one module's `export_paths` list in place, keeping every other
     * line of the file — comments above the list included.
     *
     * @param  list<string>  $paths
     */
    public static function writeModuleList(string $kitPath, string $module, array $paths): void
    {
        $lines = preg_split('/\r?\n/', (string) file_get_contents($kitPath)) ?: [];
        $start = static::listStart($lines, $module);

        if ($start === null) {
            throw new \RuntimeException("Could not find modules.{$module}.export_paths in {$kitPath}.");
        }

        $indent = str_repeat(' ', strlen($lines[$start]) - strlen(ltrim($lines[$start])));
        $item = $indent.'  - ';
        $end = $start + 1;

        // The list runs until the first non-empty line indented no deeper than the key.
        while ($end < count($lines)) {
            $line = $lines[$end];

            if (trim($line) !== '' && strlen($line) - strlen(ltrim($line)) <= strlen($indent)) {
                break;
            }

            $end++;
        }

        $replacement = array_map(fn ($path) => $item.$path, $paths);

        // Keep one blank line before the next key when there was one.
        if ($end < count($lines) && $end > $start + 1 && trim($lines[$end - 1]) === '') {
            $replacement[] = '';
        }

        array_splice($lines, $start + 1, $end - $start - 1, $replacement);

        file_put_contents($kitPath, implode("\n", $lines));
    }

    /** @param  list<string>  $lines */
    private static function listStart(array $lines, string $module): ?int
    {
        $inModules = false;
        $inModule = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^modules:\s*$/', $line)) {
                $inModules = true;

                continue;
            }

            if ($inModules && preg_match('/^\S/', $line)) {
                $inModules = false;
                $inModule = false;
            }

            if ($inModules && preg_match('/^(\s+)'.preg_quote($module, '/').':\s*$/', $line)) {
                $inModule = true;

                continue;
            }

            if ($inModule && preg_match('/^\s+export_paths:\s*$/', $line)) {
                return $i;
            }

            if ($inModule && preg_match('/^\s{1,2}\S/', $line)) {
                $inModule = false;
            }
        }

        return null;
    }
}
