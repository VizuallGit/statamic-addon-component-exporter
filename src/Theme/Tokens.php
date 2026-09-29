<?php

namespace Vizuall\ComponentExporter\Theme;

use MarioHamann\StatamicVisualEditor\TailwindTheme;
use Vizuall\ComponentExporter\Section\Paths;

/**
 * The theme tokens a section or unit needs from the site it lands on.
 *
 * Its files travel; the theme does not. A hero that paints itself with
 * `var(--secondary-600)` looks right here and colourless on a site whose theme
 * never had a secondary. This says which tokens it asks for, so the import can
 * say so before it writes anything.
 *
 * A token is asked for when the files reference it and none of them declares
 * it. The section's own `--color-bg` is declared in its own `style_push` and is
 * nobody else's business; `--secondary-600` is not. Baked Tailwind passes
 * tokens through — `--color-primary: var(--primary)` both declares and
 * references — and the subtraction lands on `--primary`, which is the name the
 * site's `{{ theme_tokens }}` tag actually puts on `:root`.
 *
 * A value that points at another token pulls that one in too, so
 * `--spacing-900: var(--size-900)` reports the size a new site would have to
 * have, not just the alias.
 *
 * Reading matches the two readers that already exist — the site's ThemeTokens
 * tag and color-scheme's SiteCssColors — so all three agree on what `@theme`
 * says.
 */
final class Tokens
{
    public const KIND_COLOR = 'color';

    public const KIND_SIZE = 'size';

    public const KIND_OTHER = 'other';

    /** This site has the token. */
    public const PRESENT = 'present';

    /** This site has it under another value — the section takes this site's. */
    public const DIFFERS = 'differs';

    /** The colour family is here, this step of it is not. */
    public const STEP_MISSING = 'step_missing';

    /** Nothing here by that name. */
    public const MISSING = 'missing';

    /** No theme has it: it comes from somewhere else, like var.css. */
    public const UNKNOWN = 'unknown';

    /**
     * A `--name` in a value or a declaration.
     *
     * The quantifier is possessive on purpose: a template writes
     * `var(--size-{{ settings.size }})`, and a name built at render time is
     * not a name. Without it the engine backs off to `--size` and reports a
     * token nobody asked for.
     */
    private const VAR = '/var\(\s*--([\w-]++)(?!\{)/';

    private const DECL = '/--([\w-]++)(?!\{)\s*:/';

    private const STEP = '/^(.+)-(\d+)$/';

    /** @var array{0: int|false, 1: array<string, string>}|null */
    private static ?array $memo = null;

    /**
     * What a section's or unit's files ask of the theme.
     *
     * @param  list<array{path: string, role?: string}>  $files  manifest file list
     * @return list<array{
     *   name: string, kind: string, known: bool, value: ?string,
     *   family: ?string, step: ?int, base: ?string, min: ?string, max: ?string,
     *   used_by: list<string>, via: list<string>
     * }>
     */
    public static function forFiles(array $files): array
    {
        $referenced = [];
        $declared = [];

        foreach ($files as $file) {
            $relative = (string) ($file['path'] ?? '');
            $path = Paths::absolute($relative);

            if (! static::readable($relative) || ! is_file($path)) {
                continue;
            }

            $text = (string) file_get_contents($path);

            foreach (static::referencedIn($text) as $name) {
                $referenced[$name]['used_by'][] = (string) $file['path'];
            }

            foreach (static::declaredIn($text) as $name) {
                $declared[$name] = true;
            }
        }

        $theme = static::theme();
        $wanted = [];

        foreach ($referenced as $name => $found) {
            if (isset($declared[$name])) {
                continue;
            }

            static::pull($wanted, (string) $name, $theme, $found['used_by'], []);
        }

        $out = array_values($wanted);

        usort($out, fn ($a, $b) => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);

        return $out;
    }

