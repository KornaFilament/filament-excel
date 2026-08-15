<?php

use pxlrbt\FilamentExcel\FilamentExcelServiceProvider;

it('defaults the export disk to local storage', function () {
    expect(config('filesystems.disks.filament-excel'))
        ->toMatchArray(['driver' => 'local', 'root' => storage_path('app/filament-excel')]);
});

// Regression test for #277: the package used to overwrite the disk on every boot, so a
// shared disk for multi-server setups never took effect.
it('keeps a disk the app configured itself', function () {
    config()->set('filesystems.disks.filament-excel', ['driver' => 's3', 'bucket' => 'exports']);

    (new FilamentExcelServiceProvider(app()))->register();

    expect(config('filesystems.disks.filament-excel'))
        ->toMatchArray(['driver' => 's3', 'bucket' => 'exports']);
});
