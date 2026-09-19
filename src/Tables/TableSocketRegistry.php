<?php

namespace RecruiterLabs\FilamentRealtimeDriver\Tables;

use Filament\Tables\Table;
use WeakMap;

/**
 * Backs Table::socket() with per-instance state. A WeakMap keyed by the
 * Table instance itself avoids adding a dynamic property to a Filament
 * class we don't own, and lets entries be garbage-collected with the table.
 */
class TableSocketRegistry
{
    /** @var WeakMap<Table, array{channel: string, event: string}> */
    protected static WeakMap $configs;

    public static function set(Table $table, string $channel, string $event): void
    {
        static::$configs ??= new WeakMap();
        static::$configs[$table] = ['channel' => $channel, 'event' => $event];
    }

    /**
     * @return array{channel: string, event: string}|null
     */
    public static function get(Table $table): ?array
    {
        static::$configs ??= new WeakMap();

        return static::$configs[$table] ?? null;
    }
}
