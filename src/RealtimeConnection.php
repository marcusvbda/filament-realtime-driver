<?php

namespace Marcusvbda\FilamentRealtimeDriver;

use RuntimeException;

class RealtimeConnection
{
    protected string $server = '';

    protected array $events = [];

    protected ?string $socketId = null;

    /** @var resource|null */
    protected $connection = null;

    public function connect(string $url, array $auth = []): static
    {
        $this->server = $url;

        [$host, $port] = $this->parseServer($url);

        $connection = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            10,
        );

        if ($connection === false) {
            throw new RuntimeException("Unable to connect to realtime server [{$url}]: {$errorMessage} ({$errorCode})");
        }

        $this->connection = $connection;

        $this->handshake($host, $port, $auth);
        $this->awaitConnectionEstablished();

        return $this;
    }

    public function watch(string $event, callable $callback): static
    {
        $this->events[$event] = $callback;

        return $this;
    }

    /**
     * Subscribe to a Pusher-protocol channel, so channel-scoped broadcasts
     * (e.g. events sent through Laravel's broadcast() on a Reverb driver)
     * start reaching this connection.
     *
     * `private-*` and `presence-*` channels are signed locally with this
     * app's own Reverb key/secret (the same signature Laravel's
     * /broadcasting/auth endpoint would return), since this connection runs
     * inside the Laravel app itself. $channelData is only used for presence
     * channels (the user info Reverb exposes to other subscribers).
     */
    public function subscribe(string $channel, array $channelData = []): static
    {
        $this->ensureConnected();

        $data = ['channel' => $channel];

        if ($this->isProtectedChannel($channel)) {
            $data += $this->authorize($channel, $channelData);
        }

        $this->writeFrame(json_encode([
            'event' => 'pusher:subscribe',
            'data' => $data,
        ]));

        return $this;
    }

    /**
     * Block and read incoming frames, dispatching each received event to its
     * registered callable until the connection is closed.
     */
    public function listen(): void
    {
        $this->ensureConnected();

        while (! feof($this->connection)) {
            $payload = $this->readFrame();

            if ($payload === null) {
                continue;
            }

            $this->dispatch($payload);
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }

        $this->connection = null;
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function parseServer(string $url): array
    {
        if (! str_contains($url, '://')) {
            $url = "tcp://{$url}";
        }

        $parts = parse_url($url);

        if (! isset($parts['host'], $parts['port'])) {
            throw new RuntimeException("Invalid realtime server address [{$url}]. Expected format \"host:port\".");
        }

        return [$parts['host'], $parts['port']];
    }

    protected function handshake(string $host, int $port, array $auth): void
    {
        $wsKey = base64_encode(random_bytes(16));

        // Reverb speaks the Pusher protocol, which is addressed by app key
        // in the connection path rather than negotiated after the upgrade.
        $path = isset($auth['key'])
            ? '/app/' . $auth['key'] . '?protocol=7&client=filament-realtime-driver&version=1.0'
            : '/';

        $headers = [
            "GET {$path} HTTP/1.1",
            "Host: {$host}:{$port}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$wsKey}",
            'Sec-WebSocket-Version: 13',
        ];

        if ($token = $auth['token'] ?? null) {
            $headers[] = "Authorization: Bearer {$token}";
        }

        foreach ($auth['headers'] ?? [] as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        fwrite($this->connection, implode("\r\n", $headers) . "\r\n\r\n");

        $response = '';

        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fgets($this->connection, 1024);

            if ($chunk === false) {
                throw new RuntimeException("Realtime server [{$this->server}] closed the connection during handshake.");
            }

            $response .= $chunk;
        }

        if (! str_contains($response, '101')) {
            throw new RuntimeException("Realtime server [{$this->server}] refused the WebSocket handshake: {$response}");
        }
    }

    protected function ensureConnected(): void
    {
        if (! is_resource($this->connection)) {
            throw new RuntimeException("Not connected to a realtime server. Call connect() first.");
        }
    }

    /**
     * Read the first frame Reverb sends after a successful handshake, so the
     * socket_id it carries is available for signing private/presence channel
     * subscriptions. Any other frame received here is dispatched normally
     * rather than dropped.
     */
    protected function awaitConnectionEstablished(): void
    {
        $payload = $this->readFrame();

        if ($payload === null) {
            return;
        }

        $message = json_decode($payload, true);

        if (($message['event'] ?? null) !== 'pusher:connection_established') {
            $this->dispatch($payload);

            return;
        }

        $data = $message['data'] ?? null;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        $this->socketId = $data['socket_id'] ?? null;
    }

    protected function isProtectedChannel(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    /**
     * @return array{auth: string, channel_data?: string}
     */
    protected function authorize(string $channel, array $channelData): array
    {
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');

        if (! $key || ! $secret) {
            throw new RuntimeException("Cannot subscribe to protected channel [{$channel}]: Reverb key/secret is not configured.");
        }

        if (! $this->socketId) {
            throw new RuntimeException("Cannot subscribe to protected channel [{$channel}]: no socket_id received from the server yet.");
        }

        $signaturePayload = "{$this->socketId}:{$channel}";
        $result = [];

        if (str_starts_with($channel, 'presence-')) {
            $encodedChannelData = json_encode($channelData);
            $signaturePayload .= ":{$encodedChannelData}";
            $result['channel_data'] = $encodedChannelData;
        }

        $result['auth'] = $key . ':' . hash_hmac('sha256', $signaturePayload, $secret);

        return $result;
    }

    protected function dispatch(string $payload): void
    {
        $message = json_decode($payload, true);

        if (! is_array($message) || ! isset($message['event'])) {
            return;
        }

        $callback = $this->events[$message['event']] ?? null;

        if ($callback === null) {
            return;
        }

        $data = $message['data'] ?? null;

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        $callback($data);
    }

    /**
     * Write a masked WebSocket text frame. Client-to-server frames must be
     * masked per RFC 6455.
     */
    protected function writeFrame(string $payload): void
    {
        $length = strlen($payload);
        $mask = random_bytes(4);

        $frame = chr(0x81);

        if ($length < 126) {
            $frame .= chr($length | 0x80);
        } elseif ($length < 65536) {
            $frame .= chr(126 | 0x80) . pack('n', $length);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $length);
        }

        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->connection, $frame);
    }

    /**
     * Read a single WebSocket text frame and return its decoded payload.
     */
    protected function readFrame(): ?string
    {
        $header = fread($this->connection, 2);

        if ($header === false || strlen($header) < 2) {
            return null;
        }

        $bytes = unpack('C2', $header);
        $length = $bytes[2] & 0b01111111;

        if ($length === 126) {
            $extended = fread($this->connection, 2);
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = fread($this->connection, 8);
            $length = unpack('J', $extended)[1];
        }

        $payload = '';

        while (strlen($payload) < $length) {
            $chunk = fread($this->connection, $length - strlen($payload));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $payload .= $chunk;
        }

        return $payload;
    }
}
