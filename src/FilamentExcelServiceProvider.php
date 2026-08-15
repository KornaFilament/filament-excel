<?php

namespace pxlrbt\FilamentExcel;

use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use pxlrbt\FilamentExcel\Commands\PruneExportsCommand;
use pxlrbt\FilamentExcel\Events\ExportFinishedEvent;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentExcelServiceProvider extends PackageServiceProvider
{
    public function register(): void
    {
        // Only provide a default. Multi-server setups need to point this at a shared
        // disk (S3, …) in config/filesystems.php, or the queue worker writes exports
        // where the web server cannot find them.
        if (config('filesystems.disks.filament-excel') === null) {
            config()->set('filesystems.disks.filament-excel', [
                'driver' => 'local',
                'root' => storage_path('app/filament-excel'),
                'url' => config('app.url').'/filament-excel',
            ]);
        }

        parent::register();
    }

    public function configurePackage(Package $package): void
    {
        $package->name('filament-excel')
            ->hasCommands([PruneExportsCommand::class])
            ->hasRoutes(['web'])
            ->hasTranslations();
    }

    public function bootingPackage()
    {
        Filament::serving(fn () => app(FilamentExport::class)->sendNotification());

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneExportsCommand::class)->daily();
        });

        Event::listen(
            ExportFinishedEvent::class,
            fn (ExportFinishedEvent $event) => app(FilamentExport::class)->handleExportFinished($event)
        );
    }
}
