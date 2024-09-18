<?php

namespace benjaminzwahlen\bracequeues\messagequeues\tasks;

class TaskMessage
{
    public string $path;
    public array $params;

    public function __construct(string $path_, array $params_)
    {
        $this->path = $path_;
        $this->params = $params_;
    }
}
