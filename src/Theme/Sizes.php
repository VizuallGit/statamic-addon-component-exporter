<?php

namespace Vizuall\ComponentExporter\Theme;

/**
 * A fluid size, worked out against the container width of the site it lands on.
 *
 * `--size-700: clamp(2.375rem, 2rem + 1.875vw, 3.5rem)` is 38 px on a phone and
 * 56 px from the container width up. The slope in the middle is only true for
 * the width it was made against, so a package carries the two ends and the site
 * that receives it does the sum again with its own container. Copy the clamp
 * instead and a site with a wider container never reaches the size it was
 * drawn at.
 *
 * Same formula as the Visual Editor theme panel's `sizes.js` and the fluid-size
 * fieldtype, number for number, so a size made here reads like one made there.
 */
final class Sizes
{
    /** Where every size is at its smallest, as in FluidSize. */
    public const MIN_VIEWPORT = 320;

    private const REM = 16;

    /**
     * The ends of a size in px: a `clamp(min, …, max)` or one fixed length
     * (both ends the same). Null for anything else.
     *
     * @return array{min: float, max: float}|null
     */
    public static function ends(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^clamp\(\s*([^,]+),\s*([^,]+),\s*([^,)]+)\)$/i', $value, $m)) {
            $min = static::px($m[1]);
            $max = static::px($m[3]);

            return $min === null || $max === null ? null : ['min' => $min, 'max' => $max];
        }

        $fixed = static::px($value);

        return $fixed === null ? null : ['min' => $fixed, 'max' => $fixed];
    }

    /**
     * The value to write: fluid from MIN_VIEWPORT to `$maxViewport` px, or one
     * length when the two ends are the same.
     */
    public static function value(float $min, float $max, float $maxViewport): string
    {
        $minRem = static::num($min / self::REM);
        $maxRem = static::num($max / self::REM);
        $range = $maxViewport - self::MIN_VIEWPORT;

        if ($range <= 0 || abs($max - $min) <= 0.001) {
            return $minRem.'rem';
        }

        $slope = ($max - $min) / $range;
        $intercept = $min - $slope * self::MIN_VIEWPORT;

        return sprintf(
            'clamp(%srem, %srem + %svw, %srem)',
            $minRem,
            static::num($intercept / self::REM),
            static::num($slope * 100),
            $maxRem
        );
    }

    /**
     * A size from a package, redone for this site.
     *
     * @param  array  $token  a token entry, with `min` and `max` as written
     * @param  string|null  $containerWidth  this site's, e.g. `85.375rem`
     */
    public static function rebuild(array $token, ?string $containerWidth): ?string
    {
        $min = static::px((string) ($token['min'] ?? ''));
        $max = static::px((string) ($token['max'] ?? ''));

        if ($min === null || $max === null) {
            return null;
        }

        $viewport = $containerWidth === null ? null : static::px($containerWidth);

        if ($viewport === null || $viewport <= self::MIN_VIEWPORT) {
            // No container to grow to: keep the value the package carried.
            return is_string($token['value'] ?? null) ? $token['value'] : null;
        }

        return static::value($min, $max, $viewport);
    }

    /** `1.5rem` / `24px` in px. */
    public static function px(string $length): ?float
    {
        if (! preg_match('/^(-?[\d.]+)(rem|px)$/', trim($length), $m)) {
            return null;
        }

        return $m[2] === 'rem' ? (float) $m[1] * self::REM : (float) $m[1];
    }

    /** `round($n, 4)` printed as the panel prints it: 1.0 → "1", 0.93750 → "0.9375". */
    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format(round($n, 4), 4, '.', ''), '0'), '.') ?: '0';
    }
}
