<?php

namespace RecruiterLabs\FilamentRealtimeDriver\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class Listener extends Component
{
    public function __construct(
        public string $channel,
        public string $event,
        public ?string $callback = null,
    ) {}

    public function render(): View
    {
        return view('filament-realtime-driver::components.listener');
    }
}
