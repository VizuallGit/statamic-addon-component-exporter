<?php

namespace Vizuall\ComponentExporter;

use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider as BaseAddonServiceProvider;
use Statamic\Statamic;
use Vizuall\ComponentExporter\Console\KitCommand;
use Vizuall\ComponentExporter\Http\Controllers\ComponentExporterController;

/**
 * Komponent Eksport: a Control Panel utility that moves section types between
 * sites as ZIPs — each with its fieldsets, partials, baked Tailwind and preview —
 * and a console command that keeps the starter kit's sections module honest.
 *
 * Requires the Visual Editor: the partial graph a section renders through is
 * the editor's (`PreviewPartials`), so that a section's files are decided in
 * exactly one place.
 */
class AddonServiceProvider extends BaseAddonServiceProvider
{
    protected $viewNamespace = 'component-exporter';

    protected $commands = [
        KitCommand::class,
    ];

    public function bootAddon(): void
    {
        // Cache-bust on contents. The utility is one Vue component, and a browser
        // holding an old copy shows a screen that looks right and calls routes
        // that have changed.
        $script = __DIR__.'/../resources/js/addon.js';

        $this->publishes([
            $script => public_path('vendor/component-exporter/js/addon.js'),
        ], 'component-exporter');

        Statamic::script('component-exporter', 'addon.js?v='.md5_file($script));

        Utility::register('component-exporter')
            ->title('Komponent Eksport')
            ->description('Eksportér og importér sektioner som ZIP — fieldsets, partials, Tailwind og preview følger med.')
            ->icon('package-box-crate')
            ->view('component-exporter::utilities.component-exporter')
            ->routes(function ($router) {
                $router->get('items', [ComponentExporterController::class, 'items']);
                $router->post('export', [ComponentExporterController::class, 'export']);
                $router->post('import/inspect', [ComponentExporterController::class, 'inspect']);
                $router->post('import', [ComponentExporterController::class, 'import']);
                $router->get('selection', [ComponentExporterController::class, 'selection']);
                $router->post('selection/toggle', [ComponentExporterController::class, 'toggleSelection']);
            });
    }
}
