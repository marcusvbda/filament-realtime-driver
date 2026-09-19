# Filament Realtime Driver

Realtime updates for [Filament](https://filamentphp.com) panels, backed by [Laravel Reverb](https://reverb.laravel.com) (or any Pusher-protocol-compatible broadcaster), without Laravel Echo or `pusher-js` on the frontend.

It replaces `wire:poll` / database-notification polling with actual WebSocket pushes:

- **One shared WebSocket connection per page**, no matter how many components listen.
- **No JS dependencies.** The browser client speaks the Pusher wire protocol directly (~4 KB, inlined, no build step).
- **A tiny `window.Echo`-compatible shim**, so Filament's own broadcasting-aware components (flash notifications, database notifications) work completely unmodified.
- **Fully opt-in.** Nothing changes in a panel that doesn't call `->socket()`.
- **Public, private and presence channels**, with private-channel authorization handled automatically (browser-side via `/broadcasting/auth`, backend-side via a local HMAC signature).

---

## Requirements

- Laravel 11+ (built and tested against Laravel 13)
- Filament 5+
- A running Reverb server (or another Pusher-protocol broadcaster) — `BROADCAST_CONNECTION=reverb`

If you haven't set up broadcasting yet:

```bash
composer require laravel/reverb
php artisan reverb:install
```

This publishes `config/reverb.php`, adds `REVERB_*` / `VITE_REVERB_*` variables to `.env`, and sets `BROADCAST_CONNECTION=reverb`. Run the server locally with:

```bash
php artisan reverb:start
```

(or add it to your `composer.json` `dev` script via Laravel's `DevCommands::artisan('reverb:start', 'reverb')`, so `composer dev` starts it alongside everything else).

## Installation

Not published to Packagist yet — install as a local path package. In your app's root `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "packages/filament-realtime-driver" }
    ],
    "require": {
        "recruiter-labs/filament-realtime-driver": "*"
    }
}
```

```bash
composer update recruiter-labs/filament-realtime-driver
```

The service provider is auto-discovered — nothing else to register.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=filament-realtime-driver-config
```

## Configuration

`config/filament-realtime-driver.php`:

| Key | Env var | Default | Purpose |
|---|---|---|---|
| `server` | `FILAMENT_REALTIME_SERVER` | `localhost:8080` | `host:port` of the Reverb server the **browser** connects to |
| `channel` | `FILAMENT_REALTIME_CHANNEL` | `filament-realtime-driver` | Default channel the backend listener (`connectAndListen()`) auto-subscribes to |
| `key` | `REVERB_APP_KEY` | — | Reverb app key, required in the WebSocket connection path |
| `secure` | `FILAMENT_REALTIME_SECURE` | derived from `REVERB_SCHEME` | Whether the browser connects over `wss://` instead of `ws://` |

`key` reuses your existing `REVERB_APP_KEY` by default — there is normally nothing to configure beyond what `reverb:install` already set up.

## Quick start

Register the plugin on a panel and call `->socket()`:

```php
use RecruiterLabs\FilamentRealtimeDriver\FilamentRealtimeDriverPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            FilamentRealtimeDriverPlugin::make()->socket()
        );
}
```

`->socket()` alone doesn't change anything visible — it just makes the shared browser client available on that panel. You opt into individual features (tables, database notifications, or your own `<x-filament-realtime-driver::listener>` usage) separately, as shown below.

## How it works

- `->socket()` injects one small inline `<script>` (via a `BODY_END` render hook) that defines `window.FilamentRealtimeDriver` — a lazy, multiplexed WebSocket client keyed by `"channel::event"`. It only opens a connection the first time something actually subscribes.
- The same script defines a minimal `window.Echo` shim (`channel()` / `private()` / `join()` / `.listen()` / `.notification()` / `.socketId()`), because Livewire itself calls `window.Echo.socketId()` on every request once `window.Echo` exists, and Filament's native notification components call `window.Echo.private(...).listen(...)`. This lets those built-in Filament features work for free, without installing Laravel Echo.
- Everything — the Blade component, `Table::socket()`, and the database notifications bridge — subscribes through that same `window.FilamentRealtimeDriver.subscribe(channel, event, callback)` call, so a page with ten realtime widgets still opens exactly one socket.

## Usage

### 1. Frontend — the `<x-filament-realtime-driver::listener>` component

