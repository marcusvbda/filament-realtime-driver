<?php

namespace Marcusvbda\FilamentRealtimeDriver\Console;

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Marcusvbda\FilamentRealtimeDriver\FilamentRealtimeDriverPlugin;

class ListenCommand extends Command
{
    protected $signature = 'filament-realtime-driver:listen {panel=admin}';

    protected $description = 'Connect to the realtime server and dispatch received events to their registered watch() callbacks';

    public function handle(): int
    {
        $panel = Filament::getPanel($this->argument('panel'));

        /** @var FilamentRealtimeDriverPlugin $plugin */
        $plugin = $panel->getPlugin('filament-realtime-driver');

        $this->components->info("Connecting to the realtime server for panel [{$panel->getId()}]...");

        $plugin->connectAndListen();

        return self::SUCCESS;
    }
}
