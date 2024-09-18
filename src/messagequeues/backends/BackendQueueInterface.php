<?php

namespace benjaminzwahlen\bracequeues\messagequeues\backend;

use benjaminzwahlen\bracequeues\messagequeues\tasks\TaskMessage;

interface BackendQueueInterface
{
    public function send(string $queueName, TaskMessage $data);

    public function registerWorker(string $queueName, callable $callback);
}
