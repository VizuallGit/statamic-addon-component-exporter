<?php

namespace Vizuall\ComponentExporter\Tests;

use MarioHamann\StatamicVisualEditor\PreviewPartials;
use Vizuall\ComponentExporter\Export\Package;
use Vizuall\ComponentExporter\Import\Importer;
use Vizuall\ComponentExporter\Import\Inspector;
use Vizuall\ComponentExporter\Import\Rename;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Registry;

class RenameTest extends TestCase
{
    public function test_a_new_display_name_is_registered_and_nothing_moves(): void
    {
        $this->heroSite();
        $package = $this->package(['hero/style_2']);

        $renames = ['hero/style_2' => ['display' => 'Intro']];
        $review = Inspector::inspect($package, $renames)['sections'][0];

        $this->assertSame('hero/style_2', $review['target_handle']);
        $this->assertSame('Intro', $review['target_display']);
        $this->assertTrue($review['registered']);
        $this->assertFalse($review['registry_same'], 'a new name is a change to the entry');
        $this->assertSame(array_column($review['files'], 'path'), array_column($review['files'], 'target'));

        $result = Importer::apply($package, ['files' => [], 'sections' => ['hero/style_2'], 'renames' => $renames]);

        $this->assertSame(['hero/style_2'], $result['registered']);
        $this->assertSame('Intro', Registry::entry('hero/style_2')['set']['display']);
    }

    public function test_a_new_handle_moves_the_sections_own_files_and_leaves_the_one_already_here(): void
    {
        // The receiving site already has hero/style_2 — the reason to rename.
        $this->heroSite();
        $package = $this->package(['hero/style_2']);
        file_put_contents(base_path('resources/views/partials/page_sections/hero/style_2.antlers.html'), "<section>Mine</section>\n");

        $renames = ['hero/style_2' => ['handle' => 'hero/intro', 'display' => 'Intro']];
        $review = Inspector::inspect($package, $renames)['sections'][0];

        $this->assertNull($review['rename_error']);
        $this->assertSame('hero/intro', $review['target_handle']);
        $this->assertFalse($review['registered']);

        $targets = collect($review['files'])->pluck('target', 'path');
        $this->assertSame('resources/views/partials/page_sections/hero/intro.antlers.html', $targets['resources/views/partials/page_sections/hero/style_2.antlers.html']);
        $this->assertSame('resources/fieldsets/hero/intro.yaml', $targets['resources/fieldsets/hero/style_2.yaml']);
        $this->assertSame('resources/visual-editor/tw/hero/intro.css', $targets['resources/visual-editor/tw/hero/style_2.css']);
        $this->assertSame('public/assets/set-previews/hero-intro-abc123.png', $targets['public/assets/set-previews/hero-style-2-abc123.png']);
        $this->assertSame('public/assets/set-previews/.meta/hero-intro-abc123.png.yaml', $targets['public/assets/set-previews/.meta/hero-style-2-abc123.png.yaml']);
        $this->assertSame('resources/fieldsets/blocks/headline.yaml', $targets['resources/fieldsets/blocks/headline.yaml'], 'shared files never move');
        $this->assertSame('resources/visual-editor/tw/view/partials/blocks/headline.css', $targets['resources/visual-editor/tw/view/partials/blocks/headline.css']);

        $statuses = collect($review['files'])->pluck('status', 'target');
        $this->assertSame('new', $statuses['resources/views/partials/page_sections/hero/intro.antlers.html'], 'judged where it lands, not where it came from');
        $this->assertSame('same', $statuses['resources/fieldsets/blocks/headline.yaml']);

        $files = collect($review['files'])->mapWithKeys(fn ($file) => [$file['path'] => $file['suggested']])->all();
        $this->track($targets->filter(fn ($target, $path) => $target !== $path)->values()->all());

        $result = Importer::apply($package, ['files' => $files, 'sections' => ['hero/style_2'], 'renames' => $renames]);

        $this->assertSame(['hero/intro'], $result['registered']);
        $this->assertEqualsCanonicalizing([
            'resources/views/partials/page_sections/hero/intro.antlers.html',
            'resources/fieldsets/hero/intro.yaml',
            'resources/visual-editor/tw/hero/intro.css',
            'public/assets/set-previews/hero-intro-abc123.png',
            'public/assets/set-previews/.meta/hero-intro-abc123.png.yaml',
        ], $result['written']);

        $this->assertStringContainsString('Mine', file_get_contents(base_path('resources/views/partials/page_sections/hero/style_2.antlers.html')), 'the section already here is not touched');
        $this->assertSame('Hero style 2', Registry::entry('hero/style_2')['set']['display']);

        $entry = Registry::entry('hero/intro');
        $this->assertSame('hero', $entry['group']);
        $this->assertSame('Intro', $entry['set']['display']);
        $this->assertSame([['import' => 'hero.intro']], $entry['set']['fields']);
        $this->assertSame('hero-intro-abc123.png', $entry['set']['image']);

        // On this site the renamed section is a whole section in its own right.
        PreviewPartials::flush();
        $manifest = Manifest::forSection('hero/intro');
        $own = collect($manifest['files'])->reject(fn ($file) => $file['shared'])->pluck('path')->all();
        $this->assertEqualsCanonicalizing([
            'resources/fieldsets/hero/intro.yaml',
            'resources/views/partials/page_sections/hero/intro.antlers.html',
            'resources/visual-editor/tw/hero/intro.css',
            'public/assets/set-previews/hero-intro-abc123.png',
            'public/assets/set-previews/.meta/hero-intro-abc123.png.yaml',
        ], $own);
        $this->assertSame([], array_values(array_diff($manifest['missing'], ['fieldset: image'])));
    }

