<?php

namespace benjaminzwahlen\bracequeues\messagequeues;

abstract class AbstractTaskMessage
{
    public string $path;
    public array $params;

    public function __construct(string $path_, array $params_)
    {
        $this->path = $path_;
        $this->params = $params_;
    }
}
