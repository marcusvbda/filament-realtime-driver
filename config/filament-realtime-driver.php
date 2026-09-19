<?php

return [
    'server' => env('FILAMENT_REALTIME_SERVER', 'localhost:8080'),

    'channel' => env('FILAMENT_REALTIME_CHANNEL', 'filament-realtime-driver'),

    // Reverb speaks the Pusher protocol, which requires the app key in the
    // WebSocket connection path. Defaults to this app's own Reverb driver key.
    'key' => env('REVERB_APP_KEY'),

    // Whether the browser should connect over wss:// instead of ws://.
    'secure' => (bool) env('FILAMENT_REALTIME_SECURE', env('REVERB_SCHEME', 'http') === 'https'),
];
