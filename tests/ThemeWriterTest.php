<?php

namespace Vizuall\ComponentExporter\Tests;

use PHPUnit\Framework\Attributes\Test;
use Vizuall\ComponentExporter\Export\Package;
use Vizuall\ComponentExporter\Import\Importer;
use Vizuall\ComponentExporter\Import\Inspector;
use Vizuall\ComponentExporter\Theme\Sizes;
use Vizuall\ComponentExporter\Theme\Tokens;
use Vizuall\ComponentExporter\Theme\Writer;

/**
 * Making the theme tokens a package asked for.
 *
 * site.css is the one file every page reads its colours and sizes from, so
 * these tests care as much about what is *not* written as about what is.
 */
class ThemeWriterTest extends TestCase
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

    private function siteCss(string $theme): string
    {
        $path = $this->file('resources/css/site.css', "@theme {\n".$theme."\n}\n\n@utility wrapper {\n    padding: 1rem;\n}\n");
        Tokens::flush();

        return $path;
    }

    #[Test]
    public function it_adds_a_missing_colour_with_its_family()
    {
        $path = $this->siteCss("    --color-primary: #11122C;\n    --container-width: 80rem;");

        $wanted = [[
            'name' => 'color-secondary-600', 'kind' => Tokens::KIND_COLOR, 'known' => true,
            'value' => '#24418d', 'family' => 'secondary', 'step' => 600, 'base' => '#061b6f',
            'status' => Tokens::MISSING,
        ]];

        $result = Writer::add($wanted, ['color-secondary-600']);

        $this->assertSame(['color-secondary', 'color-secondary-600'], $result['written']);
        $this->assertNull($result['reason']);

        $theme = Tokens::parse(file_get_contents($path));
        $this->assertSame('#061b6f', $theme['color-secondary'], 'The family, so the panel shows one.');
        $this->assertSame('#24418d', $theme['color-secondary-600']);
        $this->assertSame('#11122C', $theme['color-primary'], 'Untouched.');

        $this->assertStringContainsString('@utility wrapper', file_get_contents($path), 'The rest of the file stands.');
    }

    #[Test]
    public function it_adds_only_the_step_when_the_family_is_already_here()
    {
        $path = $this->siteCss("    --color-secondary: #254446;\n    --container-width: 80rem;");

        $wanted = [[
            'name' => 'color-secondary-600', 'kind' => Tokens::KIND_COLOR, 'known' => true,
            'value' => '#24418d', 'family' => 'secondary', 'step' => 600, 'base' => '#061b6f',
            'status' => Tokens::STEP_MISSING,
        ]];

        Writer::add($wanted, ['color-secondary-600']);

        $theme = Tokens::parse(file_get_contents($path));
        $this->assertSame('#254446', $theme['color-secondary'], 'This site’s own base is kept.');
        $this->assertSame('#24418d', $theme['color-secondary-600']);
    }

    #[Test]
    public function a_fluid_size_is_worked_out_against_this_container_not_copied()
    {
        $path = $this->siteCss('    --container-width: 75rem;');

        // 38 px → 56 px, made on a site whose container was 85.375rem (1366 px).
        $wanted = [[
            'name' => 'size-700', 'kind' => Tokens::KIND_SIZE, 'known' => true,
            'value' => 'clamp(2.375rem, 2rem + 1.875vw, 3.5rem)',
            'min' => '2.375rem', 'max' => '3.5rem', 'status' => Tokens::MISSING,
        ]];

        Writer::add($wanted, ['size-700']);

        $written = Tokens::parse(file_get_contents($path))['size-700'];

        $this->assertNotSame('clamp(2.375rem, 2rem + 1.875vw, 3.5rem)', $written, 'Not the sender’s slope.');
        $this->assertSame(Sizes::value(38, 56, 1200), $written);

        // Both ends still land where they were drawn: 38 px on a phone, 56 px
        // at this site's own full width.
        $ends = Sizes::ends($written);
        $this->assertEqualsWithDelta(38, $ends['min'], 0.01);
        $this->assertEqualsWithDelta(56, $ends['max'], 0.01);
    }

    #[Test]
    public function it_never_touches_a_token_this_site_already_has()
    {
        $path = $this->siteCss("    --color-secondary-600: #425e60;\n    --container-width: 80rem;");
        $before = file_get_contents($path);

        $wanted = [[
            'name' => 'color-secondary-600', 'kind' => Tokens::KIND_COLOR, 'known' => true,
            'value' => '#24418d', 'family' => 'secondary', 'step' => 600, 'base' => '#061b6f',
            'status' => Tokens::PRESENT,
        ]];

        $result = Writer::add($wanted, ['color-secondary-600']);

        $this->assertSame([], $result['written']);
        $this->assertSame('nothing-to-add', $result['reason']);
        $this->assertSame($before, file_get_contents($path), 'The file is byte for byte what it was.');
    }

    #[Test]
    public function a_token_the_editor_did_not_tick_is_not_made()
    {
        $path = $this->siteCss('    --container-width: 80rem;');

        $wanted = [
            ['name' => 'color-a-600', 'kind' => Tokens::KIND_COLOR, 'known' => true, 'value' => '#111111', 'family' => 'a', 'step' => 600, 'base' => null, 'status' => Tokens::MISSING],
            ['name' => 'color-b-600', 'kind' => Tokens::KIND_COLOR, 'known' => true, 'value' => '#222222', 'family' => 'b', 'step' => 600, 'base' => null, 'status' => Tokens::MISSING],
        ];

        Writer::add($wanted, ['color-b-600']);

        $theme = Tokens::parse(file_get_contents($path));
        $this->assertArrayNotHasKey('color-a-600', $theme);
        $this->assertSame('#222222', $theme['color-b-600']);
    }

    #[Test]
    public function the_whole_write_is_dropped_when_the_result_looks_wrong()
    {
        $path = $this->siteCss('    --container-width: 80rem;');
        $before = file_get_contents($path);

        // A value carrying a `;` would end the declaration early and leave a
        // stray token behind — the count check catches it and nothing is saved.
        $wanted = [[
            'name' => 'color-x', 'kind' => Tokens::KIND_COLOR, 'known' => true,
            'value' => '#111111; --color-sneaked: #000000', 'family' => 'x', 'step' => null, 'base' => null,
            'status' => Tokens::MISSING,
        ]];

        $result = Writer::add($wanted, ['color-x']);

        $this->assertSame([], $result['written']);
        $this->assertSame('unexpected-result', $result['reason']);
        $this->assertSame($before, file_get_contents($path));
    }

    #[Test]
    public function importing_makes_the_colour_the_section_paints_with()
    {
        $this->heroSite();
        $this->siteCss("    --color-primary: #11122C;\n    --color-secondary: #061b6f;\n    --color-secondary-600: #24418d;\n    --container-width: 85.375rem;");

        $this->file('resources/visual-editor/tw/hero/style_2.css', ".bg-secondary-600{background-color:var(--color-secondary-600)}\n");

        $package = Package::build(['hero/style_2']);
        $this->created[] = $package['path'];

        // The receiving site: primary only, and a narrower container.
        $path = $this->siteCss("    --color-primary: #11122C;\n    --container-width: 75rem;");

        $review = Inspector::inspect($package['path']);
        $token = collect($review['tokens'])->firstWhere('name', 'color-secondary-600');
        $this->assertSame(Tokens::MISSING, $token['status']);

        $result = Importer::apply($package['path'], [
            'files' => [],
            'sections' => [],
            'tokens' => ['color-secondary-600'],
        ]);

        $this->assertSame(['color-secondary', 'color-secondary-600'], $result['tokens']['written']);

        $theme = Tokens::parse(file_get_contents($path));
        $this->assertSame('#24418d', $theme['color-secondary-600']);
        $this->assertSame('#061b6f', $theme['color-secondary']);
        $this->assertSame('75rem', $theme['container-width'], 'This site’s own container stands.');
    }

    #[Test]
    public function importing_refuses_a_token_that_is_not_missing_even_if_asked()
    {
        $this->heroSite();
        $this->siteCss("    --color-secondary: #061b6f;\n    --color-secondary-600: #24418d;\n    --container-width: 85.375rem;");
        $this->file('resources/visual-editor/tw/hero/style_2.css', ".bg-secondary-600{background-color:var(--color-secondary-600)}\n");

        $package = Package::build(['hero/style_2']);
        $this->created[] = $package['path'];

        // Here the same name is a different colour. The package must not win.
        $path = $this->siteCss("    --color-secondary: #254446;\n    --color-secondary-600: #425e60;\n    --container-width: 85.375rem;");

        $result = Importer::apply($package['path'], [
            'files' => [],
            'sections' => [],
            'tokens' => ['color-secondary-600'],
        ]);

        $this->assertSame([], $result['tokens']['written']);
        $this->assertSame('#425e60', Tokens::parse(file_get_contents($path))['color-secondary-600']);
    }
}
