<?php

namespace Marcusvbda\FilamentRealtimeDriver;

use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Marcusvbda\FilamentRealtimeDriver\Console\ListenCommand;
use Marcusvbda\FilamentRealtimeDriver\Tables\TableSocketRegistry;

class FilamentRealtimeDriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/filament-realtime-driver.php', 'filament-realtime-driver');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'filament-realtime-driver');

        Blade::componentNamespace('Marcusvbda\\FilamentRealtimeDriver\\View\\Components', 'filament-realtime-driver');

        $this->registerTableSocketMacro();

        $this->publishes([
            __DIR__ . '/../config/filament-realtime-driver.php' => config_path('filament-realtime-driver.php'),
        ], 'filament-realtime-driver-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListenCommand::class,
            ]);
        }
    }


    /**
     * Adds Table::socket() as a non-invasive extension (Filament's own Table
     * is already Macroable) instead of a parallel Table implementation.
     * Requires the panel's plugin to also call ->socket(), since that's what
     * makes window.FilamentRealtimeDriver exist in the browser at all — the
     * injected x-init no-ops if it doesn't.
     */
    protected function registerTableSocketMacro(): void
    {
        Table::macro('socket', function (string $channel, string $event): Table {
            /** @var Table $this */
            TableSocketRegistry::set($this, $channel, $event);

            // extraAttributes() renders values unescaped (by design, so
            // callers can pass raw Alpine expressions), so this attribute
            // value — which itself contains double quotes from json_encode()
            // — must be HTML-escaped here, or it breaks out of the
            // surrounding x-init="..." attribute.
            //
            // Wrapped in void(...): Alpine's directive() registration treats
            // any function an x-init expression evaluates to as an
            // auto-cleanup callback and invokes it immediately. subscribe()
            // returns an unsubscribe function, so without void(...) Alpine
            // was unsubscribing the table the instant it subscribed.
            $this->extraAttributes([
                'x-init' => e(
                    'void (window.FilamentRealtimeDriver && window.FilamentRealtimeDriver.subscribe('
                        . json_encode($channel) . ', ' . json_encode($event) . ', () => $wire.$refresh()))'
                ),
            ], merge: true);

            return $this;
        });
    }
}
