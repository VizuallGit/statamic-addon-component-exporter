<?php

namespace Vizuall\ComponentExporter\Section;

use Statamic\Facades\AssetContainer;
use Statamic\Fieldtypes\Sets;

/**
 * The preview image a set carries (`image: hero-style-2-8ee5f2d0.png`) as
 * files on disk, where Statamic itself keeps set preview images
 * (`config/statamic/assets.php` → `set_preview_images`): the picture and the
 * `.meta` YAML Statamic writes beside it.
 */
final class Previews
{
    /**
     * The preview files that exist for an image name, relative to the site root.
     *
     * @return list<string>
     */
    public static function filesFor(?string $image): array
    {
        if (! is_string($image) || $image === '' || ($directory = static::directory()) === null) {
            return [];
        }

        $files = [];

        foreach ([$directory.'/'.$image, $directory.'/.meta/'.$image.'.yaml'] as $path) {
            if (is_file($path)) {
                $files[] = Paths::relative($path);
            }
        }

        return $files;
    }

    /** The preview folder, relative to the site root, or null when the site has none. */
    public static function relativeDirectory(): ?string
    {
        $directory = static::directory();

        return $directory === null ? null : Paths::relative($directory);
    }

    /** Absolute path of the preview folder, or null when the container is not configured. */
    private static function directory(): ?string
    {
        $config = Sets::previewImageConfig();

        if (! $config || ! ($container = AssetContainer::find($config['container']))) {
            return null;
        }

        $root = rtrim((string) $container->diskPath(), '/\\');
        $folder = trim((string) ($config['folder'] ?? ''), '/');

        return $folder === '' ? $root : $root.'/'.$folder;
    }
}
