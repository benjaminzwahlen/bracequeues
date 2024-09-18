<?php

namespace benjaminzwahlen\bracequeues\messagequeues;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQ implements MessageQueueInterface
{
    public static string $host;
    public static string $port;
    public static string $username;
    public static string $password;

    public static bool $passive = false;
    public static bool $durable = true;
    public static bool $exclusive = false;
    public static bool $autoDelete = false;


    public static function init($host_, $port_, $username_, $password_)
    {
        RabbitMQ::$host = $host_;
        RabbitMQ::$port = $port_;
        RabbitMQ::$username = $username_;
        RabbitMQ::$password = $password_;
    }


    public static function send(string $queueName, AbstractTaskMessage $data)
    {
        $connection = new AMQPStreamConnection(RabbitMQ::$host, RabbitMQ::$port, RabbitMQ::$username, RabbitMQ::$password);

        $channel = $connection->channel();
        $msg = new AMQPMessage(
            serialize($data),
            array('delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT)
        );

        $channel->basic_publish($msg, '', $queueName);

        $channel->close();
        $connection->close();
    }


    public static function registerWorker(string $queueName, callable $userCallback)
    {
        $connection = new AMQPStreamConnection(RabbitMQ::$host, RabbitMQ::$port, RabbitMQ::$username, RabbitMQ::$password);
        $channel = $connection->channel();

        $retryQueueName = $queueName . "_retry";
        $queueExchangeName = $queueName . "_DLX";
        $retryQueueExchangeName = $retryQueueName . "_DLX";


        $channel->exchange_declare($queueExchangeName, 'direct', false, true);
        $channel->exchange_declare($retryQueueExchangeName, 'direct', false, true);


        //Standard queue
        $channel->queue_declare(
            $queueName,
            RabbitMQ::$passive,
            RabbitMQ::$durable,
            RabbitMQ::$exclusive,
            RabbitMQ::$autoDelete,
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
            'x-message-ttl' => 5000
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
