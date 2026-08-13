<?php

namespace Vizuall\ComponentExporter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Statamic\Facades\YAML;
use ZipArchive;

class ComponentExporterController
{
    public function items()
    {
        return response()->json([
            'page_sections' => $this->getPageSections(),
            'blueprints'    => $this->getBlueprints(),
            'collections'   => $this->getCollections(),
            'selection'     => $this->loadSelection(),
        ]);
    }

    public function selection(): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->loadSelection());
    }

    public function toggleSelection(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $this->loadSelection();

        if ($request->has('set')) {
            // Erstat hele arrayet (bruges af "Vælg alle")
            $data['page_sections'] = array_values((array) $request->input('set', []));
        } else {
            // Toggle enkelt handle
            $handle   = $request->input('handle', '');
            $sections = $data['page_sections'] ?? [];
            $idx      = array_search($handle, $sections, true);
            if ($idx !== false) {
                array_splice($sections, $idx, 1);
            } else {
                $sections[] = $handle;
            }
            $data['page_sections'] = array_values($sections);
        }

        $this->saveSelection($data);

        return response()->json($data);
    }

    public function export(Request $request)
    {
        $selected = $request->input('selected', []);
        $files    = [];
        $visited  = [];

        foreach ($selected['page_sections'] ?? [] as $handle) {
            $fsHandle = $this->resolveFieldsetHandle($handle);
            $fsPath   = $this->fieldsetDiskPath($fsHandle);
            if (File::exists($fsPath)) {
                $files[$this->fieldsetZipPath($fsHandle)] = $fsPath;
                $this->collectDeps($fsHandle, $files, $visited);
            }
            $this->collectViewFiles($handle, $files);
        }

        foreach ($selected['blueprints'] ?? [] as $path) {
            $abs = base_path($path);
            if (File::exists($abs)) {
                $files[$path] = $abs;
            }
        }

        foreach ($selected['collections'] ?? [] as $handle) {
            $path = "content/collections/{$handle}.yaml";
            $abs  = base_path($path);
            if (File::exists($abs)) {
                $files[$path] = $abs;
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'statamic_export_') . '.zip';
        $zip     = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $zipPath => $diskPath) {
            $zip->addFile($diskPath, $zipPath);
        }

        if (!empty($selected['page_sections'])) {
            $zip->addFromString(
                'resources/fieldsets/page_sections.yaml',
                $this->buildFilteredPageSections($selected['page_sections'])
            );
        }

        $zip->close();

        return response()->download($tmpPath, 'components-export.zip')->deleteFileAfterSend(true);
    }

    public function check(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['zip' => 'required|file']);

        $zip = new ZipArchive();
        if ($zip->open($request->file('zip')->getPathname()) !== true) {
            return response()->json(['error' => 'Ugyldig ZIP-fil'], 422);
        }

        $conflicts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (File::exists(base_path($name))) {
                $conflicts[] = $name;
            }
        }
        $zip->close();

        return response()->json(['conflicts' => $conflicts]);
    }

    public function import(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['zip' => 'required|file']);

        $zip = new ZipArchive();
        if ($zip->open($request->file('zip')->getPathname()) !== true) {
            return response()->json(['error' => 'Ugyldig ZIP-fil'], 422);
        }

        $resolutions = json_decode($request->input('resolutions', '{}'), true) ?? [];
        $written = 0;
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name    = $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            $action  = $resolutions[$name] ?? 'overwrite';
            $dest    = base_path($name);

            if ($action === 'keep') {
                $skipped++;
                continue;
            }

            if ($action === 'copy') {
                $dest = $this->resolveUniquePath($dest);
            }

            File::ensureDirectoryExists(dirname($dest));
            File::put($dest, $content);
            $written++;
        }

        $zip->close();

        return response()->json([
            'message' => "Import gennemført. {$written} skrevet, {$skipped} beholdt.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data loaders
    // ─────────────────────────────────────────────────────────────────────────

    private function getPageSections(): array
    {
        $path = resource_path('fieldsets/page_sections.yaml');
        if (!File::exists($path)) return [];

        $yaml = YAML::parse(File::get($path));
        $sets = data_get($yaml, 'fields.0.field.sets.items.sets', []);

        return collect($sets)->map(function ($set, $handle) {
            $fsHandle = $this->fieldsetHandleFromSet($set, $handle);
            return [
                'handle' => $handle,
                'title'  => $set['display'] ?? $handle,
                'deps'   => $this->resolveDepsForHandle($fsHandle),
            ];
        })->values()->all();
    }

    private function getBlueprints(): array
    {
        $blueprints = [];
        $base       = resource_path('blueprints');

        foreach (File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'yaml') continue;

            $yaml     = YAML::parse(File::get($file->getPathname()));
            $relPath  = 'resources/blueprints/' . $file->getRelativePathname();
            $parts    = array_filter(explode(DIRECTORY_SEPARATOR, $file->getRelativePath()));
            $category = implode(' / ', array_map('ucfirst', $parts)) ?: 'Generelt';

            $blueprints[] = [
                'handle'   => $file->getFilenameWithoutExtension(),
                'title'    => $yaml['title'] ?? $file->getFilenameWithoutExtension(),
                'path'     => $relPath,
                'category' => $category,
            ];
        }

        return $blueprints;
    }

    private function getCollections(): array
    {
        return collect(File::glob(base_path('content/collections/*.yaml')))
            ->map(function ($path) {
                $handle = pathinfo($path, PATHINFO_FILENAME);
                $yaml   = YAML::parse(File::get($path));
                return ['handle' => $handle, 'title' => $yaml['title'] ?? ucfirst($handle)];
            })
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dependency resolution
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveDepsForHandle(string $handle): array
    {
        $handles = [];
        $visited = [];
        $this->walkDepsIntoHandles($handle, $handles, $visited);
        return $handles;
    }

    private function walkDepsIntoHandles(string $handle, array &$handles, array &$visited): void
    {
        if (isset($visited[$handle])) return;
        $visited[$handle] = true;

        $path = $this->fieldsetDiskPath($handle);
        if (!File::exists($path)) return;

        $yaml = YAML::parse(File::get($path));

        foreach ($this->extractDepHandles($yaml) as $dep) {
            if (!in_array($dep, $handles) && File::exists($this->fieldsetDiskPath($dep))) {
                $handles[] = $dep;
                $this->walkDepsIntoHandles($dep, $handles, $visited);
            }
        }
    }

    private function collectDeps(string $handle, array &$files, array &$visited): void
    {
        if (isset($visited[$handle])) return;
        $visited[$handle] = true;

        $path = $this->fieldsetDiskPath($handle);
        if (!File::exists($path)) return;

        $yaml = YAML::parse(File::get($path));

        foreach ($this->extractDepHandles($yaml) as $dep) {
            $depPath = $this->fieldsetDiskPath($dep);
            $key     = $this->fieldsetZipPath($dep);
            if (File::exists($depPath) && !isset($files[$key])) {
                $files[$key] = $depPath;
                $this->collectDeps($dep, $files, $visited);
            }
        }
    }

    private function extractDepHandles(array $yaml): array
    {
        $deps = [];
        array_walk_recursive($yaml, function ($value, $key) use (&$deps) {
            // - import: fieldset_handle (may be nested, e.g. hero.style_1)
            if ($key === 'import' && is_string($value)) {
                $deps[] = $value;
            }
            // field: fieldset_handle.field_name (fieldset handle may contain dots)
            if ($key === 'field' && is_string($value) && str_contains($value, '.')) {
                $deps[] = \Illuminate\Support\Str::beforeLast($value, '.');
            }
        });
        return array_unique($deps);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function selectionPath(): string
    {
        return storage_path('app/export-selection.json');
    }

    private function loadSelection(): array
    {
        $path = $this->selectionPath();
        if (!File::exists($path)) return ['page_sections' => []];
        return json_decode(File::get($path), true) ?? ['page_sections' => []];
    }

    private function saveSelection(array $data): void
    {
        File::put($this->selectionPath(), json_encode($data, JSON_PRETTY_PRINT));
    }

    private function resolveUniquePath(string $dest): string
    {
        if (!File::exists($dest)) return $dest;

        $dir  = dirname($dest);
        $ext  = pathinfo($dest, PATHINFO_EXTENSION);
        $base = pathinfo($dest, PATHINFO_FILENAME);

        $n = 2;
        do {
            $candidate = "{$dir}/{$base}_{$n}.{$ext}";
            $n++;
        } while (File::exists($candidate));

        return $candidate;
    }

    /** Replicator set handle → fieldset handle (`hero/style_1` → `hero.style_1`). */
    private function sectionToFieldset(string $handle): string
    {
        return str_replace('/', '.', $handle);
    }

    /** Prefer explicit `import:` in the set; fall back to slash→dot mapping. */
    private function fieldsetHandleFromSet(array $set, string $sectionHandle): string
    {
        foreach ($set['fields'] ?? [] as $field) {
            if (isset($field['import']) && is_string($field['import'])) {
                return $field['import'];
            }
        }

        return $this->sectionToFieldset($sectionHandle);
    }

    private function resolveFieldsetHandle(string $sectionHandle): string
    {
        $path = resource_path('fieldsets/page_sections.yaml');
        if (File::exists($path)) {
            $yaml = YAML::parse(File::get($path));
            $set  = data_get($yaml, "fields.0.field.sets.items.sets.{$sectionHandle}");
            if (is_array($set)) {
                return $this->fieldsetHandleFromSet($set, $sectionHandle);
            }
        }

        return $this->sectionToFieldset($sectionHandle);
    }

    private function fieldsetDiskPath(string $handle): string
    {
        return resource_path('fieldsets/' . str_replace('.', '/', $handle) . '.yaml');
    }

    private function fieldsetZipPath(string $handle): string
    {
        return 'resources/fieldsets/' . str_replace('.', '/', $handle) . '.yaml';
    }

    private function collectViewFiles(string $handle, array &$files): void
    {
        $base    = resource_path('views/partials/page_sections');
        $zipBase = 'resources/views/partials/page_sections';

        // Direct view file: e.g. hero/style_1 → hero/style_1.antlers.html
        $viewPath = "{$base}/{$handle}.antlers.html";
        if (File::exists($viewPath)) {
            $files["{$zipBase}/{$handle}.antlers.html"] = $viewPath;
        }

        // Sub-directory partials for handles without slashes (e.g. code_block → code_block/)
        if (!str_contains($handle, '/')) {
            $subDir = "{$base}/{$handle}";
            if (File::isDirectory($subDir)) {
                foreach (File::allFiles($subDir) as $file) {
                    $rel = $file->getRelativePathname();
                    $files["{$zipBase}/{$handle}/{$rel}"] = $file->getPathname();
                }
            }
        }
    }

    private function buildFilteredPageSections(array $selectedHandles): string
    {
        $path = resource_path('fieldsets/page_sections.yaml');
        $yaml = YAML::parse(File::get($path));

        $allSets  = data_get($yaml, 'fields.0.field.sets.items.sets', []);
        $filtered = array_filter($allSets, fn($k) => in_array($k, $selectedHandles), ARRAY_FILTER_USE_KEY);

        data_set($yaml, 'fields.0.field.sets.items.sets', $filtered);

        return YAML::dump($yaml);
    }
}
