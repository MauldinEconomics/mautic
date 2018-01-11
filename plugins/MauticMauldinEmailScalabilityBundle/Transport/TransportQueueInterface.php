<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport;

/**
 * Transport Queue Interface.
 */
interface TransportQueueInterface
{
    /**
     * Publish a message to the underlying queue implementation.
     *
     * @param mixed $message
     */
    public function publish($message);

    /**
     * Consume $callback on the underlying message queue.
     *
     * @param \Callable $callback
     */
    public function consume($callback);

    /**
     * Return the channel used.
     *
     *  @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel();
}
