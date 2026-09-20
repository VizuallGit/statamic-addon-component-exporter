<?php

namespace Vizuall\ComponentExporter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vizuall\ComponentExporter\Export\Extras;
use Vizuall\ComponentExporter\Export\Package;
use Vizuall\ComponentExporter\Import\Importer;
use Vizuall\ComponentExporter\Import\Inspector;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Registry;
use Vizuall\ComponentExporter\Selection;

/**
 * HTTP in, JSON or a download out. What a section consists of is Manifest's
 * business; what a package is, Package's; what happens on import, Inspector's
 * and Importer's. This class only translates.
 */
class ComponentExporterController
{
    /** Everything the picker shows: section types by group, blueprints, collections, the remembered selection. */
    public function items(): JsonResponse
    {
        $groups = [];

        foreach (Registry::groups() as $key => $group) {
            $sections = [];

            foreach (array_keys($group['sets']) as $handle) {
                if (! $manifest = Manifest::forSection((string) $handle)) {
                    continue;
                }

                $shared = [];

                foreach ($manifest['files'] as $file) {
                    if ($file['shared']) {
                        $shared[] = ['label' => $file['label'], 'role' => $file['role'], 'path' => $file['path']];
                    }
                }

                $sections[] = [
                    'handle' => $manifest['handle'],
                    'display' => $manifest['display'],
                    'static' => $manifest['static'],
                    'hidden' => ($manifest['set']['hide'] ?? false) === true,
                    'files' => count($manifest['files']),
                    'own' => count($manifest['files']) - count($shared),
                    'shared' => $shared,
                    'missing' => $manifest['missing'],
                ];
            }

            $groups[] = ['key' => $key, 'display' => $group['display'] ?? $key, 'sections' => $sections];
        }

        return response()->json([
            'groups' => $groups,
            'blueprints' => Extras::blueprints(),
            'collections' => Extras::collections(),
            'selection' => Selection::load(),
        ]);
    }

    public function export(Request $request)
    {
        $sections = array_values(array_filter((array) $request->input('sections', []), 'is_string'));
        $extras = array_values(array_filter((array) $request->input('extras', []), 'is_string'));

        try {
            $package = Package::build($sections, $extras);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Vælg mindst én sektion, blueprint eller collection.'], 422);
        }

        return response()->download($package['path'], $package['filename'])->deleteFileAfterSend(true);
    }

    public function inspect(Request $request): JsonResponse
    {
        $request->validate(['zip' => 'required|file']);

        try {
            return response()->json(Inspector::inspect($request->file('zip')->getPathname()));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['zip' => 'required|file']);

        $choices = json_decode((string) $request->input('choices', '{}'), true);

        if (! is_array($choices)) {
            return response()->json(['error' => 'Ugyldige valg.'], 422);
        }

        try {
            $result = Importer::apply($request->file('zip')->getPathname(), $choices);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result + [
            'message' => sprintf(
                '%d fil(er) skrevet, %d beholdt%s%s.',
                count($result['written']),
                count($result['skipped']),
                $result['registered'] ? ', registreret: '.implode(', ', $result['registered']) : '',
                $result['rejected'] ? ', afvist: '.implode(', ', $result['rejected']) : ''
            ),
        ]);
    }

    public function selection(): JsonResponse
    {
        return response()->json(Selection::load());
    }

    public function toggleSelection(Request $request): JsonResponse
    {
        if ($request->has('set')) {
            return response()->json(Selection::replace((array) $request->input('set', [])));
        }

        return response()->json(Selection::toggle((string) $request->input('handle', '')));
    }
}
