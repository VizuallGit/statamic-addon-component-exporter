<?php

namespace Vizuall\ComponentExporter\Theme;

use MarioHamann\StatamicVisualEditor\TailwindTheme;

/**
 * Makes the theme tokens a package asked for and this site did not have.
 *
 * It only ever **adds**, and only a token whose status says this site is
 * missing it. One this site declares is left exactly as it is, whatever the
 * package says it should be, because a section that lands here is supposed to
 * take the colours it finds here. Nothing is reordered,
 * reformatted or removed: the new lines go in at the end of the last `@theme`
 * block, under a comment saying where they came from.
 *
 * site.css is the one file every page on the site reads its colours and sizes
 * from, so the write is checked before it happens: the file is parsed again
 * afterwards in memory, and unless it holds exactly what it held before plus
 * the new names, nothing is written at all.
 */
final class Writer
{
    /**
     * @param  list<array>  $tokens  compared entries (Tokens::compare)
     * @param  list<string>  $names  the ones the editor chose
     * @return array{written: list<string>, skipped: list<string>, reason: ?string}
     */
    public static function add(array $tokens, array $names): array
    {
        $written = [];
        $skipped = [];

        if (! $names) {
            return ['written' => [], 'skipped' => [], 'reason' => null];
        }

        $path = TailwindTheme::path();

        if ($path === null || ! is_writable($path)) {
            return ['written' => [], 'skipped' => $names, 'reason' => 'theme-not-writable'];
        }

        $css = (string) file_get_contents($path);
        $before = Tokens::parse($css);
        $lines = [];

        foreach ($tokens as $token) {
            $name = (string) ($token['name'] ?? '');

            if (! in_array($name, $names, true)) {
                continue;
            }

            // Asked for or not: only a token this site is missing may be made.
            // The caller checks too; this file is worth two locks.
            if (! in_array($token['status'] ?? null, [Tokens::MISSING, Tokens::STEP_MISSING], true)) {
                $skipped[] = $name;

                continue;
            }

            foreach (static::declarationsFor($token, $before, $lines) as $declaration => $value) {
                if (isset($before[$declaration]) || isset($lines[$declaration])) {
                    $skipped[] = $declaration;

                    continue;
                }

                $lines[$declaration] = $value;
                $written[] = $declaration;
            }
        }

        if (! $lines) {
            return ['written' => [], 'skipped' => $skipped, 'reason' => 'nothing-to-add'];
        }

        $updated = static::insert($css, $lines);

        if ($updated === null) {
            return ['written' => [], 'skipped' => $names, 'reason' => 'no-theme-block'];
        }

        $after = Tokens::parse($updated);

        // The file must hold what it held, plus exactly the new names.
        foreach ($before as $key => $value) {
            if (($after[$key] ?? null) !== $value) {
                return ['written' => [], 'skipped' => $names, 'reason' => 'would-change-existing'];
            }
        }

        if (count($after) !== count($before) + count($lines)) {
            return ['written' => [], 'skipped' => $names, 'reason' => 'unexpected-result'];
        }

        if (! static::put($path, $updated)) {
            return ['written' => [], 'skipped' => $names, 'reason' => 'write-failed'];
        }

        Tokens::flush();

        return ['written' => $written, 'skipped' => $skipped, 'reason' => null];
    }

    /**
     * What one chosen token becomes in the file: its own declaration, and for
     * a colour whose family this site has never seen, the family's base colour
     * as well — so the theme panel shows a family with a step rather than an
     * orphan.
     *
     * @param  array<string, string>  $before  this site's tokens
     * @param  array<string, string>  $pending  what earlier tokens already added
     * @return array<string, string> declaration name (without `--`) => value
     */
    private static function declarationsFor(array $token, array $before, array $pending): array
    {
        $name = (string) $token['name'];
        $kind = (string) ($token['kind'] ?? Tokens::KIND_OTHER);
        $out = [];

        if ($kind === Tokens::KIND_COLOR) {
            $family = (string) ($token['family'] ?? '');
            $base = $token['base'] ?? null;

            if ($family !== '' && is_string($base) && ! isset($before['color-'.$family], $pending['color-'.$family])) {
                $out['color-'.$family] = $base;
            }

            if (is_string($token['value'] ?? null)) {
                $out[$name] = $token['value'];
            }

            return $out;
        }

        if ($kind === Tokens::KIND_SIZE) {
            $value = Sizes::rebuild($token, $before['container-width'] ?? null);

            if ($value !== null) {
                $out[$name] = $value;
            }

            return $out;
        }

        if (is_string($token['value'] ?? null)) {
            $out[$name] = $token['value'];
        }

        return $out;
    }

    /**
     * The new lines at the end of the last `@theme` block, indented like the
     * line above them. Null when the file has no such block.
     *
     * @param  array<string, string>  $lines
     */
    public static function insert(string $css, array $lines): ?string
    {
        $end = null;

        foreach (static::themeBlocks($css) as $block) {
            $end = $block;
        }

        if ($end === null) {
            return null;
        }

        $indent = static::indentOf($css, $end);
        $body = '';

        foreach ($lines as $name => $value) {
            $body .= $indent.'--'.$name.': '.$value.";\n";
        }

        $comment = $indent."/* Tilføjet af Komponent Eksport ved import. */\n";

        return substr($css, 0, $end).$comment.$body.substr($css, $end);
    }

    /**
     * The offset of each `@theme` block's closing brace.
     *
     * @return list<int>
     */
    private static function themeBlocks(string $css): array
    {
        $out = [];
        $offset = 0;

        while (($pos = strpos($css, '@theme', $offset)) !== false) {
            $brace = strpos($css, '{', $pos);

            if ($brace === false) {
                break;
            }

            $depth = 0;
            $length = strlen($css);

            for ($i = $brace; $i < $length; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $out[] = $i;
                        $offset = $i + 1;

                        continue 2;
                    }
                }
            }

            break;
        }

        return $out;
    }

    /** The indent of the last declaration before the closing brace, or four spaces. */
    private static function indentOf(string $css, int $end): string
    {
        $body = substr($css, 0, $end);

        if (preg_match('/\n([ \t]+)--[\w-]+\s*:[^;]*;\s*$/', $body, $m)) {
            return $m[1];
        }

        return '    ';
    }

    /** Written beside the file and moved into place, so a page never reads half a theme. */
    private static function put(string $path, string $contents): bool
    {
        $temp = $path.'.ce-'.bin2hex(random_bytes(4));

        if (@file_put_contents($temp, $contents) === false) {
            @unlink($temp);

            return false;
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }
}
