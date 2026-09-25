<?php

declare(strict_types=1);

namespace Cms\Events;

/**
 * Sync in-process domain event dispatcher.
 * Controllers dispatch here; outbound webhooks (and future listeners) subscribe.
 */
final class EventBus
{
    /** @var list<callable(string, array<string, mixed>, ?int): void> */
    private array $listeners = [];

    /**
     * @param callable(string, array<string, mixed>, ?int): void $listener
     */
    public function listen(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload, ?int $resourceId = null): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event, $payload, $resourceId);
        }
    }

    /**
     * Run listeners after the HTTP response is flushed (shutdown / fastcgi_finish_request).
     *
     * @param array<string, mixed> $payload
     */
    public function dispatchAfterResponse(string $event, array $payload, ?int $resourceId = null): void
    {
        register_shutdown_function(function () use ($event, $payload, $resourceId): void {
            if (\function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $this->dispatch($event, $payload, $resourceId);
        });
    }
}
