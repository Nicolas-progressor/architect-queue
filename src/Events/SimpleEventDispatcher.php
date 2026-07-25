<?php

declare(strict_types=1);

namespace Architect\Queue\Events;

/**
 * Простая реализация диспетчера событий.
 */
class SimpleEventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<string, callable[]>
     */
    protected array $listeners = [];

    public function dispatch(object $event): void
    {
        $eventClass = get_class($event);
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        foreach ($this->listeners[$eventClass] as $listener) {
            $listener($event);
        }
    }

    public function listen(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }
        $this->listeners[$eventClass][] = $listener;
    }
}