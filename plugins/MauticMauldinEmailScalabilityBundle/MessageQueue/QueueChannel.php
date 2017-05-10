<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Queue Channel, decorates AMQPChannel with some convenience methods.
 */
class QueueChannel
{
    /** @var AMQPChannel */
    protected $channel;

    /** @var string */
    protected $exchange = '';

    /** @var string */
    protected $consumerTag = '';

    /**
     * Constructor.
     *
     * @param AMQPChannel $channel
     */
    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
    }

    /**
     * Call undecorated methods on internal channel.
     *
     * @param $method
     * @param $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments = null)
    {
        return call_user_func_array([$this->channel, $method], (array) $arguments);
    }

    /**
     * Publish.
     *
     * @param AMQPMessage $message
     * @param string      $queue   Name of a queue in this channel
     */
    public function publish(AMQPMessage $message, $queue)
    {
        $this->channel->basic_publish($message, $this->exchange, $queue);
    }

    /**
     * Consume a queue's messages and apply callback to each.
     *
     * @param callable $callback
     * @param string   $queue    Name of a queue in this channel
     */
    public function consume($callback, $queue)
    {
        $tag = $this->consumerTag;
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume($queue, $tag, false, false, false, false, $callback);
    }

    /**
     * Has callbacks.
     *
     * @return bool
     */
    public function hasCallbacks()
    {
        return count($this->channel->callbacks) >= 1;
    }

    /**
     * Wait.
     *
     * @param int   $timeout
     * @param array $allowedMethods = null
     * @param bool  $nonBlocking    = false
     *
     * @see \PhpAmqpLib\Channel\AbstractChannel::wait
     *
     * @return mixed
     */
    public function wait($timeout = 0, $allowedMethods = null, $nonBlocking = false)
    {
        return $this->channel->wait($allowedMethods, $nonBlocking, $timeout);
    }

    /**
     * Set exchange.
     *
     * @param string $exchange
     */
    public function setExchange($exchange = '')
    {
        $this->exchange = $exchange;
    }

    /**
     * Set consumer tag.
     *
     * @param string $tag
     */
    public function setConsumerTag($tag = '')
    {
        $this->consumerTag = $tag;
    }
}