    public function test_a_handle_that_cannot_be_used_stops_the_import_before_anything_is_written(): void
    {
        $this->heroSite();
        $package = $this->package(['hero/style_2', 'static_section/banner']);

        $bad = Inspector::inspect($package, ['hero/style_2' => ['handle' => 'Hero/Intro!']])['sections'][0];
        $this->assertSame('hero/style_2', $bad['target_handle']);
        $this->assertStringContainsString('små bogstaver', $bad['rename_error']);

        $taken = Inspector::inspect($package, ['hero/style_2' => ['handle' => 'static_section/banner']])['sections'];
        $this->assertStringContainsString('hedder allerede static_section/banner', $taken[0]['rename_error']);
        $this->assertNull($taken[1]['rename_error'], 'the section that has the name keeps it');

        $before = file_get_contents(base_path('resources/fieldsets/page_sections.yaml'));

        try {
            Importer::apply($package, [
                'files' => ['resources/fieldsets/hero/style_2.yaml' => true],
                'sections' => ['hero/style_2'],
                'renames' => ['hero/style_2' => ['handle' => 'global_section']],
            ]);
            $this->fail('the import went ahead');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Visual Editorens eget sæt', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents(base_path('resources/fieldsets/page_sections.yaml')));
    }

    public function test_a_fieldset_in_a_folder_of_its_own_keeps_the_folder_and_takes_the_new_name(): void
    {
        $plan = $this->plan($this->section('featured_section/style_1', 'featured_sections.style_1'), 'featured_section/intro');

        $this->assertSame([['import' => 'featured_sections.intro']], $plan['set']['fields']);
        $this->assertSame('resources/fieldsets/featured_sections/intro.yaml', $plan['paths']['resources/fieldsets/featured_sections/style_1.yaml']);
    }

    public function test_a_fieldset_another_section_imports_keeps_its_name(): void
    {
        $section = $this->section('hero/style_2', 'hero.style_2');
        $plan = $this->plan($section, 'hero/intro', ['resources/fieldsets/hero/style_2.yaml' => ['hero/style_2', 'hero/style_3']]);

        $this->assertNull($plan['error']);
        $this->assertSame([['import' => 'hero.style_2']], $plan['set']['fields']);
        $this->assertArrayNotHasKey('resources/fieldsets/hero/style_2.yaml', $plan['paths']);
        $this->assertSame('resources/views/partials/page_sections/hero/intro.antlers.html', $plan['paths']['resources/views/partials/page_sections/hero/style_2.antlers.html']);
    }

    public function test_a_fieldset_another_of_its_fieldsets_refers_to_keeps_its_name(): void
    {
        $section = $this->section('hero/style_2', 'hero.style_2');
        $section['files'][] = ['path' => 'resources/fieldsets/hero/extras.yaml', 'role' => 'fieldset', 'shared' => false, 'label' => 'hero.extras'];

        $plan = $this->plan($section, 'hero/intro', [], ['resources/fieldsets/hero/extras.yaml' => "fields:\n  -\n    import: hero.style_2\n"]);

        $this->assertSame([['import' => 'hero.style_2']], $plan['set']['fields']);
    }

    public function test_a_template_another_section_uses_or_a_folder_of_templates_refuses_the_handle(): void
    {
        $section = $this->section('hero/style_2', 'hero.style_2');
        $shared = $this->plan($section, 'hero/intro', ['resources/views/partials/page_sections/hero/style_2.antlers.html' => ['hero/style_2', 'hero/style_3']]);

        $this->assertSame('hero/style_2', $shared['handle']);
        $this->assertStringContainsString('bruges også af hero/style_3', $shared['error']);
        $this->assertSame([], $shared['paths']);

        $section['files'][] = ['path' => 'resources/views/partials/page_sections/hero/style_2/card.antlers.html', 'role' => 'partial', 'shared' => false, 'label' => 'x'];
        $folder = $this->plan($section, 'hero/intro');

        $this->assertStringContainsString('mappe af skabeloner', $folder['error']);
    }

    public function test_a_template_that_writes_its_class_out_is_noted(): void
    {
        $section = $this->section('media_textbox/style_1', 'media_textbox.style_1');
        $template = 'resources/views/partials/page_sections/media_textbox/style_1.antlers.html';

        $alone = $this->plan($section, 'media_textbox/intro', [], [$template => '<section class="[ media-textbox-style-1 ] wrapper"></section>']);
        $this->assertStringContainsString('Den beholdes', $alone['notes'][0]);

        $mixed = $this->plan($section, 'media_textbox/intro', [], [$template => '<section class="{{ _class }}"></section><style>.media-textbox-style-1{}</style>']);
        $this->assertStringContainsString('.media-textbox-intro', $mixed['notes'][0]);

        $clean = $this->plan($section, 'media_textbox/intro', [], [$template => '<section class="{{ _class }}"></section>']);
        $this->assertSame([], $clean['notes']);
    }

    /** @param  list<string>  $handles */
    private function package(array $handles): string
    {
        $package = Package::build($handles);
        $this->created[] = $package['path'];

        return $package['path'];
    }

    /** Files the import writes under new names, removed with the rest in tearDown. */
    private function track(array $relative): void
    {
        foreach ($relative as $path) {
            $this->created[] = base_path($path);
        }
    }

    /** A manifest section with a template and one own fieldset, as Package writes it. */
    private function section(string $handle, string $fieldset): array
    {
        return [
            'handle' => $handle,
            'display' => $handle,
            'set' => ['display' => $handle, 'fields' => [['import' => $fieldset]]],
            'files' => [
                ['path' => 'resources/fieldsets/'.str_replace('.', '/', $fieldset).'.yaml', 'role' => 'fieldset', 'shared' => false, 'label' => $fieldset],
                ['path' => 'resources/views/partials/page_sections/'.$handle.'.antlers.html', 'role' => 'partial', 'shared' => false, 'label' => 'partials/page_sections/'.$handle],
            ],
        ];
    }

    private function plan(array $section, string $to, array $usedBy = [], array $contents = []): array
    {
        $read = fn (string $path) => $contents[$path] ?? '';

        return Rename::plans([$section], [$section['handle'] => ['handle' => $to]], $usedBy, $read)[$section['handle']];
    }
}
