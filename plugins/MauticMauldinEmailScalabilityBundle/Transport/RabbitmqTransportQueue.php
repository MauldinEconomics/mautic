<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport;

use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;

/**
 * RabbitMQ Transport Queue, wraps QueueReference with overridden constructor
 * parameters and handling of publish arguments.
 */
class RabbitmqTransportQueue implements TransportQueueInterface
{
    /** @var ChannelHelper */
    private $helper;

    /** @var \MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueReference */
    private $queue = null;

    const EMAIL_QUEUE = 'email';

    /**
     * Constructor.
     *
     * @param ChannelHelper $helper
     * @param string        $queueName
     */
    public function __construct(ChannelHelper $helper)
    {
        $this->helper = $helper;
    }

    public function getQueue()
    {
        if ($this->queue === null) {
            $this->queue = $this->helper->declareQueue(self::EMAIL_QUEUE);
        }

        return $this->queue;
    }

    /**
     * Publish a message to the underlying queue implementation.
     *
     * @param mixed $message
     */
    public function publish($message)
    {
        return $this->getQueue()-> publish($message);
    }

    /**
     * Consume $callback on the underlying message queue.
     *
     * @param \Callable $callback
     */
    public function consume($callback)
    {
        // TODO: improve message retry functionality for now its is just going
        // to retry forever until the message is sent, implement retry count
        // limit, TTL expiring or dead lettered messages

        return $this->getQueue()->consume($callback);
    }

    /**
     * Has channel callbacks.
     *
     * @return bool
     */
    public function hasChannelCallbacks()
    {
        return $this->getQueue()->hasChannelCallbacks();
    }

    /**
     * Wait.
     *
     * @param int   $timeout
     * @param array $allowedMethods
     * @param bool  $nonBlocking
     *
     * @return mixed
     */
    public function wait($timeout = 0, $allowedMethods = null, $nonBlocking = false)
    {
        return $this->getQueue()->wait($timeout, $allowedMethods, $nonBlocking);
    }

    /**
     * Return the channel used.
     *
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel()
    {
        return $this->getQueue()->getChannel();
    }
}