<?php

namespace Vizuall\ComponentExporter\Tests;

use Vizuall\ComponentExporter\Section\Manifest;

class ManifestTest extends TestCase
{
    public function test_an_unregistered_handle_has_no_manifest(): void
    {
        $this->heroSite();

        $this->assertNull(Manifest::forSection('hero/style_9'));
    }

    public function test_a_section_lists_its_fieldset_chain_partials_css_and_preview(): void
    {
        $this->heroSite();

        $manifest = Manifest::forSection('hero/style_2');

        $this->assertSame('hero', $manifest['group']);
        $this->assertSame('Hero', $manifest['group_display']);
        $this->assertSame('Hero style 2', $manifest['display']);
        $this->assertFalse($manifest['static']);

        $byPath = collect($manifest['files'])->keyBy('path');

        // Same pairs, whatever order the walk met them in.
        $this->assertEquals([
            'resources/fieldsets/hero/style_2.yaml' => false,
            'resources/fieldsets/blocks/headline.yaml' => true,
            'resources/fieldsets/common.yaml' => true,
            'resources/views/partials/page_sections/hero/style_2.antlers.html' => false,
            'resources/views/partials/blocks/headline.antlers.html' => true,
            'resources/visual-editor/tw/hero/style_2.css' => false,
            'resources/visual-editor/tw/view/partials/blocks/headline.css' => true,
            'public/assets/set-previews/hero-style-2-abc123.png' => false,
            'public/assets/set-previews/.meta/hero-style-2-abc123.png.yaml' => false,
        ], $byPath->map(fn ($file) => $file['shared'])->all());

        $this->assertSame('fieldset', $byPath['resources/fieldsets/common.yaml']['role']);
        $this->assertSame('common', $byPath['resources/fieldsets/common.yaml']['label']);
        $this->assertSame('partial', $byPath['resources/views/partials/blocks/headline.antlers.html']['role']);
        $this->assertSame('partials/blocks/headline', $byPath['resources/views/partials/blocks/headline.antlers.html']['label']);
        $this->assertSame('css', $byPath['resources/visual-editor/tw/hero/style_2.css']['role']);
        $this->assertSame('hero/style_2', $byPath['resources/visual-editor/tw/hero/style_2.css']['label']);
        $this->assertSame('preview', $byPath['public/assets/set-previews/hero-style-2-abc123.png']['role']);

        // `common` imports a fieldset that does not exist: said, not hidden.
        $this->assertSame(['fieldset: image'], $manifest['missing']);
    }

    public function test_the_global_section_set_is_not_a_section_type(): void
    {
        $this->heroSite();
        $this->registry([
            'hero' => ['Hero', [
                'hero/style_2' => ['display' => 'Hero style 2', 'fields' => [['import' => 'hero.style_2']]],
                'global_section' => ['display' => 'Global section', 'hide' => true, 'fields' => [
                    ['handle' => 'global_section', 'field' => ['type' => 'entries', 'collections' => ['saved_sections']]],
                ]],
            ]],
        ]);
        $this->file('resources/views/partials/page_sections/global_section.antlers.html', '{{ partial src="partials/page_sections/{type}" }}');

        $this->assertNull(Manifest::forSection('global_section'));
        $this->assertSame(['hero/style_2'], array_column(Manifest::forSections(['global_section', 'hero/style_2']), 'handle'));
    }

    public function test_a_static_section_is_its_template_alone(): void
    {
        $this->heroSite();

        $manifest = Manifest::forSection('static_section/banner');

        $this->assertTrue($manifest['static']);
        $this->assertSame(
            ['resources/views/partials/page_sections/static_section/banner.antlers.html'],
            array_column($manifest['files'], 'path')
        );
        $this->assertSame([], $manifest['missing']);
    }

    public function test_the_union_says_which_sections_use_a_shared_file(): void
    {
        $this->heroSite();

        $union = collect(Manifest::union(Manifest::forSections(['hero/style_2', 'static_section/banner', 'nope'])))->keyBy('path');

        $this->assertSame(['hero/style_2'], $union['resources/fieldsets/blocks/headline.yaml']['used_by']);
        $this->assertSame(['static_section/banner'], $union['resources/views/partials/page_sections/static_section/banner.antlers.html']['used_by']);
    }

    public function test_tailwind_handles_are_read_from_the_template_text(): void
    {
        $this->assertSame(['hero/style_2'], Manifest::tailwindHandles('{{ sve_tw }}', 'hero/style_2'));
        $this->assertSame(['view/default'], Manifest::tailwindHandles('{{ sve_tw handle="view/default" }}', 'hero/style_2'));
        $this->assertSame(
            ['hero/style_2', "view/a"],
            Manifest::tailwindHandles("{{ sve_tw }} … {{ sve_tw handle='view/a' }}", 'hero/style_2')
        );
        $this->assertSame([], Manifest::tailwindHandles('{{ style_push }}', 'hero/style_2'));
    }
}
