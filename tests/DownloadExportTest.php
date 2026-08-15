<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('filament-excel');
});

function putQueuedExport(string $filename = 'export.xlsx'): string
{
    // Queued exports are prefixed with a UUID, which the route strips back off again.
    $path = Str::uuid().'-'.$filename;

    Storage::disk('filament-excel')->put($path, 'export');

    return $path;
}

function downloadUrl(string $path): string
{
    return URL::temporarySignedRoute('filament-excel-download', now()->addHour(), ['path' => $path]);
}

it('downloads an export under its original filename', function () {
    $path = putQueuedExport('posts.xlsx');

    $this->get(downloadUrl($path))
        ->assertSuccessful()
        ->assertDownload('posts.xlsx');
});

it('deletes the export after sending it', function () {
    $path = putQueuedExport();

    $this->get(downloadUrl($path))->assertSuccessful();

    Storage::disk('filament-excel')->assertMissing($path);
});

// Regression test for #277: the route used to resolve a local filesystem path, which
// breaks for a shared disk and for links pointing at an already deleted export.
it('returns 404 when the export no longer exists', function () {
    $this->get(downloadUrl(Str::uuid().'-export.xlsx'))->assertNotFound();
});

it('rejects unsigned download links', function () {
    $path = putQueuedExport();

    $this->get(route('filament-excel-download', ['path' => $path]))->assertForbidden();

    Storage::disk('filament-excel')->assertExists($path);
});
