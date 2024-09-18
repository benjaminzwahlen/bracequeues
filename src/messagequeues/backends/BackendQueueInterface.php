<?php

namespace benjaminzwahlen\bracequeues\messagequeues\backends;

use benjaminzwahlen\bracequeues\messagequeues\tasks\TaskMessage;

interface BackendQueueInterface
{
    public function send(string $queueName, TaskMessage $data);

    public function registerWorker(string $queueName, callable $callback);
}
