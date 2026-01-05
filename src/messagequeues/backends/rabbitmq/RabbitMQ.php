<?php

namespace benjaminzwahlen\bracequeues\messagequeues\backends\rabbitmq;


use benjaminzwahlen\bracequeues\messagequeues\backends\BackendQueueInterface;
use benjaminzwahlen\bracequeues\messagequeues\tasks\TaskMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQ implements BackendQueueInterface
{
    public  string $host;
    public  string $port;
    public  string $username;
    public  string $password;
    public  int $retryTtlMillis;
    public  int $maxRetryCount;

    public  bool $passive = false;
    public  bool $durable = true;
    public  bool $exclusive = false;
    public  bool $autoDelete = false;


    public function __construct($host_, $port_, $username_, $password_, $retryTtlMillis_, $maxRetryCount_)
    {
        $this->host = $host_;
        $this->port = $port_;
        $this->username = $username_;
        $this->password = $password_;
        $this->retryTtlMillis = $retryTtlMillis_;
        $this->maxRetryCount = $maxRetryCount_;
    }


    public function send(string $exchangeName, TaskMessage $data)
    {
        $connection = new AMQPStreamConnection($this->host, $this->port, $this->username, $this->password);

        $channel = $connection->channel();
        $msg = new AMQPMessage(
            serialize($data),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ]
        );

        $channel->basic_publish($msg, $exchangeName);

        $channel->close();
        $connection->close();
    }


    public function registerWorker(string $exchangeName, string $queueName, callable $userCallback, int $delayMicro = 0)
    {

        $connection = new AMQPStreamConnection($this->host, $this->port, $this->username, $this->password);
        $channel = $connection->channel();

        /**
         * 1️⃣ Declare exchanges
         */
        $channel->exchange_declare($exchangeName, 'fanout', false, true, false);
        $channel->exchange_declare($exchangeName . "_retry", 'fanout', false, true, false);
        $channel->exchange_declare($exchangeName . "_dlx", 'fanout', false, true, false);

        /**
         * 2️⃣ Declare queues
         */

        // Dead-letter queue
        $channel->queue_declare($queueName . '_dlx', false, true, false, false);
        $channel->queue_bind($queueName . '_dlx', $exchangeName . '_dlx');

        // Retry queue with 10s TTL and dead-letter to main exchange
        $retry_args = new AMQPTable([
            'x-dead-letter-exchange' => $exchangeName,
            'x-message-ttl' => $this->retryTtlMillis
        ]);
        $channel->queue_declare($queueName . '_retry', false, true, false, false, false, $retry_args);
        $channel->queue_bind($queueName . '_retry', $exchangeName . '_retry');


        // Main queue with DLX to retry exchange
        $main_args = new AMQPTable([
            'x-dead-letter-exchange' => $exchangeName . '_retry'
        ]);
        $channel->queue_declare($queueName, false, true, false, false, false, $main_args);
        $channel->queue_bind($queueName, $exchangeName);








        $localCallback = function ($msg) use ($userCallback, $channel, $exchangeName, $delayMicro) {

            $headers = $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : [];
            $xDeath = $headers['x-death'][0]['count'] ?? 0;


            $task = unserialize($msg->getBody());

            if (false === call_user_func($userCallback, $task)) {


                if ($xDeath >= $this->maxRetryCount) {
                    // Max retries → manually publish to DLX
                    $dlxMsg = new AMQPMessage($msg->body, [
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                    ]);
                    $channel->basic_publish($dlxMsg, $exchangeName . '_dlx');
                    $msg->ack(); // Remove from main queue
                    echo "Worker failed task. Moving to DLX\n";
                } else {
                    // Reject message, DLX routes it to retry queue
                    echo "Worker failed task. Fail countr = " . ($xDeath + 1) . "\n";
                    $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], false, false);
                }
            } else {
                //Task successfully processed.
                $msg->ack();
            }
            if ($delayMicro > 0) {
                usleep($delayMicro);
            }
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use ($channel) {
            echo "Received termination signal.\n";
            $channel->close();
        });

        $channel->basic_consume($queueName, '', false, false, false, false, $localCallback);

        while ($channel->is_consuming()) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException $e) {
                // Timeout — check for signals and loop
            }
        }

        $channel->close();
        $connection->close();
    }
}
