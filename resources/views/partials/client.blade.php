@once
    <script data-navigate-once>
        (function () {
            if (window.FilamentRealtimeDriver) {
                return;
            }

            // One WebSocket per page, multiplexed by "channel::event". Every
            // listener component, Table::socket() call and the Echo shim
            // below (used by Filament's own broadcasting-aware components)
            // all subscribe through this same connection.
            window.FilamentRealtimeDriver = {
                socket: null,
                socketId: null,
                subscriptions: {},
                subscribedChannels: new Set(),
                pendingChannels: new Set(),

                ensureConnected() {
                    if (this.socket) {
                        return;
                    }

                    this.socket = new WebSocket(@js(\Marcusvbda\FilamentRealtimeDriver\FilamentRealtimeDriverPlugin::browserSocketUrl()));
                    this.socket.addEventListener('message', (raw) => this.__handleMessage(raw));
                    this.socket.addEventListener('close', () => {
                        this.socket = null;
                        this.socketId = null;
                        this.subscribedChannels.clear();
                    });
                },

                __handleMessage(raw) {
                    const message = JSON.parse(raw.data);
                    const data = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;

                    if (message.event === 'pusher:connection_established') {
                        this.socketId = data.socket_id;
                        this.pendingChannels.forEach((channel) => this.__sendSubscribe(channel));
                        this.pendingChannels.clear();
                        return;
                    }

                    const callbacks = this.subscriptions[`${message.channel ?? ''}::${message.event}`];

                    if (callbacks) {
                        callbacks.forEach((callback) => callback(data));
                    }
                },

                async __sendSubscribe(channel) {
                    if (this.subscribedChannels.has(channel)) {
                        return;
                    }

                    this.subscribedChannels.add(channel);

                    const isProtected = channel.startsWith('private-') || channel.startsWith('presence-');
                    const payload = { channel };

                    if (isProtected) {
                        Object.assign(payload, await this.__authorize(channel));
                    }

                    this.socket.send(JSON.stringify({ event: 'pusher:subscribe', data: payload }));
                },

                // private-*/presence-* channels are signed by Laravel's own
                // /broadcasting/auth endpoint, guarded by whatever
                // Broadcast::channel() authorization is defined for them.
                async __authorize(channel) {
                    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
                    const token = match ? decodeURIComponent(match[1]) : '';

                    const response = await fetch('/broadcasting/auth', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-XSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new URLSearchParams({ socket_id: this.socketId, channel_name: channel }),
                    });

                    if (!response.ok) {
                        console.error('[filament-realtime-driver] channel authorization failed', channel, response.status);
                        return {};
                    }

                    return response.json();
                },

                // Returns an unsubscribe function. Cleanup is the caller's
                // responsibility to invoke it — Alpine in this project's
                // bundled Livewire version has no $cleanup magic to do it
                // automatically on element removal. Note for callers: if you
                // call this directly from an x-init/x-effect expression,
                // wrap the call in void(...) — Alpine's directive() runtime
                // treats any function a directive expression evaluates to as
                // an immediate auto-cleanup callback, and this function's
                // return value (the unsubscribe callback) would otherwise be
                // invoked right away, undoing the subscription instantly.
                subscribe(channel, event, callback) {
                    this.ensureConnected();

                    const key = `${channel}::${event}`;
                    this.subscriptions[key] ??= new Set();
                    this.subscriptions[key].add(callback);

                    if (this.socketId) {
                        this.__sendSubscribe(channel);
                    } else {
                        this.pendingChannels.add(channel);
                    }

                    return () => this.unsubscribe(channel, event, callback);
                },

                unsubscribe(channel, event, callback) {
                    const key = `${channel}::${event}`;
                    this.subscriptions[key]?.delete(callback);

                    if (this.subscriptions[key]?.size === 0) {
                        delete this.subscriptions[key];
                    }
                },
            };

            // Echo-compatible shim so Filament's own broadcasting-aware
            // components AND Livewire's own built-in Echo integration
            // (supportLaravelEcho.js — it calls window.Echo.socketId() on
            // every single request the moment window.Echo exists, not just
            // broadcast-related ones) work unmodified against our connection
            // instead of real Laravel Echo. Presence channel member events
            // (here/joining/leaving) are not implemented — nothing in this
            // package or Filament's own components needs them.
            if (!window.Echo) {
                const frdChannel = (prefix) => (name) => {
                    const channel = prefix ? `${prefix}-${name}` : name;
                    const listeners = {};

                    return {
                        listen(event, callback) {
                            const normalizedEvent = event.startsWith('.') ? event.slice(1) : event;
                            listeners[normalizedEvent] = callback;
                            window.FilamentRealtimeDriver.subscribe(channel, normalizedEvent, callback);
                            return this;
                        },
                        stopListening(event) {
                            const normalizedEvent = event.startsWith('.') ? event.slice(1) : event;
                            const callback = listeners[normalizedEvent];

                            if (callback) {
                                window.FilamentRealtimeDriver.unsubscribe(channel, normalizedEvent, callback);
                                delete listeners[normalizedEvent];
                            }

                            return this;
                        },
                        notification(callback) {
                            return this.listen('Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', callback);
                        },
                    };
                };

                window.Echo = {
                    channel: frdChannel(null),
                    private: frdChannel('private'),
                    join: frdChannel('presence'),
                    leave() {},
                    socketId() {
                        return window.FilamentRealtimeDriver.socketId ?? '';
                    },
                };

                window.dispatchEvent(new CustomEvent('EchoLoaded'));
            }
        })();
    </script>
@endonce
