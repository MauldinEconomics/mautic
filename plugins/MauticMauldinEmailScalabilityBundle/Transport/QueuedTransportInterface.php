<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport;

/**
 * Queued Transport Interface.
 */
interface QueuedTransportInterface
{
    /**
     * Set transport queue.
     *
     * @param TransportQueueInterface $queue
     */
    public function setTransportQueue(TransportQueueInterface $queue);

    /**
     * Get transport queue.
     *
     * @return TransportQueueInterface
     */
    public function getTransportQueue();
}
