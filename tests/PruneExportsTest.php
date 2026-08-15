<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('filament-excel');
});

function putExport(string $path, int $ageInHours = 0): void
{
    $disk = Storage::disk('filament-excel');

    $disk->put($path, 'export');
    touch($disk->path($path), now()->subHours($ageInHours)->getTimestamp());
}

it('prunes exports older than a day', function () {
    putExport('old.xlsx', ageInHours: 25);

    $this->artisan('filament-excel:prune')->assertSuccessful();

    Storage::disk('filament-excel')->assertMissing('old.xlsx');
});

it('keeps exports from the last day', function () {
    putExport('fresh.xlsx', ageInHours: 23);

    $this->artisan('filament-excel:prune')->assertSuccessful();

    Storage::disk('filament-excel')->assertExists('fresh.xlsx');
});

// Regression test for #274: the listing was non-recursive and typed against FileAttributes,
// so a subdirectory on the disk both hid the exports inside it and crashed the command.
it('prunes exports inside subdirectories', function () {
    putExport('nested/old.xlsx', ageInHours: 25);
    putExport('nested/fresh.xlsx', ageInHours: 1);

    $this->artisan('filament-excel:prune')->assertSuccessful();

    Storage::disk('filament-excel')->assertMissing('nested/old.xlsx');
    Storage::disk('filament-excel')->assertExists('nested/fresh.xlsx');
});
