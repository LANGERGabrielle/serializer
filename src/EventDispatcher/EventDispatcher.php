<?php

declare(strict_types=1);

/*
 * Copyright 2016 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\Serializer\EventDispatcher;

use JMS\Serializer\Exception\InvalidArgumentException;

/**
 * Light-weight event dispatcher.
 *
 * This implementation focuses primarily on performance, and dispatching
 * events for certain classes. It is not a general purpose event dispatcher.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class EventDispatcher implements EventDispatcherInterface
{
    private $listeners = [];
    private $classListeners = [];

    public static function getDefaultMethodName($eventName)
    {
        return 'on' . str_replace(['_', '.'], '', $eventName);
    }

    /**
     * Sets the listeners.
     *
     * @param array $listeners
     */
    public function setListeners(array $listeners): void
    {
        $this->listeners = $listeners;
        $this->classListeners = [];
    }

    public function addListener(string $eventName, $callable, ?string $class = null, ?string $format = null, ?string $interface = null): void
    {
        $this->listeners[$eventName][] = [$callable, $class, $format, $interface];
        unset($this->classListeners[$eventName]);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber->getSubscribedEvents() as $eventData) {
            if (!isset($eventData['event'])) {
                throw new InvalidArgumentException(sprintf('Each event must have a "event" key.'));
            }

            $method = isset($eventData['method']) ? $eventData['method'] : self::getDefaultMethodName($eventData['event']);
            $class = isset($eventData['class']) ? $eventData['class'] : null;
            $format = isset($eventData['format']) ? $eventData['format'] : null;
            $interface = isset($eventData['interface']) ? $eventData['interface'] : null;
            $this->listeners[$eventData['event']][] = [[$subscriber, $method], $class, $format, $interface];
            unset($this->classListeners[$eventData['event']]);
        }
    }

    public function hasListeners(string $eventName, string $class, string $format): bool
    {
        if (!isset($this->listeners[$eventName])) {
            return false;
        }

        if (!isset($this->classListeners[$eventName][$class][$format])) {
            $this->classListeners[$eventName][$class][$format] = $this->initializeListeners($eventName, $class, $format);
        }

        return !!$this->classListeners[$eventName][$class][$format];
    }

    public function dispatch($eventName, string $class, string $format, Event $event): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        $object = $event instanceof ObjectEvent ? $event->getObject() : null;
        $objectClass = $object ? (get_class($object) . $class) : $class;

        if (!isset($this->classListeners[$eventName][$objectClass][$format])) {
            $this->classListeners[$eventName][$objectClass][$format] = [];
            foreach ($this->initializeListeners($eventName, $class, $format) as $listener) {
                if ($listener[3] !== null && !($object instanceof $listener[3])) {
                    continue;
                }

                $this->classListeners[$eventName][$objectClass][$format][] = $listener[0];
            }
        }

        foreach ($this->classListeners[$eventName][$objectClass][$format] as $listener) {

            if ($event->isPropagationStopped()) {
                break;
            }
            \call_user_func($listener, $event, $eventName, $class, $format, $this);
        }
    }

    /**
     * @param string $eventName
     * @param string $loweredClass
     * @param string $format
     *
     * @return array An array of listeners
     */
    protected function initializeListeners($eventName, $loweredClass, $format)
    {
        $listeners = [];
        foreach ($this->listeners[$eventName] as $listener) {
            if (null !== $listener[1] && $loweredClass !== $listener[1]) {
                continue;
            }
            if (null !== $listener[2] && $format !== $listener[2]) {
                continue;
            }

            $listeners[] = $listener;
        }

        return $listeners;
    }
}

