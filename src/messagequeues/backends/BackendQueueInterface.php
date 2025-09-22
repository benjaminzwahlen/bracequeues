<?php

namespace benjaminzwahlen\bracequeues\messagequeues\backends;

use benjaminzwahlen\bracequeues\messagequeues\tasks\TaskMessage;

interface BackendQueueInterface
{
    public function send(string $exchangeName, TaskMessage $data);

    public function registerWorker(string $exchangeName, string $queueName, callable $callback);
}
