<?php

namespace pxlrbt\FilamentExcel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;

class PruneExportsCommand extends Command
{
    protected $signature = 'filament-excel:prune';

    protected $description = 'Prune dangling exports';

    public function handle()
    {
        collect(Storage::disk('filament-excel')->listContents('', true))
            ->each(function (StorageAttributes $file) {
                if ($file->isFile() && $file->lastModified() < now()->subDay()->getTimestamp()) {
                    Storage::disk('filament-excel')->delete($file->path());
                }
            });
    }
}