    /**
     * Whether a file is worth reading for tokens. A preview PNG is not, and a
     * manifest lists one per section.
     */
    private static function readable(string $relative): bool
    {
        static $text = ['css', 'html', 'php', 'yaml', 'yml', 'js', 'json', 'md', 'antlers'];

        return in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), $text, true);
    }

    /**
     * Adds a token and, through its value, the tokens it leans on.
     *
     * @param  array<string, array>  $wanted  keyed by name
     * @param  array<string, string>  $theme
     * @param  list<string>  $usedBy  files that reference it
     * @param  list<string>  $via  the tokens that led here, outermost first
     */
    private static function pull(array &$wanted, string $name, array $theme, array $usedBy, array $via): void
    {
        $entry = static::describe($name, $theme);
        // `--secondary-600` and `--color-secondary-600` are one token under two
        // names; the theme's own name is the one that gets an entry.
        $name = $entry['name'];

        if (in_array($name, $via, true)) {
            // A theme that points at itself (`--size-100: var(--size-100)`).
            return;
        }

        if (isset($wanted[$name])) {
            $wanted[$name]['used_by'] = array_values(array_unique([...$wanted[$name]['used_by'], ...$usedBy]));

            if ($via && ! $wanted[$name]['via']) {
                $wanted[$name]['via'] = $via;
            }

            return;
        }

        $entry['used_by'] = array_values(array_unique($usedBy));
        $entry['via'] = $via;

        $wanted[$name] = $entry;

        if ($entry['value'] === null) {
            return;
        }

        preg_match_all(self::VAR, $entry['value'], $matches);

        foreach (array_unique($matches[1]) as $next) {
            static::pull($wanted, (string) $next, $theme, $usedBy, [...$via, $name]);
        }
    }

    /**
     * One token as this site's theme has it.
     *
     * `var(--secondary-600)` and `var(--color-secondary-600)` are the same
     * token under two names — the site's tag puts both on `:root` — so a short
     * name is looked up under `color-` first. `known: false` means the theme
     * says nothing about it: either the site brings it from elsewhere
     * (`--gutter` lives in var.css) or nobody has it.
     *
     * @param  array<string, string>  $theme
     * @return array{name: string, token: ?string, kind: string, known: bool, value: ?string, family: ?string, step: ?int, base: ?string, min: ?string, max: ?string}
     */
    public static function describe(string $name, array $theme): array
    {
        $token = isset($theme['color-'.$name]) ? 'color-'.$name : $name;
        $value = $theme[$token] ?? null;

        $entry = [
            'name' => $value === null ? $name : $token,
            'token' => $value === null ? null : $token,
            'kind' => self::KIND_OTHER,
            'known' => $value !== null,
            'value' => $value,
            'family' => null,
            'step' => null,
            'base' => null,
            'min' => null,
            'max' => null,
        ];

        if ($value === null) {
            return $entry;
        }

        if (str_starts_with($token, 'color-')) {
            $entry['kind'] = self::KIND_COLOR;
            $family = substr($token, 6);

            if (preg_match(self::STEP, $family, $m)) {
                $entry['family'] = $m[1];
                $entry['step'] = (int) $m[2];
            } else {
                $entry['family'] = $family;
            }

            // The family's own colour, so a site without it can make the scale
            // from the same base instead of one borrowed step.
            $entry['base'] = $theme['color-'.$entry['family']] ?? null;

            return $entry;
        }

        if (str_starts_with($token, 'size-')) {
            $entry['kind'] = self::KIND_SIZE;
            [$entry['min'], $entry['max']] = static::clampEnds($value);
        }

        return $entry;
    }

    /**
     * The ends of a fluid size: `clamp(1.5rem, 1.29rem + 1.04vw, 2.125rem)` is
     * 1.5rem on a phone and 2.125rem at the container width. A size that is
     * one plain value is both ends.
     *
     * The slope between them is not carried: it is only true for the container
     * width it was made against, and a site with a wider container has to work
     * its own out.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function clampEnds(string $value): array
    {
        $value = trim($value);

        if (! preg_match('/^clamp\((.*)\)$/s', $value, $m)) {
            return [$value, $value];
        }

        $parts = static::splitArgs($m[1]);

        if (count($parts) !== 3) {
            return [null, null];
        }

        return [trim($parts[0]), trim($parts[2])];
    }

    /** Top-level commas only, so `calc(a, b)` inside an argument stays whole. */
    private static function splitArgs(string $args): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($args) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        $parts[] = $current;

        return $parts;
    }

    /** @return list<string> */
    public static function referencedIn(string $text): array
    {
        preg_match_all(self::VAR, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    public static function declaredIn(string $text): array
    {
        preg_match_all(self::DECL, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * This site's `@theme`: name without `--` => value, in file order.
     *
     * @return array<string, string>
     */
    public static function theme(): array
    {
        $path = TailwindTheme::path();
        $mtime = $path === null ? false : @filemtime($path);

        if (static::$memo !== null && static::$memo[0] === $mtime) {
            return static::$memo[1];
        }

        $tokens = $path === null ? [] : static::parse((string) @file_get_contents($path));
        static::$memo = [$mtime, $tokens];

        return $tokens;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $css): array
    {
        preg_match_all('/@theme\b[^{]*\{([^}]*)\}/', $css, $blocks);

        $tokens = [];

        foreach ($blocks[1] as $block) {
            $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);

            preg_match_all('/--([\w-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $name, $value]) {
                $value = trim($value);

                if ($value !== 'initial') {
                    $tokens[$name] = $value;
                }
            }
        }

        return $tokens;
    }

    /**
     * A package's tokens against this site's theme, for the import review.
     *
     * Nothing is written and nothing is overwritten: a token this site already
     * has wins, even when its value differs, because the point of importing a
     * section is that it takes on the theme it lands in. Only what is missing
     * can be offered.
     *
     * @param  list<array>  $wanted  token entries as the package recorded them
     * @param  array<string, string>|null  $theme  this site's, read when not given
     * @return list<array> the entries with `status`, `here` and `here_base`
     */
    public static function compare(array $wanted, ?array $theme = null): array
    {
        $theme ??= static::theme();
        $out = [];

        foreach ($wanted as $token) {
            $name = (string) ($token['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $mine = static::describe($name, $theme);
            $family = $token['family'] ?? $mine['family'];
            $familyHere = is_string($family) ? ($theme['color-'.$family] ?? null) : null;

            $out[] = [
                ...$token,
                'status' => static::status($token, $mine, $familyHere),
                'here' => $mine['value'],
                'here_base' => $familyHere,
            ];
        }

        return $out;
    }

    /**
     * @param  array  $token  as the package recorded it
     * @param  array  $mine  the same name read from this site
     */
    private static function status(array $token, array $mine, ?string $familyHere): string
    {
        if (! ($token['known'] ?? false)) {
            // The site it came from did not have it either: it is not a theme
            // token at all — `--gutter` lives in var.css — so importing a
            // theme cannot help, and saying "missing" would be a lie.
            return self::UNKNOWN;
        }

        if ($mine['known']) {
            return static::same((string) $token['value'], (string) $mine['value'])
                ? self::PRESENT
                : self::DIFFERS;
        }

        if (($token['kind'] ?? null) === self::KIND_COLOR && $familyHere !== null) {
            return self::STEP_MISSING;
        }

        return self::MISSING;
    }

    /** Values that only differ in spacing or case are the same value. */
    private static function same(string $a, string $b): bool
    {
        $normalise = fn (string $v) => strtolower((string) preg_replace('/\s+/', '', $v));

        return $normalise($a) === $normalise($b);
    }

    /** The width a fluid size grows to here, so another site can redo the maths. */
    public static function containerWidth(): ?string
    {
        return static::theme()['container-width'] ?? null;
    }

    public static function flush(): void
    {
        static::$memo = null;
    }
}
