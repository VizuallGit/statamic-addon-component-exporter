<?php

namespace Vizuall\ComponentExporter\Section;

use Illuminate\Support\Str;
use Statamic\Facades\Fieldset;

/**
 * The page-builder registry: the Replicator field whose grouped sets are the
 * site's section types (`resources/fieldsets/page_sections.yaml` by default).
 *
 * Statamic stores the sets grouped — `sets: { hero: { display, sets: {…} } }` —
 * and a section type keeps its group when it travels, so the library on the
 * receiving site files it where the sending site did. Reading here is by
 * handle across every group; writing merges one set into one group and leaves
 * everything else in the file as it was.
 */
final class Registry
{
    /** The fieldset the page builder imports. Same setting the Visual Editor reads. */
    public static function fieldsetHandle(): string
    {
        return (string) config('statamic-visual-editor.previews.field', 'page_sections');
    }

    /** The registry file, relative to the site root. */
    public static function relativePath(): string
    {
        return Paths::relative(FieldsetChain::path(static::fieldsetHandle()));
    }

    /**
     * Every group: key => ['display' => …, 'sets' => [handle => set]].
     *
     * @return array<string, array{display: ?string, sets: array<string, array>}>
     */
    public static function groups(): array
    {
        $contents = static::contents();

        if ($contents === null || ($index = static::fieldIndex($contents)) === null) {
            return [];
        }

        $groups = [];

        foreach ((array) ($contents['fields'][$index]['field']['sets'] ?? []) as $key => $group) {
            if (! is_array($group)) {
                continue;
            }

            $sets = [];

            foreach ((array) ($group['sets'] ?? []) as $handle => $set) {
                if (is_array($set)) {
                    $sets[(string) $handle] = $set;
                }
            }

            $groups[(string) $key] = [
                'display' => isset($group['display']) ? (string) $group['display'] : null,
                'sets' => $sets,
            ];
        }

        return $groups;
    }

    /**
     * Every section type by handle, with the group it sits in.
     *
     * @return array<string, array{group: string, group_display: ?string, set: array}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (static::groups() as $key => $group) {
            foreach ($group['sets'] as $handle => $set) {
                $entries[$handle] = [
                    'group' => $key,
                    'group_display' => $group['display'],
                    'set' => $set,
                ];
            }
        }

        return $entries;
    }

    /** @return array{group: string, group_display: ?string, set: array}|null */
    public static function entry(string $handle): ?array
    {
        return static::entries()[$handle] ?? null;
    }

    /**
     * Files one set into one group and saves. The group is created when the
     * file does not have it; an existing set with the same handle is replaced.
     * The rest of the file — other groups, other sets, the field's own config —
     * is written back untouched.
     */
    public static function merge(string $group, ?string $groupDisplay, string $handle, array $set): void
    {
        $fieldset = static::fieldsetHandle();
        $existing = Fieldset::find($fieldset);
        $contents = $existing ? (array) $existing->contents() : static::skeleton();
        $index = static::fieldIndex($contents);

        if ($index === null) {
            $contents['fields'][] = static::skeleton()['fields'][0];
            $index = count($contents['fields']) - 1;
        }

        $groups = (array) ($contents['fields'][$index]['field']['sets'] ?? []);

        if (! isset($groups[$group]) || ! is_array($groups[$group])) {
            $groups[$group] = [
                'display' => $groupDisplay ?: Str::title(str_replace('_', ' ', $group)),
                'sets' => [],
            ];
        }

        $groups[$group]['sets'][$handle] = $set;
        $contents['fields'][$index]['field']['sets'] = $groups;

        // Statamic's repository hands out one instance per handle for the life of
        // the request and does not refresh it on save. Writing through that same
        // instance keeps every later read in this request — the import summary,
        // the library — on the merged registry, not the one from before.
        ($existing ?? Fieldset::make($fieldset))->setContents($contents)->save();
    }

    /** @return array<string, mixed>|null */
    private static function contents(): ?array
    {
        $fieldset = Fieldset::find(static::fieldsetHandle());

        return $fieldset ? (array) $fieldset->contents() : null;
    }

    /** The first Replicator field with sets — the page builder, whatever its handle. */
    private static function fieldIndex(array $contents): ?int
    {
        foreach ((array) ($contents['fields'] ?? []) as $i => $field) {
            $config = $field['field'] ?? null;

            if (is_array($config) && ($config['type'] ?? null) === 'replicator' && array_key_exists('sets', $config)) {
                return (int) $i;
            }
        }

        return null;
    }

    /** A registry with no section types yet, shaped like the one the starter kit writes. */
    private static function skeleton(): array
    {
        return [
            'title' => 'Page sections',
            'fields' => [
                [
                    'handle' => 'page_sections',
                    'field' => [
                        'type' => 'replicator',
                        'display' => 'Page sections',
                        'collapse' => 'accordion',
                        'sets' => [],
                    ],
                ],
            ],
        ];
    }
}
