<?php

namespace Vizuall\ComponentExporter\Tests;

use Symfony\Component\Yaml\Yaml;

class KitCommandTest extends TestCase
{
    private const KIT = <<<'YAML'
# The kit.
updatable: false

export_paths:
  - resources/css

modules:
  sections:
    prompt: 'Install the sections?'
    default: true
    export_paths:
      # the registry
      - resources/fieldsets/page_sections.yaml
      - resources/views/partials/page_sections
      - resources/fieldsets/gallery

      - content/collections/saved_sections

dependencies:
  - statamic-addon/visual-editor

YAML;

    public function test_uncovered_files_and_stale_paths_fail_the_check(): void
    {
        $this->heroSite();
        $this->file('package/starter-kit.yaml', self::KIT);
        $this->file('content/collections/saved_sections/one.md', "---\ntitle: One\n---\n");

        $this->artisan('component-exporter:kit')
            ->expectsOutputToContain('not covered: resources/fieldsets/hero/style_2.yaml')
            ->expectsOutputToContain('not covered: resources/visual-editor/tw/hero/style_2.css')
            ->expectsOutputToContain('not covered: public/assets/set-previews/hero-style-2-abc123.png')
            ->expectsOutputToContain('listed but missing on disk: resources/fieldsets/gallery')
            ->assertExitCode(1);
    }

    public function test_write_rewrites_only_the_module_list_and_the_check_then_passes(): void
    {
        $this->heroSite();
        $kit = $this->file('package/starter-kit.yaml', self::KIT);
        $this->file('content/collections/saved_sections/one.md', "---\ntitle: One\n---\n");

        $this->artisan('component-exporter:kit', ['--write' => true])->assertExitCode(0);

        $text = file_get_contents($kit);
        $config = Yaml::parse($text);

        $this->assertSame([
            'resources/fieldsets/page_sections.yaml',
            'resources/views/partials/page_sections',
            'content/collections/saved_sections',
            'public/assets/set-previews',
            'resources/fieldsets/hero',
            'resources/visual-editor/tw/hero',
        ], $config['modules']['sections']['export_paths']);

        // Everything outside the list is as it was.
        $this->assertStringStartsWith("# The kit.\nupdatable: false\n", $text);
        $this->assertSame(['resources/css'], $config['export_paths']);
        $this->assertSame('Install the sections?', $config['modules']['sections']['prompt']);
        $this->assertSame(['statamic-addon/visual-editor'], $config['dependencies']);
        $this->assertStringContainsString("\n\ndependencies:", $text);

        $this->artisan('component-exporter:kit')->assertExitCode(0);
    }

    public function test_a_kit_without_the_module_is_an_error(): void
    {
        $this->heroSite();
        $this->file('package/starter-kit.yaml', "updatable: false\nexport_paths:\n  - resources/css\n");

        $this->artisan('component-exporter:kit')
            ->expectsOutputToContain('Module [sections] is not in')
            ->assertExitCode(1);
    }
}
