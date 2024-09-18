<?php

namespace benjaminzwahlen\bracequeues\messagequeues;

class EmailTaskMessage extends AbstractTaskMessage
{

    public function __construct($emailId_)
    {
        parent::__construct("/sendemail", ["emailId" => $emailId_]);
    }
}
