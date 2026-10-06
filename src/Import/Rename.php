<?php

namespace Vizuall\ComponentExporter\Import;

use Illuminate\Support\Str;
use Statamic\Facades\YAML;
use Vizuall\ComponentExporter\Section\FieldsetChain;
use Vizuall\ComponentExporter\Section\Manifest;

/**
 * A section imported under a name of the editor's choosing.
 *
 * The display name is only the label in the set picker. The handle is what
 * the page loop renders (`partials/page_sections/{type}`), what a bare
 * `{{ sve_tw }}` reads (`tw/{type}.css`), what `_class` is made from and what
 * the preview generator names its picture after — so a new handle moves the
 * section's own files with it:
 *
 *   template  {base}/{old}.antlers.html     → {base}/{new}.antlers.html
 *   Tailwind  tw/{old}.css                  → tw/{new}.css
 *   fieldset  the set's `import:` named after the section → named after the new handle
 *   preview   {old-dashed}-{hash}.png + .meta → {new-dashed}-{hash}.png + .meta
 *
 * Nothing shared moves. A fieldset another section in the package imports,
 * or another fieldset of this section refers to, keeps its name and the set
 * keeps pointing at it. A template or Tailwind file another section uses
 * cannot move, and neither can a section that is a folder of templates —
 * those name each other by path — so the handle is refused, not half-done.
 *
 * No file is rewritten: the paths change, the contents travel as they are.
 */
final class Rename
{
    /** `hero/intro`, `event_list`: lowercase words joined by `_`, folders by `/`. */
    private const HANDLE = '#^[a-z][a-z0-9_]*(/[a-z][a-z0-9_]*)*$#';

    /**
     * One plan per section in the package, keyed by the handle it was exported under.
     *
     * @param  list<array>  $sections  the manifest's sections
     * @param  array<string, mixed>  $wanted  exported handle => {handle?, display?}
     * @param  array<string, list<string>>  $usedBy  package path => what in the package uses it
     * @param  callable(string): ?string  $read  a package file's contents
     * @return array<string, array{handle: string, display: string, set: array, paths: array<string, string>, error: ?string, notes: list<string>}>
     */
    public static function plans(array $sections, array $wanted, array $usedBy, callable $read): array
    {
        $plans = [];

        foreach ($sections as $section) {
            $from = (string) $section['handle'];
            $plans[$from] = static::plan($section, (array) ($wanted[$from] ?? []), $usedBy, $read);
        }

        // Two sections cannot land on one handle: the second would replace the first.
        $landing = array_count_values(array_column($plans, 'handle'));

        foreach ($sections as $section) {
            $from = (string) $section['handle'];
            $to = $plans[$from]['handle'];

            if ($to !== $from && $landing[$to] > 1) {
                $kept = static::plan($section, ['display' => $wanted[$from]['display'] ?? ''], $usedBy, $read);
                $plans[$from] = static::refused($kept, sprintf('En anden sektion i pakken hedder allerede %s.', $to));
            }
        }

        return $plans;
    }

    /** Where each package path is written, for the sections that move any. */
    public static function targets(array $plans): array
    {
        return array_merge([], ...array_column($plans, 'paths'));
    }

    private static function plan(array $section, array $wanted, array $usedBy, callable $read): array
    {
        $from = (string) $section['handle'];
        $set = (array) ($section['set'] ?? []);
        $display = trim((string) ($wanted['display'] ?? ''));
        $to = trim((string) ($wanted['handle'] ?? ''));

        if ($display !== '' && $display !== (string) ($section['display'] ?? $from)) {
            $set['display'] = $display;
        }

        $plan = [
            'handle' => $from,
            'display' => (string) ($set['display'] ?? $from),
            'set' => $set,
            'paths' => [],
            'error' => null,
            'notes' => [],
        ];

        if ($to === '' || $to === $from) {
            return $plan;
        }

        if (! preg_match(self::HANDLE, $to)) {
            return static::refused($plan, 'Handlen må kun have små bogstaver, tal og _, og / mellem gruppe og navn, fx hero/intro.');
        }

        if ($to === Manifest::globalSectionSet()) {
            return static::refused($plan, sprintf('%s er Visual Editorens eget sæt og kan ikke bruges.', $to));
        }

        $files = array_values(array_filter((array) ($section['files'] ?? []), fn ($file) => is_array($file) && isset($file['path'])));
        $others = fn (string $path) => array_values(array_diff($usedBy[$path] ?? [], [$from]));
        $base = Manifest::sectionPartials();
        $paths = [];
        $templates = [];

        foreach ($files as $file) {
            $path = (string) $file['path'];
            $role = (string) ($file['role'] ?? '');

            if ($role === Manifest::ROLE_PARTIAL && str_starts_with($path, $base.'/'.$from.'/')) {
                return static::refused($plan, 'Sektionen er en mappe af skabeloner, der henviser til hinanden ved sti. Navnet kan ændres her, handlen ikke.');
            }

            if ($role === Manifest::ROLE_PARTIAL) {
                foreach (['.antlers.html', '.blade.php'] as $extension) {
                    if ($path === $base.'/'.$from.$extension) {
                        $paths[$path] = $base.'/'.$to.$extension;
                        $templates[] = $path;
                    }
                }
            }

            // The section's own baked Tailwind is the store key named after it (Manifest::css).
            if ($role === Manifest::ROLE_CSS && ($file['label'] ?? null) === $from && str_ends_with($path, '/'.$from.'.css')) {
                $paths[$path] = substr($path, 0, -strlen($from.'.css')).$to.'.css';
            }
        }

        foreach (array_keys($paths) as $path) {
            if ($users = $others($path)) {
                return static::refused($plan, sprintf('%s bruges også af %s og kan ikke flytte med.', basename($path), implode(', ', $users)));
            }
        }

        $paths += static::fieldsets($set, $files, $from, $to, $others, $read);
        $paths += static::preview($set, $files, $from, $to);

        return [
            'handle' => $to,
            'set' => $set,
            'paths' => $paths,
            'notes' => static::classNotes($templates, $from, $to, $read),
        ] + $plan;
    }

