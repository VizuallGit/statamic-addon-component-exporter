<?php

namespace Vizuall\ComponentExporter\Console;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;
use Vizuall\ComponentExporter\Section\Manifest;
use Vizuall\ComponentExporter\Section\Registry;

/**
 * `php please component-exporter:kit` — does the starter kit's sections module
 * carry every file the registered section types own?
 *
 * The kit lists folders (`resources/fieldsets/hero`, …). The section manifest
 * lists files. This command holds the two against each other: every own file
 * of every section must sit under a listed path, and every listed path must
 * still exist — a path that does not stops `starter-kit:export` cold. With
 * `--write` the module's list is rewritten to what the manifests need plus the
 * listed paths that are not derivable from a section (saved sections, their
 * images). Comments and everything outside that one list stay as they are.
 *
 * "What belongs to a section" is decided by Manifest; this only reads it.
 */
class KitCommand extends Command
{
    protected $signature = 'component-exporter:kit
        {--write : Rewrite the module\'s export_paths to cover every section}
        {--kit=package/starter-kit.yaml : The kit config, relative to the site root}
        {--module=sections : The module that carries the section types}';

    protected $description = 'Check that the starter kit\'s sections module covers every file the section types own';

    public function handle(): int
    {
        $kitPath = base_path((string) $this->option('kit'));
        $module = (string) $this->option('module');

        if (! is_file($kitPath)) {
            $this->error("Kit config not found: {$kitPath}");

            return self::FAILURE;
        }

        $config = Yaml::parseFile($kitPath) ?: [];

        if (! isset($config['modules'][$module]) || ! is_array($config['modules'][$module])) {
            $this->error("Module [{$module}] is not in ".$this->option('kit'));

            return self::FAILURE;
        }

        $listed = array_values(array_map('strval', (array) ($config['modules'][$module]['export_paths'] ?? [])));
        $manifests = Manifest::forSections(array_keys(Registry::entries()));
        $own = KitPaths::ownFiles($manifests);
        $wanted = KitPaths::derive($manifests);

        $uncovered = array_values(array_filter($own, fn ($file) => ! KitPaths::covers($listed, $file)));
        $stale = array_values(array_filter($listed, fn ($path) => ! file_exists(base_path($path))));
        $toAdd = array_values(array_filter($wanted, fn ($path) => ! KitPaths::covers($listed, $path) && ! in_array($path, $listed, true)));

        $this->line(sprintf('%d section types, %d own files, %d paths listed in [%s].', count($manifests), count($own), count($listed), $module));

        foreach ($uncovered as $file) {
            $this->warn("  not covered: {$file}");
        }

        foreach ($stale as $path) {
            $this->warn("  listed but missing on disk: {$path}");
        }

        if ($toAdd && ! $this->option('write')) {
            $this->line('  paths that would cover everything:');

            foreach ($toAdd as $path) {
                $this->line("    - {$path}");
            }
        }

        if (! $uncovered && ! $stale) {
            $this->info('The module covers every section type.');

            return self::SUCCESS;
        }

        if (! $this->option('write')) {
            $this->line('Run with --write to rewrite the module\'s export_paths.');

            return self::FAILURE;
        }

        $kept = array_values(array_filter($listed, fn ($path) => ! in_array($path, $stale, true)));
        $paths = KitPaths::mergeLists($kept, $wanted);

        KitPaths::writeModuleList($kitPath, $module, $paths);

        $this->info(sprintf('Wrote %d paths to modules.%s.export_paths in %s.', count($paths), $module, $this->option('kit')));

        return self::SUCCESS;
    }
}