Drop it anywhere in a Blade view (inside or outside a Filament panel — it works on any page, it doesn't require Livewire):

```blade
<x-filament-realtime-driver::listener channel="orders" event="OrderUpdated">
    <h1 x-text="event.id"></h1>
    <span x-text="event.status"></span>

    @script
    <script>
        console.log('order updated', event);
    </script>
    @endscript
</x-filament-realtime-driver::listener>
```

Props:

| Prop | Required | Description |
|---|---|---|
| `channel` | yes | Channel name. Prefix with `private-` or `presence-` for protected channels. |
| `event` | yes | Event name — matches whatever `broadcastAs()` returns on the PHP side. |
| `callback` | no | Raw JS, evaluated every time an event arrives, alongside updating the reactive `event` variable used above. |

Inside the slot, `event` is an Alpine-reactive object holding whatever `broadcastWith()` sent — bind to it with `x-text`, `x-show`, etc.

To just trigger a Livewire refresh instead of reading the payload, skip the slot and use `callback`:

```blade
<x-filament-realtime-driver::listener channel="orders" event="OrderUpdated" callback="$wire.$refresh()" />
```

### 2. Filament Tables — `Table::socket()`

Adds a `socket()` method directly to `Filament\Tables\Table` (registered as a macro — Filament's `Table` is already `Macroable`, so this is a non-invasive extension, not a parallel implementation). Use it as an alternative to `->poll(...)`:

```php
use Filament\Tables\Table;

public static function table(Table $table): Table
{
    return $table
        ->columns([
            // ...
        ])
        ->socket(
            channel: 'orders',
            event: 'OrderUpdated',
        );
}
```

Behaviour:

- No periodic request of any kind. The table subscribes once, over the shared socket.
- When (and only when) the exact `channel`/`event` pair is received, the table's Livewire component gets `$wire.$refresh()`'d — a normal Livewire round trip, not a client-side row patch.
- Requires the panel's plugin to have `->socket()` enabled; if it doesn't, the injected listener no-ops safely (checks `window.FilamentRealtimeDriver &&` before using it) instead of throwing.

Fire the matching event from wherever the record changes — typically a model event:

```php
use RecruiterLabs\FilamentRealtimeDriver\RealtimeEvent;

protected static function booted(): void
{
    static::saved(function (Order $order): void {
        RealtimeEvent::dispatch('orders', 'OrderUpdated', ['id' => $order->id]);
    });
}
```

**Segmenting a channel per user (or tenant, or anything else):** parametrize the channel name with whatever identifier should scope who receives the update. This project's `JobsTable` does it per acting user:

```php
use Illuminate\Support\Facades\Auth;

->socket(
    channel: 'jobs_' . Auth::id(),
    event: 'JobUpdated',
)
```

```php
// App\Models\Job::booted()
static::saved(function (Job $job): void {
    RealtimeEvent::dispatch('jobs_' . Auth::id(), 'JobUpdated', ['id' => $job->id]);
});
```

Be precise about what this achieves: the browser subscribes to `jobs_{theViewer'sOwnId}`, and the dispatch broadcasts to `jobs_{theEditor'sId}` — so with `Auth::id()` on both sides, a user only sees a live refresh for edits *they themselves* triggered (useful for "your own action confirmed" UX, e.g. across two open tabs). If the goal is instead "everyone looking at this list sees every change", scope the channel by something shared by all viewers instead — e.g. `'jobs_' . $job->company_id` — and have every viewer's table subscribe to that same channel.

This is a **public channel** either way (no `private-` prefix): it only filters which *browser tabs* get the push, it is not an authorization boundary — anyone who knows or guesses the channel name can subscribe to it. See [Channel types](#channel-types-and-authorization) below if you need an actual access check.

### 3. Backend — a persistent PHP listener

For server-side reactions to realtime events (independent of any browser), configure a callback when calling `->socket()`:

```php
use RecruiterLabs\FilamentRealtimeDriver\RealtimeConnection;

->plugin(
    FilamentRealtimeDriverPlugin::make()->socket(
        function (RealtimeConnection $listener) {
            $listener->watch('event.example', function ($params) {
                Log::info('Realtime event received', ['params' => $params]);
            });
        }
    )
)
```

Run it with the bundled Artisan command — this is a **long-lived, blocking process**, not something you call from an HTTP request:

```bash
php artisan filament-realtime-driver:listen
```

Wire it into `composer dev` the same way you would `reverb:start` or a queue worker (in `AppServiceProvider::boot()`):

```php
if ($this->app->runningInConsole()) {
    DevCommands::artisan('filament-realtime-driver:listen', 'realtime');
}
```

If `->socket()` is called with no callback (as in the quick-start example), the command connects, finds nothing to watch, and exits immediately — it's a no-op, not an error. Backend listening is entirely optional and independent of frontend usage.

### 4. Database notifications

Replaces Filament's own database-notifications polling with a push, using the broadcast event Filament **already ships** (`Filament\Notifications\Events\DatabaseNotificationsSent`) — this package doesn't reimplement or duplicate the notifications UI at all.

```php
->plugin(
    FilamentRealtimeDriverPlugin::make()
        ->socket()
        ->databaseNotifications()
)
```

Two things to know:

1. **The panel itself must also enable database notifications** — that's Filament's own toggle for the bell UI, unrelated to this package:
   ```php
   $panel->databaseNotifications();
   ```
   This plugin only changes *how* that UI gets its updates, not whether it exists.

2. **Send notifications with `isEventDispatched: true`**, or nothing gets broadcast:
   ```php
   use Filament\Notifications\Notification;

   Notification::make()
       ->title('Something happened')
       ->body('Details here')
       ->sendToDatabase($user, isEventDispatched: true);
   ```

What `->databaseNotifications()` actually does:

- Calls `$panel->databaseNotificationsPolling(null)`, turning off Filament's default 30-second polling.
- Injects a small bridge script that subscribes to the current user's private notification channel and, on `database-notifications.sent`, fires `Livewire.dispatch('databaseNotificationsSent')` — a global Livewire event Filament's `DatabaseNotifications` component already listens for unconditionally.

That last point matters: Filament's own component only starts listening for realtime updates on its own once it has rendered at least one existing notification (a quirk in `database-notifications.blade.php`, not something this package can fix upstream). The bridge above works around it, so even a user's very first notification arrives live instead of needing a page reload.

The `notifications` table must exist (`php artisan make:notifications-table && php artisan migrate` if you haven't already), and on **PostgreSQL**, its `data` column must be `json`, not the framework's default `text` — Filament queries it with `data->>'format'`, which Postgres's `text` type doesn't support.

## Generic broadcasting — `RealtimeEvent`

Instead of writing a dedicated `Event` class for every notification, dispatch this ready-made one directly with a channel, event name and payload:

```php
use RecruiterLabs\FilamentRealtimeDriver\RealtimeEvent;

broadcast(new RealtimeEvent('orders', 'OrderUpdated', ['id' => $order->id]));
// or
RealtimeEvent::dispatch('orders', 'OrderUpdated', ['id' => $order->id]);
```

It implements `ShouldBroadcastNow` (broadcasts immediately, no queue worker required). Write your own `Event` class instead if you need queued broadcasting, or richer payload logic than "here's an array".

## Channel types and authorization

This package talks the **Pusher wire protocol** directly (the same protocol Reverb speaks) instead of using Laravel Echo / `pusher-js`. It never installs or requires those packages — the shim in [How it works](#how-it-works) exists purely so Filament's *own* code (which does expect a real `window.Echo`) keeps working.

- **Public channels** — any string not prefixed `private-`/`presence-`. No authorization: anyone who knows the name can subscribe. Fine for non-sensitive updates (a table refresh signal, a generic "something changed" ping).
- **Private channels** (`private-*`) — require authorization on every subscribe:
  - **Browser side**: the client calls Laravel's standard `POST /broadcasting/auth` automatically (sending the session cookie), exactly like Laravel Echo would.
  - **Backend listener side** (`RealtimeConnection`, used by `filament-realtime-driver:listen`): signs the subscription locally with your Reverb app key/secret (HMAC-SHA256) — no HTTP round-trip, since it already runs inside the Laravel app.
- **Presence channels** (`presence-*`) — subscribe/authorize the same way as private channels. The member-list events (`here`/`joining`/`leaving`) are **not implemented** — nothing in this package or in Filament's own broadcasting-aware components needs them.

Either way, you still need `routes/channels.php` authorization rules for **private/presence** channels — this package doesn't add or replace that, it's plain Laravel broadcasting:

```php
use Illuminate\Support\Facades\Broadcast;

// Required for Filament's own database notifications feature — publish it
// via `php artisan reverb:install` / `php artisan install:broadcasting`,
// or add it yourself if it's missing:
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Your own private channels need the same treatment:
Broadcast::channel('orders.{id}', function ($user, $id) {
    return $user->companies->contains('id', Order::find($id)?->company_id);
});
```

Public channels (like the `orders` / `jobs_{id}` examples earlier in this README) need **no entry** in `channels.php` at all — Reverb accepts a subscription to any public channel name without asking Laravel.

## Activating, configuring, segmenting — summary

- **Activate**: call `->socket()` on `FilamentRealtimeDriverPlugin::make()` in your panel provider. Nothing else in the package does anything until this is called.
- **Deactivate**: remove the `->socket()` call (or the whole `->plugin(...)`). Every feature — the shared client, `Table::socket()`, database notifications — is gated behind it and becomes fully inert.
- **Configure**: `config/filament-realtime-driver.php` (server, channel, key, secure) — see [Configuration](#configuration).
- **Toggle a single feature**: `->databaseNotifications()` is independent of `->socket()` — call it or don't, regardless of whether the socket itself is on.
- **Segment who receives what**: parametrize channel names with a user/tenant/record identifier (see the `Table::socket()` section above) — use a **private** channel with a `routes/channels.php` rule if that segmentation needs to be enforced, not just filtered client-side.

## Known limitations

- No automatic reconnection/backoff if the WebSocket connection drops.
- `Table::socket()` always does a full `$wire.$refresh()` — no client-side row patching.
- Presence channel member events (`here`/`joining`/`leaving`) are not implemented.
- Filament's own `DatabaseNotifications` component has a first-load quirk (see [Database notifications](#4-database-notifications)) that this package works around, but is worth knowing about if you build directly against Filament's Echo integration elsewhere.

## License

MIT.
