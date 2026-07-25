<?php

declare(strict_types=1);

namespace Architect\Queue\Events;

/**
 * Интерфейс диспетчера событий.
 */
interface EventDispatcherInterface
{
    /**
     * Диспетчеризует событие.
     *
     * @param object $event Объект события
     * @return void
     */
    public function dispatch(object $event): void;

    /**
     * Регистрирует слушателя для события.
     *
     * @param string $eventClass Класс события
     * @param callable $listener Слушатель
     * @return void
     */
    public function listen(string $eventClass, callable $listener): void;
}