    /**
     * The set's own fieldsets, renamed after the new handle: `hero.style_2`
     * for `hero/style_2` becomes `hero.intro` for `hero/intro`; a fieldset in
     * a folder of its own (`featured_sections.style_1` for
     * `featured_section/style_1`) keeps its folder and takes the new name.
     * Rewrites the set's `import:` to match.
     *
     * @return array<string, string> package path => target path
     */
    private static function fieldsets(array &$set, array $files, string $from, string $to, callable $others, callable $read): array
    {
        $byHandle = [];

        foreach ($files as $file) {
            if (($file['role'] ?? '') === Manifest::ROLE_FIELDSET && isset($file['label'])) {
                $byHandle[(string) $file['label']] = (string) $file['path'];
            }
        }

        $paths = [];
        $imports = [];

        foreach (FieldsetChain::rootsOf($set) as $root) {
            $renamed = static::fieldsetName($root, $from, $to);
            $path = $byHandle[$root] ?? null;
            $suffix = str_replace('.', '/', $root).'.yaml';

            if ($renamed === $root || $path === null || ! str_ends_with($path, '/'.$suffix) || $others($path)) {
                continue;
            }

            if (static::referencedByAnother($root, $byHandle, $read)) {
                continue;
            }

            $paths[$path] = substr($path, 0, -strlen($suffix)).str_replace('.', '/', $renamed).'.yaml';
            $imports[$root] = $renamed;
        }

        foreach ((array) ($set['fields'] ?? []) as $i => $field) {
            if (is_array($field) && is_string($field['import'] ?? null) && isset($imports[$field['import']])) {
                $set['fields'][$i]['import'] = $imports[$field['import']];
            }
        }

        return $paths;
    }

    private static function fieldsetName(string $root, string $from, string $to): string
    {
        if ($root === str_replace('/', '.', $from)) {
            return str_replace('/', '.', $to);
        }

        if (Str::afterLast($root, '.') !== Str::afterLast($from, '/')) {
            return $root;
        }

        $folder = FieldsetChain::folder($root);

        return ($folder === '' ? '' : $folder.'.').Str::afterLast($to, '/');
    }

    /** Whether another of the section's fieldsets imports or borrows from this one. */
    private static function referencedByAnother(string $root, array $byHandle, callable $read): bool
    {
        foreach ($byHandle as $handle => $path) {
            if ($handle === $root) {
                continue;
            }

            try {
                $contents = YAML::parse((string) $read($path));
            } catch (\Throwable) {
                continue;
            }

            if (is_array($contents) && in_array($root, FieldsetChain::dependencies($contents), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The preview carries the handle in its name the way the Visual Editor's
     * generator writes it, so it lands under the new name and never on top of
     * a picture this site already has for the old one.
     *
     * @return array<string, string> package path => target path
     */
    private static function preview(array &$set, array $files, string $from, string $to): array
    {
        $image = $set['image'] ?? null;
        $prefix = static::dashed($from).'-';

        if (! is_string($image) || ! str_starts_with($image, $prefix)) {
            return [];
        }

        $renamed = static::dashed($to).'-'.substr($image, strlen($prefix));
        $paths = [];

        foreach ($files as $file) {
            if (($file['role'] ?? '') !== Manifest::ROLE_PREVIEW) {
                continue;
            }

            $path = (string) $file['path'];

            foreach ([$image => $renamed, $image.'.yaml' => $renamed.'.yaml'] as $old => $new) {
                if (basename($path) === $old) {
                    $paths[$path] = substr($path, 0, -strlen($old)).$new;
                }
            }
        }

        $set['image'] = $renamed;

        return $paths;
    }

    /**
     * A template that writes its class out instead of using `{{ _class }}`
     * keeps that class. That is fine as long as it never uses `_class` too:
     * then `_class` is the new name and rules written to the old one miss.
     *
     * @param  list<string>  $templates
     * @return list<string>
     */
    private static function classNotes(array $templates, string $from, string $to, callable $read): array
    {
        $old = static::dashed($from);
        $notes = [];

        foreach ($templates as $path) {
            $contents = (string) $read($path);

            if (! preg_match('/(?<![\w-])'.preg_quote($old, '/').'(?![\w-])/', $contents)) {
                continue;
            }

            $notes[] = preg_match('/\b_class\b/', $contents)
                ? sprintf('Skabelonen bruger både {{ _class }} og klassen .%s direkte. Efter omdøbningen er {{ _class }} .%s, så regler skrevet til .%s rammer ikke længere.', $old, static::dashed($to), $old)
                : sprintf('Skabelonen skriver klassen .%s direkte i stedet for {{ _class }}. Den beholdes, så sektionen ser ud som før.', $old);
        }

        return $notes;
    }

    /** `hero/style_2` → `hero-style-2`: the page loop's `_class`, and the start of the preview's name. */
    private static function dashed(string $handle): string
    {
        return str_replace(['/', '_'], '-', $handle);
    }

    /** A plan that keeps the handle the section came with, and says why. */
    private static function refused(array $plan, string $error): array
    {
        return ['error' => $error] + $plan;
    }
}
