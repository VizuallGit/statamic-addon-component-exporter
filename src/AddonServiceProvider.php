<?php

namespace Vizuall\ComponentExporter;

use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider as BaseAddonServiceProvider;
use Statamic\Statamic;
use Vizuall\ComponentExporter\Http\Controllers\ComponentExporterController;

class AddonServiceProvider extends BaseAddonServiceProvider
{
    protected $viewNamespace = 'component-exporter';

    public function bootAddon(): void
    {
        // Cache-bust på indhold. Utility'en er én stor Vue-komponent, og en
        // browser der holder fast i en gammel udgave viser en skærm der ser
        // rigtig ud, men kalder ruter der har ændret sig.
        $script = __DIR__.'/../resources/js/addon.js';

        $this->publishes([
            $script => public_path('vendor/component-exporter/js/addon.js'),
        ], 'component-exporter');

        Statamic::script('component-exporter', 'addon.js?v='.md5_file($script));

        Utility::register('component-exporter')
            ->title('Komponent Eksport')
            ->description('Eksporter og importer page sections, blueprints og collections som ZIP')
            ->icon('export')
            ->view('component-exporter::utilities.component-exporter')
            ->routes(function ($router) {
                $router->get('items', [ComponentExporterController::class, 'items']);
                $router->post('export', [ComponentExporterController::class, 'export']);
                $router->post('import/check', [ComponentExporterController::class, 'check']);
                $router->post('import', [ComponentExporterController::class, 'import']);
                $router->get('selection', [ComponentExporterController::class, 'selection']);
                $router->post('selection/toggle', [ComponentExporterController::class, 'toggleSelection']);
            });
    }
}
