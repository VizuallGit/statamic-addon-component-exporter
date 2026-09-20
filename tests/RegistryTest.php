<?php

namespace Vizuall\ComponentExporter\Tests;

use Statamic\Facades\YAML;
use Vizuall\ComponentExporter\Section\Registry;

class RegistryTest extends TestCase
{
    public function test_entries_carry_their_group(): void
    {
        $this->heroSite();

        $entries = Registry::entries();

        $this->assertSame(['hero/style_2', 'static_section/banner'], array_keys($entries));
        $this->assertSame('hero', $entries['hero/style_2']['group']);
        $this->assertSame('Hero', $entries['hero/style_2']['group_display']);
        $this->assertSame('Hero style 2', $entries['hero/style_2']['set']['display']);
        $this->assertNull(Registry::entry('nope'));
    }

    public function test_merge_replaces_a_set_in_its_group_and_keeps_the_rest(): void
    {
        $this->heroSite();

        Registry::merge('hero', 'Hero', 'hero/style_2', ['display' => 'Hero style 2 (ny)', 'fields' => [['import' => 'hero.style_2']]]);

        $groups = Registry::groups();

        $this->assertSame('Hero style 2 (ny)', $groups['hero']['sets']['hero/style_2']['display']);
        $this->assertArrayHasKey('static_section/banner', $groups['static_sections']['sets']);
        $this->assertSame('Statiske sektioner', $groups['static_sections']['display']);
    }

    public function test_merge_creates_a_missing_group_with_its_display_name(): void
    {
        $this->heroSite();

        Registry::merge('galleries', 'Galleries', 'gallery/style_1', ['display' => 'Galleri', 'fields' => []]);

        $groups = Registry::groups();

        $this->assertSame('Galleries', $groups['galleries']['display']);
        $this->assertSame('Galleri', $groups['galleries']['sets']['gallery/style_1']['display']);
        $this->assertSame(['hero', 'static_sections', 'galleries'], array_keys($groups));

        // Written as Statamic writes it: the file parses back to the same thing.
        $onDisk = YAML::parse((string) file_get_contents(base_path('resources/fieldsets/page_sections.yaml')));
        $this->assertSame('Galleries', $onDisk['fields'][0]['field']['sets']['galleries']['display']);
    }

    public function test_merge_without_a_registry_starts_one(): void
    {
        $this->ensureDirectory(resource_path('fieldsets'));
        $this->created[] = resource_path('fieldsets/page_sections.yaml');

        Registry::merge('hero', null, 'hero/style_2', ['display' => 'Hero', 'fields' => []]);

        $groups = Registry::groups();

        $this->assertSame('Hero', $groups['hero']['display']);
        $this->assertSame('Hero', $groups['hero']['sets']['hero/style_2']['display']);
        $this->assertSame('resources/fieldsets/page_sections.yaml', Registry::relativePath());
    }
}
