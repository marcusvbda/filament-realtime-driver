<?php

namespace RecruiterLabs\FilamentRealtimeDriver;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

class FilamentRealtimeDriverPlugin implements Plugin
{
    protected RealtimeConnection $socket;

    /** @var callable|null */
    protected $init = null;

    protected ?string $server = null;

    protected array $auth = [];

    /**
     * Whether ->socket() was called at all (regardless of arguments). Drives
     * every frontend feature (the shared browser client, database
     * notifications, table sockets) — none of them do anything unless this
     * is true, so a panel that never calls ->socket() sees no behavior
     * change at all.
     */
    protected bool $socketEnabled = false;

    protected bool $databaseNotifications = false;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'filament-realtime-driver';
    }

    /**
     * Enables the plugin. $init/$server/$auth configure the optional
     * BACKEND listener (php artisan filament-realtime-driver:listen) — none
     * of that is required for frontend usage, so `->socket()` alone is a
     * valid, common call.
     */
    public function socket(?callable $init = null, ?string $server = null, array $auth = []): static
    {
        $this->socketEnabled = true;
        $this->init = $init;
        $this->server = $server;
        $this->auth = $auth;

        return $this;
    }

    /**
     * Opt-in: replaces Filament's own database notifications polling with a
     * realtime update driven by the same broadcast event Filament already
     * dispatches (Filament\Notifications\Events\DatabaseNotificationsSent,
     * on `sendToDatabase($user, isEventDispatched: true)`), through the
     * shared browser client's Echo-compatible shim. Does nothing to the
     * notifications UI itself — that stays 100% native Filament.
     */
    public function databaseNotifications(bool $condition = true): static
    {
        $this->databaseNotifications = $condition;

        return $this;
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        if (! $this->socketEnabled) {
            return;
        }

        if ($this->databaseNotifications) {
            $panel->databaseNotificationsPolling(null);
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): string {
                $html = view('filament-realtime-driver::partials.client')->render();

                if ($this->databaseNotifications) {
                    $html .= view('filament-realtime-driver::partials.database-notifications-bridge')->render();
                }

                return $html;
            },
        );
    }

    /**
     * The channel Filament's own DatabaseNotifications component broadcasts
     * a user's notifications on — same logic as its own getBroadcastChannel().
     */
    public static function currentUserBroadcastChannel(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'receivesBroadcastNotificationsOn')) {
            return $user->receivesBroadcastNotificationsOn();
        }

        return str_replace('\\', '.', $user::class) . '.' . $user->getAuthIdentifier();
    }

    /**
     * The Pusher-protocol WebSocket URL browsers connect to. Shared by the
     * <x-filament-realtime-driver::listener> component and the page-level
     * client bootstrap, so both agree on where to connect without
     * duplicating the config lookup.
     */
    public static function browserSocketUrl(): string
    {
        $server = config('filament-realtime-driver.server');
        $key = config('filament-realtime-driver.key');
        $scheme = config('filament-realtime-driver.secure') ? 'wss' : 'ws';

        return "{$scheme}://{$server}/app/{$key}?protocol=7&client=js&version=1.0";
    }

    /**
     * Connect to the realtime server, run the registered watch() callbacks
     * through the init callback, and block listening for events.
     *
     * This must only run inside a long-lived process (the
     * filament-realtime-driver:listen console command), never during an
     * HTTP request or a one-off artisan command — it never returns while
     * connected.
     */
    public function connectAndListen(): void
    {
        if ($this->init === null) {
            return;
        }

        $server = $this->server ?? config('filament-realtime-driver.server');
        $auth = $this->auth + ['key' => config('filament-realtime-driver.key')];

        $this->socket = new RealtimeConnection();
        $this->socket->connect($server, $auth);

        if ($channel = config('filament-realtime-driver.channel')) {
            $this->socket->subscribe($channel);
        }

        ($this->init)($this->socket);

        $this->socket->listen();
    }
}
