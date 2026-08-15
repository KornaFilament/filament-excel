<?php

namespace pxlrbt\FilamentExcel;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use pxlrbt\FilamentExcel\Events\ExportFinishedEvent;

class FilamentExport
{
    public static ?Closure $createExportUrlUsing = null;

    public static function createExportUrlUsing(Closure $closure): void
    {
        static::$createExportUrlUsing = $closure;
    }

    /**
     * Hand a finished export over to the user it belongs to.
     *
     * This runs on the queue worker. Database notifications are stored in the database,
     * which the web server reads too, so they can be sent right here. Anything else has
     * to wait for the user's next request and is passed along through the cache — which
     * means the worker and the web server need to share a cache store.
     */
    public function handleExportFinished(ExportFinishedEvent $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $export = [
            'id' => Str::uuid()->toString(),
            'filename' => $event->filename,
            'userId' => $event->userId,
            'locale' => $event->locale,
        ];

        if ($this->sendDatabaseNotificationForPanel($event, $export)) {
            return;
        }

        $key = static::getNotificationCacheKey($event->userId);

        $exports = cache()->pull($key, []);
        $exports[] = $export;

        cache()->put($key, $exports);
    }

    protected function sendDatabaseNotificationForPanel(ExportFinishedEvent $event, array $export): bool
    {
        // `filament` is the facade's binding and only exists when panels are installed.
        if ($event->panelId === null || ! app()->bound('filament')) {
            return false;
        }

        $panel = Filament::getPanel($event->panelId, isStrict: false);

        if ($panel === null || ! $panel->hasDatabaseNotifications()) {
            return false;
        }

        $user = $panel->auth()->getProvider()->retrieveById($event->userId);

        if ($user === null) {
            return false;
        }

        $this->sendDatabaseNotification($export, $this->createUrl($export), $user);

        return true;
    }

    protected function sendDatabaseNotification(array $export, string $url, mixed $notifiable = null): void
    {
        $previousLocale = app()->getLocale();

        if (isset($export['locale'])) {
            app()->setLocale($export['locale']);
        }

        Notification::make(data_get($export, 'id'))
            ->title(__('filament-excel::notifications.download_ready.title'))
            ->body(__('filament-excel::notifications.download_ready.body'))
            ->success()
            ->icon('heroicon-o-arrow-down-tray')
            ->actions([
                Action::make('download')
                    ->label(__('filament-excel::notifications.download_ready.download'))
                    ->url($url, shouldOpenInNewTab: true)
                    ->button()
                    ->close(),
            ])
            ->sendToDatabase($notifiable ?? Filament::auth()->user());

        app()->setLocale($previousLocale);
    }

    protected function sendPersistentNotification(array $export, string $url): void
    {
        Notification::make(data_get($export, 'id'))
            ->title(__('filament-excel::notifications.download_ready.title'))
            ->body(__('filament-excel::notifications.download_ready.body'))
            ->success()
            ->icon('heroicon-o-arrow-down-tray')
            ->actions([
                Action::make('download')
                    ->label(__('filament-excel::notifications.download_ready.download'))
                    ->url($url, shouldOpenInNewTab: true)
                    ->button()
                    ->close(),
            ])
            ->persistent()
            ->send();
    }

    public function sendNotification(): void
    {
        $key = static::getNotificationCacheKey(Filament::auth()->id());

        if (! cache()->has($key)) {
            return;
        }

        $exports = cache()->pull($key);

        if (! filled($exports)) {
            return;
        }

        foreach ($exports as $export) {
            if (! Storage::disk('filament-excel')->exists($export['filename'])) {
                continue;
            }

            $url = $this->createUrl($export);

            if (Filament::getCurrentPanel()->hasDatabaseNotifications()) {
                $this->sendDatabaseNotification($export, $url);
            } else {
                $this->sendPersistentNotification($export, $url);
            }
        }
    }

    public static function getNotificationCacheKey($userId): string
    {
        return 'filament-excel:exports:'.$userId;
    }

    protected function createUrl(array $export): string
    {
        if (static::$createExportUrlUsing !== null) {
            return (static::$createExportUrlUsing)($export);
        }

        return URL::temporarySignedRoute(
            'filament-excel-download',
            now()->addHours(24),
            ['path' => $export['filename']]
        );
    }
}
