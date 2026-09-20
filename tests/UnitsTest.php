<?php

namespace Vizuall\ComponentExporter\Tests;

use Vizuall\ComponentExporter\Export\Package;
use Vizuall\ComponentExporter\Import\Importer;
use Vizuall\ComponentExporter\Import\Inspector;
use Vizuall\ComponentExporter\Units\Catalog;
use ZipArchive;

class UnitsTest extends TestCase
{
    public function test_the_catalog_lists_units_by_kind(): void
    {
        $this->unitsSite();

        $this->assertSame(['collection:services'], Catalog::ids('collection'));
        $this->assertSame(['form:contact_form'], Catalog::ids('form'));
        $this->assertSame(['global:site_head'], Catalog::ids('global'));
        $this->assertSame(['blueprint:resources/blueprints/user.yaml'], Catalog::ids('blueprint'));

        $all = Catalog::all();
        $this->assertSame('Services', $all['collections'][0]['display']);
        $this->assertSame('Kontakt', $all['forms'][0]['display']);
        $this->assertSame('Header', $all['globals'][0]['display']);
        $this->assertSame('User', $all['blueprints'][0]['display']);
    }

    public function test_a_collection_travels_with_its_blueprint_views_css_presets_and_what_they_pull_in(): void
    {
        $this->unitsSite();

        $unit = Catalog::resolve('collection:services');

        $this->assertSame('services', $unit['handle']);
        $files = collect($unit['files'])->keyBy('path');

        $this->assertEquals([
            'content/collections/services.yaml' => ['config', false],
            'resources/blueprints/collections/services/services.yaml' => ['blueprint', false],
            'resources/fieldsets/common.yaml' => ['fieldset', true],
            'resources/views/services/index.antlers.html' => ['view', false],
            'resources/views/services/show.antlers.html' => ['view', false],
            'resources/views/partials/components/card.antlers.html' => ['partial', true],
            'resources/visual-editor/tw/view/services/show.css' => ['css', false],
            'resources/visual-editor/tw/view/partials/components/card.css' => ['css', true],
            'resources/visual-editor/collection-presets/services/preset.yaml' => ['preset', false],
            'resources/visual-editor/collection-presets/services/show.antlers.html' => ['preset', false],
        ], $files->map(fn ($f) => [$f['role'], $f['shared']])->all());

        $this->assertSame('services/show', $files['resources/views/services/show.antlers.html']['label']);
        $this->assertSame(['fieldset: image'], $unit['missing']);
    }

    public function test_forms_globals_and_loose_blueprints_are_units_too(): void
    {
        $this->unitsSite();

        $form = Catalog::resolve('form:contact_form');
        $this->assertSame(['resources/forms/contact_form.yaml', 'resources/blueprints/forms/contact_form.yaml'], array_column($form['files'], 'path'));
        $this->assertSame(['config', 'blueprint'], array_column($form['files'], 'role'));

        $global = Catalog::resolve('global:site_head');
        $this->assertSame(['content/globals/site_head.yaml', 'resources/blueprints/globals/site_head.yaml'], array_column($global['files'], 'path'));

        $blueprint = Catalog::resolve('blueprint:resources/blueprints/user.yaml');
        $this->assertSame('user', $blueprint['files'][0]['label']);

        $this->assertNull(Catalog::resolve('collection:nope'));
        $this->assertNull(Catalog::resolve('collection:../etc'));
        $this->assertNull(Catalog::resolve('blueprint:resources/blueprints/../../.env'));
        $this->assertNull(Catalog::resolve('page:home'));
        $this->assertNull(Catalog::resolve('nonsense'));
    }

    public function test_a_unit_package_is_built_reviewed_and_imported(): void
    {
        $this->unitsSite();

        $package = Package::build([], ['collection:services', 'form:contact_form', 'form:nope']);
        $this->created[] = $package['path'];

        $this->assertSame(0, $package['sections']);
        $this->assertSame(2, $package['units']);
        $this->assertSame('pakke-'.date('Y-m-d').'.zip', $package['filename']);

        $zip = new ZipArchive;
        $zip->open($package['path']);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame(2, $manifest['format']);
        $this->assertSame([], $manifest['sections']);
        $this->assertSame(['collection:services', 'form:contact_form'], array_column($manifest['units'], 'id'));

        // The receiving site lacks the collection's own files; the shared card is different there.
        unlink(base_path('content/collections/services.yaml'));
        unlink(base_path('resources/views/services/show.antlers.html'));
        file_put_contents(base_path('resources/views/partials/components/card.antlers.html'), "<div>local</div>\n");

        $review = Inspector::inspect($package['path']);
        $this->assertSame([], $review['sections']);
        $services = collect($review['units'][0]['files'])->keyBy('path');
        $this->assertSame('new', $services['content/collections/services.yaml']['status']);
        $this->assertSame('changed', $services['resources/views/partials/components/card.antlers.html']['status']);
        $this->assertFalse($services['resources/views/partials/components/card.antlers.html']['suggested']);
        $this->assertSame('same', $services['resources/blueprints/collections/services/services.yaml']['status']);

        $choices = ['files' => collect($review['units'])->flatMap(fn ($u) => collect($u['files'])->mapWithKeys(fn ($f) => [$f['path'] => $f['suggested']]))->all()];
        $result = Importer::apply($package['path'], $choices);

        $this->assertEqualsCanonicalizing(['content/collections/services.yaml', 'resources/views/services/show.antlers.html'], $result['written']);
        $this->assertSame([], $result['rejected']);
        $this->assertStringContainsString('local', file_get_contents(base_path('resources/views/partials/components/card.antlers.html')));
        $this->assertFileExists(base_path('content/collections/services.yaml'));
    }

    public function test_only_places_a_unit_can_live_are_writable(): void
    {
        $this->assertTrue(Importer::isWritable('resources/forms/contact_form.yaml'));
        $this->assertTrue(Importer::isWritable('resources/blueprints/globals/site_head.yaml'));
        $this->assertTrue(Importer::isWritable('content/collections/services.yaml'));
        $this->assertTrue(Importer::isWritable('content/globals/site_head.yaml'));
        $this->assertTrue(Importer::isWritable('content/collections/templates/services-show.1.md'));
        $this->assertTrue(Importer::isWritable('resources/visual-editor/collection-presets/services/preset.yaml'));

        $this->assertFalse(Importer::isWritable('content/collections/services/an-entry.md'));
        $this->assertFalse(Importer::isWritable('content/globals/default/site_settings.yaml'));
        $this->assertFalse(Importer::isWritable('content/collections/templates/../pages/home.md'));
        $this->assertFalse(Importer::isWritable('.env'));
        $this->assertFalse(Importer::isWritable('config/app.php'));
        $this->assertFalse(Importer::isWritable('resources/views/../../.env'));
    }

    public function test_a_format_one_package_reads_its_extras_as_one_unit(): void
    {
        $this->unitsSite();

        $path = tempnam(sys_get_temp_dir(), 'v1').'.zip';
        $this->created[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('content/collections/services.yaml', "title: Services\n");
        $zip->addFromString('manifest.json', json_encode([
            'format' => 1,
            'sections' => [],
            'extras' => [['path' => 'content/collections/services.yaml', 'role' => 'collection', 'sha1' => sha1("title: Services\n")]],
        ]));
        $zip->close();

        $review = Inspector::inspect($path);

        $this->assertFalse($review['legacy']);
        $this->assertSame('Øvrige filer', $review['units'][0]['display']);
        $this->assertSame('changed', $review['units'][0]['files'][0]['status']);
    }
}
