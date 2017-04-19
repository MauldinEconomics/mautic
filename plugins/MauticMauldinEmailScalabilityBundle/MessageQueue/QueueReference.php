<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Queue Reference.
 *
 * Maintains the relationship between a queue and a channel in a single
 * object. This allows for a single point of reference for the scalar queue
 * names that are addressed several times throughout the publishing/consuming
 * process. Provides shorthand methods for the lower-level AMQP operations
 * that are used throughout this plugin and dries up the use of non-default
 * AMQP method arguments.
 */
class QueueReference
{
    /** @var QueueChannel */
    protected $channel;

    /** @var string */
    protected $queue;

    /**
     * Constructor.
     *
     * @param QueueChannel $channel
     * @param string       $queue      The name of the queue being declared/referenced
     * @param bool         $passive
     * @param bool         $durable
     * @param bool         $exclusive
     * @param bool         $autoDelete Default false is opposite queue_declare default
     */
    public function __construct(
        QueueChannel $channel,
        $queue,
        $passive = false,
        $durable = false,
        $exclusive = false,
        $autoDelete = false
    ) {
        $this->channel = $channel;
        $this->queue   = $queue;
        $this->channel->queue_declare($queue, $passive, $durable, $exclusive, $autoDelete);
    }

    /**
     * Get the name of the queue referenced by this instance.
     *
     * @return string
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * Get the channel on which this queue was declared.
     *
     * @return QueueChannel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * Publish a message to the channel using own queue name.
     *
     * @param AMQPMessage|string $message
     */
    public function publish($message)
    {
        if (!$message instanceof AMQPMessage) {
            $message = new AMQPMessage($message);
        }

        $this->channel->publish($message, $this->queue);
    }

    /**
     * Consume a queue's messages and apply callback to each.
     *
     * @param callable $callback
     */
    public function consume($callback)
    {
        $this->channel->consume($callback, $this->queue);
    }

    /**
     * Has channel callbacks.
     *
     * @return bool
     */
    public function hasChannelCallbacks()
    {
        return $this->channel->hasCallbacks();
    }

    /**
     * Wait.
     *
     * @param int   $timeout
     * @param array $allowedMethods = null
     * @param bool  $nonBlocking    = false
     *
     * @return mixed
     */
    public function wait($timeout = 0, $allowedMethods = null, $nonBlocking = false)
    {
        return $this->channel->wait($allowedMethods, $nonBlocking, $timeout);
    }
}
