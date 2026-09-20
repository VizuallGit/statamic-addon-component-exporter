<?php

namespace Vizuall\ComponentExporter\Tests;

use MarioHamann\StatamicVisualEditor\PreviewPartials;
use Mockery;
use Statamic\Assets\AssetContainer as AssetContainerModel;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\Fieldset;
use Statamic\Facades\YAML;
use Statamic\Testing\AddonTestCase;
use Vizuall\ComponentExporter\AddonServiceProvider;

/**
 * Every test runs against a small site written into the test app's own
 * `resources/` and `public/` — the same folders the code reads on a real site,
 * so nothing is mocked but the asset container. What setUp writes, tearDown
 * removes; nothing else in those folders is touched.
 */
abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = AddonServiceProvider::class;

    /** @var list<string> files this test created, newest last */
    protected array $created = [];

    /** @var list<string> directories this test created, newest last */
    protected array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        PreviewPartials::flush();
        Blink::flush();

        config([
            'statamic-visual-editor.tailwind.store' => resource_path('visual-editor/tw'),
            'statamic.assets.set_preview_images' => ['container' => 'assets', 'folder' => 'set-previews'],
        ]);

        $container = Mockery::mock(AssetContainerModel::class);
        $container->shouldReceive('diskPath')->andReturn(public_path('assets'));
        AssetContainer::shouldReceive('find')->with('assets')->andReturn($container);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $file) {
            @unlink($file);
        }

        foreach (array_reverse($this->createdDirs) as $dir) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /** Writes a file under the test app, creating (and remembering) the folders it needs. */
    protected function file(string $relative, string $contents): string
    {
        $path = base_path($relative);
        $this->ensureDirectory(dirname($path));

        file_put_contents($path, $contents);
        $this->created[] = $path;

        return $path;
    }

    protected function ensureDirectory(string $dir): void
    {
        $missing = [];

        while (! is_dir($dir)) {
            $missing[] = $dir;
            $dir = dirname($dir);
        }

        foreach (array_reverse($missing) as $make) {
            mkdir($make, 0775);
            $this->createdDirs[] = $make;
        }
    }

    /** A registry with one group holding one section type. */
    protected function registry(array $groups): void
    {
        $sets = [];

        foreach ($groups as $group => [$display, $groupSets]) {
            $sets[$group] = ['display' => $display, 'sets' => $groupSets];
        }

        $contents = [
            'title' => 'Page sections',
            'fields' => [[
                'handle' => 'page_sections',
                'field' => ['type' => 'replicator', 'display' => 'Page sections', 'sets' => $sets],
            ]],
        ];

        $this->file('resources/fieldsets/page_sections.yaml', YAML::dump($contents));

        // The repository keeps the instance it handed out earlier in this test;
        // give it the file's new contents so reads match the disk, as they would
        // in a fresh request.
        if ($cached = Fieldset::find('page_sections')) {
            $cached->setContents($contents);
        }
    }

    /**
     * The fixture most tests share: `hero/style_2` with an own fieldset that
     * imports a shared block and borrows a field from `common`, a template that
     * pulls in the block partial, baked Tailwind for both, and a preview image.
     */
    protected function heroSite(): void
    {
        $this->registry([
            'hero' => ['Hero', [
                'hero/style_2' => [
                    'display' => 'Hero style 2',
                    'image' => 'hero-style-2-abc123.png',
                    'fields' => [['import' => 'hero.style_2']],
                ],
            ]],
            'static_sections' => ['Statiske sektioner', [
                'static_section/banner' => ['display' => 'Banner', 'static' => true, 'fields' => []],
            ]],
        ]);

        $this->file('resources/fieldsets/hero/style_2.yaml', "title: Hero\nfields:\n  -\n    import: blocks.headline\n  -\n    handle: settings\n    field: common.content_tab\n");
        $this->file('resources/fieldsets/blocks/headline.yaml', "title: Headline\nfields:\n  -\n    handle: text\n    field:\n      type: text\n");
        $this->file('resources/fieldsets/common.yaml', "title: Common\nfields:\n  -\n    handle: content_tab\n    field:\n      type: replicator\n      sets:\n        image:\n          fields:\n            - import: image\n");

        $this->file('resources/views/partials/page_sections/hero/style_2.antlers.html', "<section>{{ partial:blocks/headline }}</section>\n{{ sve_tw }}\n");
        $this->file('resources/views/partials/page_sections/static_section/banner.antlers.html', "<section>Banner</section>\n");
        $this->file('resources/views/partials/blocks/headline.antlers.html', "<h1>{{ text }}</h1>\n{{ sve_tw handle=\"view/partials/blocks/headline\" }}\n");

        $this->file('resources/visual-editor/tw/hero/style_2.css', ".p-4{padding:1rem}\n");
        $this->file('resources/visual-editor/tw/view/partials/blocks/headline.css', ".text-xl{font-size:1.25rem}\n");

        $this->file('public/assets/set-previews/hero-style-2-abc123.png', 'png');
        $this->file('public/assets/set-previews/.meta/hero-style-2-abc123.png.yaml', "width: 1440\n");
    }
}
