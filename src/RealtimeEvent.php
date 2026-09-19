<?php

namespace RecruiterLabs\FilamentRealtimeDriver;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Generic broadcast event: dispatch it directly with the channel, event name
 * and payload you want instead of writing a dedicated Event class per
 * realtime notification.
 *
 *     broadcast(new RealtimeEvent('jobs', 'JobUpdated', ['id' => $job->id]));
 */
class RealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $channel,
        public readonly string $event,
        public readonly array $data = [],
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel($this->channel);
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
