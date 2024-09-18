<?php

namespace benjaminzwahlen\bracequeues\messagequeues\backends\rabbitmq;


use benjaminzwahlen\bracequeues\messagequeues\backends\BackendQueueInterface;
use benjaminzwahlen\bracequeues\messagequeues\tasks\TaskMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQ implements BackendQueueInterface
{
    public  string $host;
    public  string $port;
    public  string $username;
    public  string $password;
    public  int $retryTtlMillis;

    public  bool $passive = false;
    public  bool $durable = true;
    public  bool $exclusive = false;
    public  bool $autoDelete = false;


    public function __construct($host_, $port_, $username_, $password_, $retryTtlMillis_)
    {
        $this->host = $host_;
        $this->port = $port_;
        $this->username = $username_;
        $this->password = $password_;
        $this->retryTtlMillis = $retryTtlMillis_;
    }


    public function send(string $queueName, TaskMessage $data)
    {
        $connection = new AMQPStreamConnection($this->host, $this->port, $this->username, $this->password);

        $channel = $connection->channel();
        $msg = new AMQPMessage(
            serialize($data),
            array('delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT)
        );

        $channel->basic_publish($msg, '', $queueName);

        $channel->close();
        $connection->close();
    }


    public function registerWorker(string $queueName, callable $userCallback)
    {
        $connection = new AMQPStreamConnection($this->host, $this->port, $this->username, $this->password);
        $channel = $connection->channel();

        $retryQueueName = $queueName . "_retry";
        $queueExchangeName = $queueName . "_DLX";
        $retryQueueExchangeName = $retryQueueName . "_DLX";


        $channel->exchange_declare($queueExchangeName, 'direct', false, true);
        $channel->exchange_declare($retryQueueExchangeName, 'direct', false, true);


        //Standard queue
        $channel->queue_declare(
            $queueName,
            $this->passive,
            $this->durable,
            $this->exclusive,
            $this->autoDelete,
            false,
            new \PhpAmqpLib\Wire\AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $retryQueueName
            ])
        );
        $channel->queue_bind($queueName, $queueExchangeName);

        // Retry queue with TTL
        $channel->queue_declare($retryQueueName, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $queueName,
            'x-message-ttl' => $this->retryTtlMillis
        ]));
        $channel->queue_bind($retryQueueName, $retryQueueExchangeName);



        $localCallback = function ($msg) use ($userCallback) {
            try {
                $count = 0;
                if ($msg->has("application_headers"))
                    $count = $msg->get("application_headers")->getNativeData()["x-death"][0]["count"];

                $task = unserialize($msg->getBody());
                if (false === call_user_func($userCallback, $task)) {
                    echo "Worker failed task. Fail countr = " . ($count + 1) . "\n";
                    $msg->nack();
                } else
                    $msg->ack();
            } catch (\Throwable $t) {
                echo "FAILED " . $t->getMessage() . "\n";
                $msg->nack();
            }
        };

        $channel->basic_consume($queueName, '', false, false, false, false, $localCallback);

        while (count($channel->callbacks)) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}
