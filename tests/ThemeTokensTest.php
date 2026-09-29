<?php

namespace Vizuall\ComponentExporter\Tests;

use PHPUnit\Framework\Attributes\Test;
use Vizuall\ComponentExporter\Export\Package;
use Vizuall\ComponentExporter\Import\Inspector;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Theme\Tokens;
use ZipArchive;

/**
 * What a section asks of the theme it lands in, and what a site answers.
 *
 * The site here is deliberately small but shaped like the real one: an
 * `@theme` with a colour family, one step of it, a fluid size and an alias
 * that points at that size.
 */
class ThemeTokensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tokens::flush();
    }

    protected function tearDown(): void
    {
        Tokens::flush();

        parent::tearDown();
    }

    private function theme(string $extra = ''): void
    {
        $this->file('resources/css/site.css', <<<CSS
        @theme {
            --color-primary: #11122C;
            --color-secondary: #061b6f;
            --color-secondary-600: #24418d;
            --color-gray-600: #525252;
            --size-700: clamp(2.375rem, 2rem + 1.875vw, 3.5rem);
            --spacing-700: var(--size-700);
            --font-heading: 'Merriweather', serif;
            --container-width: 85.375rem;
            --text-*: initial;
            $extra
        }
        CSS);

        Tokens::flush();
    }

    #[Test]
    public function it_reads_the_theme_and_skips_initial()
    {
        $this->theme();

        $theme = Tokens::theme();

        $this->assertSame('#24418d', $theme['color-secondary-600']);
        $this->assertSame('85.375rem', $theme['container-width']);
        $this->assertArrayNotHasKey('text-*', $theme, 'A reset is not a token.');
        $this->assertSame('85.375rem', Tokens::containerWidth());
    }

    #[Test]
    public function a_section_asks_for_what_it_references_and_does_not_declare()
    {
        $this->theme();

        $this->file('resources/views/partials/page_sections/hero/tokens.antlers.html', <<<'HTML'
        <section id="id-{{ id }}"><h1>{{ title }}</h1></section>
        {{ style_push }}
        <style>
            #id-{{ id }} {
                --color-bg: var(--secondary-600);
                --media-width: 40%;
            }
            @scope(.hero-tokens) {
                :scope { background: var(--color-bg); padding-block: var(--size-700); }
                .media { inline-size: var(--media-width); }
            }
        </style>
        {{ /style_push }}
        HTML);

        $tokens = Tokens::forFiles([
            ['path' => 'resources/views/partials/page_sections/hero/tokens.antlers.html', 'role' => 'partial'],
        ]);

        $names = array_column($tokens, 'name');

        $this->assertContains('color-secondary-600', $names, 'A theme colour it paints with, under the name the theme has for it.');
        $this->assertContains('size-700', $names, 'A fluid size it spaces with.');
        $this->assertNotContains('color-bg', $names, 'It declares that one itself.');
        $this->assertNotContains('media-width', $names, 'And that one.');
    }

    #[Test]
    public function baked_tailwind_asks_for_the_token_its_pass_through_points_at()
    {
        $this->theme();

        // What the dock's compiler writes: the Tailwind name is declared here
        // and points at the site's own, which is the real dependency.
        $this->file('resources/visual-editor/tw/hero/tokens.css', <<<'CSS'
        @layer theme {
          :root, :host {
            --color-primary: var(--primary);
            --color-black: #000000;
            --spacing-700: var(--size-700);
          }
        }
        @layer utilities {
          .bg-primary { background-color: var(--color-primary); }
          .p-700 { padding: var(--spacing-700); }
          .text-black { color: var(--color-black); }
        }
        CSS);

        $tokens = Tokens::forFiles([['path' => 'resources/visual-editor/tw/hero/tokens.css', 'role' => 'css']]);
        $names = array_column($tokens, 'name');

        $this->assertContains('color-primary', $names, 'Through `var(--primary)`, which the theme owns.');
        $this->assertContains('size-700', $names, 'Through the spacing alias.');
        $this->assertNotContains('color-black', $names, 'A literal needs nothing.');
        $this->assertNotContains('spacing-700', $names, 'The bake declares the Tailwind name itself.');

        $size = $tokens[array_search('size-700', $names, true)];
        $this->assertSame('2.375rem', $size['min']);
        $this->assertSame('3.5rem', $size['max']);
        $this->assertSame(Tokens::KIND_SIZE, $size['kind']);
    }

    #[Test]
    public function a_name_the_template_builds_at_render_time_is_not_a_token()
    {
        $this->theme();

        $this->file('resources/views/partials/page_sections/hero/dynamic.antlers.html', <<<'HTML'
        {{ style_push }}
        <style>
            #id-{{ id }} { padding-block: var(--size-{{ settings.size }}); gap: var(--size-700); }
        </style>
        {{ /style_push }}
        HTML);

        $names = array_column(Tokens::forFiles([
            ['path' => 'resources/views/partials/page_sections/hero/dynamic.antlers.html', 'role' => 'partial'],
        ]), 'name');

        $this->assertSame(['size-700'], $names, 'Only the one that is written out.');
    }

    #[Test]
    public function a_colour_carries_its_family_step_and_base()
    {
        $this->theme();

        $entry = Tokens::describe('secondary-600', Tokens::theme());

        $this->assertSame('color-secondary-600', $entry['name'], 'Written short, reported as the theme names it.');
        $this->assertSame(Tokens::KIND_COLOR, $entry['kind']);
        $this->assertSame('secondary', $entry['family']);
        $this->assertSame(600, $entry['step']);
        $this->assertSame('#24418d', $entry['value']);
        $this->assertSame('#061b6f', $entry['base'], 'So a site without the family can make it from the same base.');
    }

    #[Test]
    public function a_token_no_theme_has_is_not_reported_as_missing()
    {
        $this->theme();

        $wanted = Tokens::forFiles([]);
        $this->assertSame([], $wanted);

        // `--gutter` lives in var.css, not in `@theme`: the site it came from
        // did not have it either, so a theme cannot supply it.
        $entry = Tokens::describe('gutter', Tokens::theme());
        $this->assertFalse($entry['known']);

        $compared = Tokens::compare([$entry]);
        $this->assertSame(Tokens::UNKNOWN, $compared[0]['status']);
    }

    #[Test]
    public function the_review_says_present_missing_differs_or_step_missing()
    {
        $this->theme();
        $source = Tokens::theme();

        $wanted = [
            Tokens::describe('secondary-600', $source),
            Tokens::describe('gray-600', $source),
            Tokens::describe('size-700', $source),
        ];

        // The receiving site: same secondary base in another colour, no step
        // 600 of it, no gray at all, and a size of its own.
        $here = [
            'color-secondary' => '#254446',
            'color-gray-600' => '#525252',
            'size-700' => 'clamp(2rem, 1rem + 2vw, 3rem)',
            'container-width' => '75rem',
        ];

        $status = collect(Tokens::compare($wanted, $here))->keyBy('name');

        $this->assertSame(Tokens::STEP_MISSING, $status['color-secondary-600']['status']);
        $this->assertSame('#254446', $status['color-secondary-600']['here_base'], 'Generate the step from this site’s own base.');

        $this->assertSame(Tokens::PRESENT, $status['color-gray-600']['status']);
        $this->assertSame(Tokens::DIFFERS, $status['size-700']['status']);
        $this->assertSame('clamp(2rem, 1rem + 2vw, 3rem)', $status['size-700']['here']);
    }

    #[Test]
    public function a_colour_family_this_site_never_had_is_missing()
    {
        $this->theme();

        $wanted = [Tokens::describe('secondary-600', Tokens::theme())];
        $status = Tokens::compare($wanted, ['color-primary' => '#11122C']);

        $this->assertSame(Tokens::MISSING, $status[0]['status']);
        $this->assertNull($status[0]['here_base']);
        $this->assertSame('#061b6f', $status[0]['base'], 'The base travels with the package.');
    }

    #[Test]
    public function the_same_value_written_differently_is_the_same_value()
    {
        $this->theme();

        $wanted = [Tokens::describe('size-700', Tokens::theme())];
        $status = Tokens::compare($wanted, ['size-700' => 'CLAMP(2.375rem,  2rem + 1.875vw,3.5rem)']);

        $this->assertSame(Tokens::PRESENT, $status[0]['status']);
    }

    #[Test]
    public function a_package_asks_the_receiving_site_for_the_colour_it_paints_with()
    {
        $this->heroSite();
        $this->theme();

        $this->file('resources/visual-editor/tw/hero/style_2.css', ".bg-secondary-600{background-color:var(--color-secondary-600)}\n");

        $package = Package::build(['hero/style_2']);
        $this->created[] = $package['path'];

        $zip = new ZipArchive;
        $zip->open($package['path']);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame('85.375rem', $manifest['theme']['container_width']);
        $this->assertContains('color-secondary-600', array_column($manifest['sections'][0]['tokens'], 'name'));

        // Play the receiving site: a theme with a primary and nothing else.
        $this->file('resources/css/site.css', "@theme {\n  --color-primary: #11122C;\n  --container-width: 75rem;\n}");
        Tokens::flush();

        $review = Inspector::inspect($package['path']);
        $tokens = collect($review['tokens'])->keyBy('name');

        $this->assertSame(Tokens::MISSING, $tokens['color-secondary-600']['status']);
        $this->assertSame('#061b6f', $tokens['color-secondary-600']['base'], 'The base came along, so the family can be made here.');
        $this->assertSame(['hero/style_2'], $tokens['color-secondary-600']['wanted_by']);
        $this->assertSame('75rem', $review['theme']['container_width_here']);
    }

    #[Test]
    public function the_section_manifest_carries_its_tokens()
    {
        $this->heroSite();
        $this->theme();

        $this->file('resources/visual-editor/tw/hero/style_2.css', ".bg-secondary-600{background-color:var(--color-secondary-600)}\n");

        $manifest = Manifest::forSection('hero/style_2');

        $this->assertContains('color-secondary-600', array_column($manifest['tokens'], 'name'));
    }
}
