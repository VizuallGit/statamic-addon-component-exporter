<?php

namespace Vizuall\ComponentExporter;

/**
 * The section types ticked for export, remembered between visits
 * (`storage/app/export-selection.json`), so a selection can be built up over
 * several sessions and exported once.
 */
final class Selection
{
    /** @return array{page_sections: list<string>} */
    public static function load(): array
    {
        $path = static::path();

        if (! is_file($path)) {
            return ['page_sections' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return ['page_sections' => array_values(array_filter((array) ($data['page_sections'] ?? []), 'is_string'))];
    }

    /** @return array{page_sections: list<string>} */
    public static function toggle(string $handle): array
    {
        $data = static::load();

        if ($handle === '') {
            return $data;
        }

        $index = array_search($handle, $data['page_sections'], true);

        if ($index === false) {
            $data['page_sections'][] = $handle;
        } else {
            array_splice($data['page_sections'], $index, 1);
        }

        return static::save($data);
    }

    /**
     * @param  list<string>  $handles
     * @return array{page_sections: list<string>}
     */
    public static function replace(array $handles): array
    {
        return static::save(['page_sections' => array_values(array_filter($handles, 'is_string'))]);
    }

    private static function save(array $data): array
    {
        $path = static::path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $data;
    }

    private static function path(): string
    {
        return storage_path('app/export-selection.json');
    }
}
