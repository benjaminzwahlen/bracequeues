<?php

namespace benjaminzwahlen\bracequeues\messagequeues;

use benjaminzwahlen\bracequeues\messagequeues\AbstractTaskMessage;

interface MessageQueueInterface
{
    public static function send(string $queueName, AbstractTaskMessage $data);

    public static function registerWorker(string $queueName, callable $callback);
}
