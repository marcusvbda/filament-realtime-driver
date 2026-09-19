@once
    @auth
        <script data-navigate-once>
            document.addEventListener('livewire:init', () => {
                window.FilamentRealtimeDriver.subscribe(
                    @js('private-' . \RecruiterLabs\FilamentRealtimeDriver\FilamentRealtimeDriverPlugin::currentUserBroadcastChannel()),
                    'database-notifications.sent',
                    () => window.Livewire.dispatch('databaseNotificationsSent'),
                );
            });
        </script>
    @endauth
@endonce
