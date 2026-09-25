<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Events\EventBus;
use PHPUnit\Framework\TestCase;

final class EventBusTest extends TestCase
{
    public function testDispatchNotifiesListenersInOrder(): void
    {
        $bus = new EventBus();
        $seen = [];
        $bus->listen(static function (string $event, array $payload, ?int $resourceId) use (&$seen): void {
            $seen[] = [$event, $payload['x'] ?? null, $resourceId];
        });
        $bus->listen(static function (string $event, array $payload, ?int $resourceId) use (&$seen): void {
            $seen[] = 'second:' . $event;
        });

        $bus->dispatch('entry.created', ['x' => 1], 7);

        self::assertSame([['entry.created', 1, 7], 'second:entry.created'], $seen);
    }
}
