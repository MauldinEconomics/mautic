<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Channel Helper.
 *
 * A wrapper around the AMQPStreamConnection class which is intended to be
 * defined as a service and then injected or retrieved as needed to provide
 * shorthanded access to AMQP functionality. The class keeps track of
 * references to declared queues and allows for channel re-use or automatic
 * opening of a new channel if necessary.
 */
class ChannelHelper
{
    /** @var array */
    protected $references = [];

    /** @var AMQPStreamConnection */
    protected $connection;

    /**
     * Constructor.
     *
     * @param AMQPStreamConnection $connection
     */
    public function __construct(AMQPStreamConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get the an instance of AMQPChannel wrapped in the QueueChannel decorator.
     *
     * @param AMPQChannel|null $original An optional pre-instantiated AMQPChannel
     *
     * @return QueueChannel
     */
    public function getChannel($original = null)
    {
        if (!$original instanceof AMQPChannel) {
            $original = $this->connection->channel();
        }

        $channel = new QueueChannel($original);

        return $channel;
    }

    /**
     * Declare queue.
     *
     * @param string            $queue
     * @param QueueChannel|null $channel
     * @param bool              $durable
     *
     * @return QueueReference
     */
    public function declareQueue($queue, $channel = null, $durable = false)
    {
        if (isset($this->references[$queue])) {
            return $this->references[$queue];
        }

        if (!$channel instanceof QueueChannel) {
            $channel = $this->getChannel($channel);
        }

        $reference = new QueueReference($channel, $queue, null, $durable);

        $this->references[$queue] = $reference;

        return $reference;
    }
}
