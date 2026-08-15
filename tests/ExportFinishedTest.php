<?php

use pxlrbt\FilamentExcel\Events\ExportFinishedEvent;
use pxlrbt\FilamentExcel\FilamentExport;

function waitingExports($userId): array
{
    return cache()->get(FilamentExport::getNotificationCacheKey($userId), []);
}

it('parks a finished export in the cache for the next request', function () {
    ExportFinishedEvent::dispatch('abc-posts.xlsx', 1, 'de');

    expect(waitingExports(1))->toHaveCount(1)
        ->and(waitingExports(1)[0])->toMatchArray([
            'filename' => 'abc-posts.xlsx',
            'userId' => 1,
            'locale' => 'de',
        ]);
});

// Regression test for #271: a Uuid object here is passed on as the notification id,
// where a string is expected.
it('identifies a parked export with a string id', function () {
    ExportFinishedEvent::dispatch('abc-posts.xlsx', 1, 'en');

    expect(waitingExports(1)[0]['id'])->toBeString();
});

it('keeps exports that are still waiting', function () {
    ExportFinishedEvent::dispatch('abc-first.xlsx', 1, 'en');
    ExportFinishedEvent::dispatch('def-second.xlsx', 1, 'en');

    expect(waitingExports(1))->toHaveCount(2);
});

it('parks exports per user', function () {
    ExportFinishedEvent::dispatch('abc-mine.xlsx', 1, 'en');

    expect(waitingExports(2))->toBeEmpty();
});

it('ignores exports without a user', function () {
    ExportFinishedEvent::dispatch('abc-posts.xlsx', null, 'en');

    expect(waitingExports(null))->toBeEmpty();
});
