<?php

namespace benjaminzwahlen\bracequeues\messagequeues\backends;

use benjaminzwahlen\bracequeues\messagequeues\tasks\TaskMessage;

interface BackendQueueInterface
{
    public function send(string $exchangeName, string $routingKey, TaskMessage $data);

    public function registerWorker(string $exchangeName, string $queueName, string $routingKey, callable $callback, int $delayMicro = 0);

    public function close();
}
