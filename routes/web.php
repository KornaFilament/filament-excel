<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('filament-excel/{path}', function (string $path) {
    $filename = substr($path, 37);
    $disk = Storage::disk('filament-excel');

    abort_unless($disk->exists($path), 404);

    // Stream through the disk rather than resolving a local path, so the export can
    // live on a shared disk that the queue worker and the web server both reach.
    $response = $disk->download($path, $filename);

    app()->terminating(fn () => $disk->delete($path));

    return $response;
})
    ->middleware(['web', 'signed'])
    ->where('path', '.*')
    ->name('filament-excel-download');
