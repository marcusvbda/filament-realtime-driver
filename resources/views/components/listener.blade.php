@include('filament-realtime-driver::partials.client')

<div x-data="{
    event: {},
    __frdUnsubscribe: null,
    __frdInit(channel, eventName) {
        this.__frdUnsubscribe = window.FilamentRealtimeDriver.subscribe(channel, eventName, (data) => {
            this.event = data;

            @if($callback)
            {!! $callback !!};
            @endif
        });
    },
}" x-init="__frdInit(@js($channel), @js($event))">
    {{ $slot }}
</div>
