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
    public string $host;
    public string $port;
    public string $username;
    public string $password;
    public int $retryTtlMillis;
    public int $maxRetryCount;

    public bool $passive = false;
    public bool $durable = true;
    public bool $exclusive = false;
    public bool $autoDelete = false;

    private ?AMQPStreamConnection $connection = null;
    private $channel = null;

    public function __construct($host_, $port_, $username_, $password_, $retryTtlMillis_, $maxRetryCount_)
    {
        $this->host = $host_;
        $this->port = $port_;
        $this->username = $username_;
        $this->password = $password_;
        $this->retryTtlMillis = $retryTtlMillis_;
        $this->maxRetryCount = $maxRetryCount_;
    }

    /**
     * -------------------------
     * CONNECTION MANAGEMENT
     * -------------------------
     */
    private function connect(): void
    {
        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->username,
            $this->password
        );

        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();
    }

    private function ensureConnection(): void
    {
        if (
            $this->connection === null ||
            !$this->connection->isConnected()
        ) {
            $this->connect();
        }
    }

    private function reconnect(): void
    {
        try {
            if ($this->channel) {
                $this->channel->close();
            }
        } catch (\Throwable $e) {
        }

        try {
            if ($this->connection) {
                $this->connection->close();
            }
        } catch (\Throwable $e) {
        }

        $this->connection = null;
        $this->channel = null;

        $this->connect();
    }

    /**
     * -------------------------
     * PRODUCER
     * -------------------------
     */
    public function send(string $exchangeName, string $routingKey, TaskMessage $data)
    {
        $this->ensureConnection();

        $msg = new AMQPMessage(
            serialize($data),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        try {
            $this->channel->basic_publish($msg, $exchangeName, $routingKey);
            $this->channel->wait_for_pending_acks();
        } catch (\Throwable $e) {
            // reconnect + retry once
            $this->reconnect();

            $this->channel->basic_publish($msg, $exchangeName, $routingKey);
            $this->channel->wait_for_pending_acks();
        }
    }

    /**
     * -------------------------
     * WORKER
     * -------------------------
     */
    public function registerWorker(string $exchangeName, string $queueName, string $routingKey, callable $userCallback, int $delayMicro = 0)
    {
        while (true) {
            try {
                $this->runWorker($exchangeName, $queueName, $routingKey, $userCallback, $delayMicro);
            } catch (\Throwable $e) {
                echo "Worker crashed, reconnecting: {$e->getMessage()}\n";
                sleep(2); // basic backoff
                $this->reconnect();
                echo "Connected.\n";
            }
        }
    }

    private function runWorker(string $exchangeName, string $queueName, string $routingKey, callable $userCallback, int $delayMicro)
    {
        $this->ensureConnection();

        /**
         * 1️⃣ Declare exchanges
         */
        $this->channel->exchange_declare($exchangeName, 'direct', false, true, false);
        $this->channel->exchange_declare($exchangeName . "_retry", 'direct', false, true, false);
        $this->channel->exchange_declare($exchangeName . "_dlx", 'direct', false, true, false);

        /**
         * 2️⃣ Declare queues
         */

        // Dead-letter queue
        $this->channel->queue_declare($queueName . '_dlx', false, true, false, false);
        $this->channel->queue_bind($queueName . '_dlx', $exchangeName . '_dlx', $routingKey);

        // Retry queue with 10s TTL and dead-letter to main exchange
        $retry_args = new AMQPTable([
            'x-dead-letter-exchange' => $exchangeName,
            'x-dead-letter-routing-key' => $routingKey,
            'x-message-ttl' => $this->retryTtlMillis
        ]);
        $this->channel->queue_declare($queueName . '_retry', false, true, false, false, false, $retry_args);
        $this->channel->queue_bind($queueName . '_retry', $exchangeName . '_retry', $routingKey);

        // Main queue with DLX to retry exchange
        $main_args = new AMQPTable([
            'x-dead-letter-exchange' => $exchangeName . '_retry',
            'x-dead-letter-routing-key' => $routingKey
        ]);
        $this->channel->queue_declare($queueName, false, true, false, false, false, $main_args);
        $this->channel->queue_bind($queueName, $exchangeName, $routingKey);

        $this->channel->basic_qos(null, 1, null);





        $channel = $this->channel; // For use in callback

        $localCallback = function ($msg) use ($userCallback, $channel, $exchangeName, $routingKey, $delayMicro) {

            $headers = $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : [];
            $xDeath = $headers['x-death'][0]['count'] ?? 0;

            $task = unserialize($msg->getBody());

            try {
                if (false === call_user_func($userCallback, $task)) {

                    if ($xDeath >= $this->maxRetryCount) {
                        $dlxMsg = new AMQPMessage($msg->body, [
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                        ]);
                        $channel->basic_publish($dlxMsg, $exchangeName . '_dlx', $routingKey);
                        $msg->ack();
                        echo "Moved to DLX\n";
                    } else {
                        $msg->nack(false, false);
                        echo "Retry " . ($xDeath + 1) . "\n";
                    }
                } else {
                    $msg->ack();
                }
            } catch (\Throwable $e) {
                // fail-safe: don't lose message
                $msg->nack(false, false);
            }

            if ($delayMicro > 0) {
                usleep($delayMicro);
            }
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            echo "Shutdown signal received\n";
            $this->close();
            exit;
        });

        $channel->basic_consume($queueName, '', false, false, false, false, $localCallback);

        while ($channel->is_consuming()) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException $e) {
                // keep loop alive
            }
        }
    }

    /**
     * -------------------------
     * CLEANUP
     * -------------------------
     */
    public function close(): void
    {
        try {
            if ($this->channel) {
                $this->channel->close();
            }
        } catch (\Throwable $e) {
        }

        try {
            if ($this->connection) {
                $this->connection->close();
            }
        } catch (\Throwable $e) {
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
