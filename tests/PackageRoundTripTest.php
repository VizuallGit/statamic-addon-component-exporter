<?php

namespace Vizuall\ComponentExporter\Tests;

use Vizuall\ComponentExporter\Export\Package;
use Vizuall\ComponentExporter\Import\Importer;
use Vizuall\ComponentExporter\Import\Inspector;
use Vizuall\ComponentExporter\Section\Registry;
use ZipArchive;

class PackageRoundTripTest extends TestCase
{
    public function test_the_package_holds_every_file_and_a_manifest(): void
    {
        $this->heroSite();

        $package = Package::build(['hero/style_2', 'nope'], ['collection:nope']);
        $this->created[] = $package['path'];

        $this->assertSame(1, $package['sections']);
        $this->assertSame(0, $package['units']);
        $this->assertSame(9, $package['files']);
        $this->assertSame('hero-style-2-'.date('Y-m-d').'.zip', $package['filename']);

        $zip = new ZipArchive;
        $zip->open($package['path']);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);

        $this->assertSame(2, $manifest['format']);
        $this->assertSame('hero/style_2', $manifest['sections'][0]['handle']);
        $this->assertSame('hero', $manifest['sections'][0]['group']);
        $this->assertSame(['fieldset: image'], $manifest['sections'][0]['missing']);
        $this->assertSame([], $manifest['units']);
        $this->assertSame(sha1('png'), collect($manifest['sections'][0]['files'])->firstWhere('role', 'preview')['sha1']);
        $this->assertNotFalse($zip->locateName('resources/fieldsets/blocks/headline.yaml'));
        $this->assertNotFalse($zip->locateName('resources/views/partials/page_sections/hero/style_2.antlers.html'));
        $zip->close();
    }

    public function test_nothing_to_export_is_refused(): void
    {
        $this->heroSite();

        $this->expectException(\InvalidArgumentException::class);

        Package::build(['nope'], []);
    }

    public function test_a_package_is_reviewed_against_this_site_and_imported_by_choice(): void
    {
        $this->heroSite();

        $package = Package::build(['hero/style_2']);
        $this->created[] = $package['path'];

        // Play the receiving site: the section's own files and its registry entry
        // are gone, one shared file differs, the rest is identical.
        unlink(base_path('resources/fieldsets/hero/style_2.yaml'));
        unlink(base_path('resources/views/partials/page_sections/hero/style_2.antlers.html'));
        unlink(base_path('resources/visual-editor/tw/hero/style_2.css'));
        file_put_contents(base_path('resources/fieldsets/blocks/headline.yaml'), "title: Headline (local edit)\n");
        $this->registry(['static_sections' => ['Statiske sektioner', [
            'static_section/banner' => ['display' => 'Banner', 'static' => true, 'fields' => []],
        ]]]);

        $review = Inspector::inspect($package['path']);

        $this->assertFalse($review['legacy']);
        $section = $review['sections'][0];
        $this->assertFalse($section['registered']);
        $files = collect($section['files'])->keyBy('path');

        $this->assertSame('new', $files['resources/fieldsets/hero/style_2.yaml']['status']);
        $this->assertTrue($files['resources/fieldsets/hero/style_2.yaml']['suggested']);
        $this->assertSame('changed', $files['resources/fieldsets/blocks/headline.yaml']['status']);
        $this->assertFalse($files['resources/fieldsets/blocks/headline.yaml']['suggested'], 'a changed shared file stays unless asked');
        $this->assertSame('same', $files['resources/fieldsets/common.yaml']['status']);
        $this->assertFalse($files['resources/fieldsets/common.yaml']['suggested']);
        $this->assertSame(['hero/style_2'], $files['resources/fieldsets/blocks/headline.yaml']['used_by']);

        $choices = [
            'files' => $files->map(fn ($file) => $file['suggested'])->all() + ['../../etc/passwd' => true],
            'sections' => ['hero/style_2'],
        ];

        $result = Importer::apply($package['path'], $choices);

        $this->assertSame(['../../etc/passwd'], $result['rejected']);
        $this->assertSame(['hero/style_2'], $result['registered']);
        $this->assertEqualsCanonicalizing([
            'resources/fieldsets/hero/style_2.yaml',
            'resources/views/partials/page_sections/hero/style_2.antlers.html',
            'resources/visual-editor/tw/hero/style_2.css',
        ], $result['written']);
        $this->assertFileExists(base_path('resources/visual-editor/tw/hero/style_2.css'));
        $this->assertStringContainsString('local edit', file_get_contents(base_path('resources/fieldsets/blocks/headline.yaml')));

        $entry = Registry::entry('hero/style_2');
        $this->assertSame('hero', $entry['group']);
        $this->assertSame('Hero', $entry['group_display']);
        $this->assertSame('Hero style 2', $entry['set']['display']);

        // Second look: the section is registered and nothing of its own is new.
        $again = collect(Inspector::inspect($package['path'])['sections'][0]['files'])->keyBy('path');
        $this->assertTrue(Inspector::inspect($package['path'])['sections'][0]['registered']);
        $this->assertSame('same', $again['resources/fieldsets/hero/style_2.yaml']['status']);
        $this->assertSame('changed', $again['resources/fieldsets/blocks/headline.yaml']['status']);
    }

    public function test_an_archive_without_a_manifest_is_reviewed_file_by_file(): void
    {
        $this->heroSite();

        $path = tempnam(sys_get_temp_dir(), 'legacy').'.zip';
        $this->created[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('resources/fieldsets/blocks/quote.yaml', "title: Quote\n");
        $zip->addFromString('resources/fieldsets/common.yaml', "title: Other\n");
        $zip->close();

        $review = Inspector::inspect($path);

        $this->assertTrue($review['legacy']);
        $this->assertSame([], $review['sections']);
        $this->assertSame(['new', 'changed'], array_column($review['units'][0]['files'], 'status'));
    }

    public function test_a_file_that_is_not_a_zip_is_refused(): void
    {
        $path = $this->file('storage/not-a.zip', 'hello');

        $this->expectException(\InvalidArgumentException::class);

        Inspector::inspect($path);
    }
}
