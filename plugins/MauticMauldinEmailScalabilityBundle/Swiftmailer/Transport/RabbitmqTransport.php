<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Swiftmailer\Transport;

use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\MemoryTransactionInterface;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\QueuedTransportInterface;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\TransportQueueInterface;

/**
 * Class RabbitmqTransport.
 */
class RabbitmqTransport extends \Swift_SmtpTransport implements QueuedTransportInterface, MemoryTransactionInterface
{
    /** @var TransportQueueInterface */
    private $queue;

    private $transaction = false;
    private $logs        = [];

    /** {@inheritdoc} */
    public function setTransportQueue(TransportQueueInterface $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Get transport queue.
     *
     * @return TransportQueueInterface
     */
    public function getTransportQueue()
    {
        if (!$this->queue) {
            throw new \RuntimeException('Transport queue missing from '.self::class);
        }

        return $this->queue;
    }

    /**
     * @param \Swift_Mime_Message $message
     * @param null                $failedRecipients
     *
     * @return int
     *
     * @throws \Swift_TransportException
     */
    public function send(\Swift_Mime_Message $message, &$failedRecipients = null)
    {
        if (!$this->queue) {
            throw new \RuntimeException('TransportQueue missing from '.self::class);
        }

        if (!$this->transaction) {
            try {
                $this->queue->publish(serialize($message));
            } catch (\Exception $e) {
                throw new \Swift_TransportException('Failed to publish message to queue', 0, $e);
            }
        } else {
            $this->logs [] = serialize($message);
        }

        return 1;
    }

    /**
     * @param \Swift_Mime_Message $message
     * @param null                $failedRecipients
     *
     * @return int
     *
     * @throws \Swift_TransportException
     */
    public function sendDirect(\Swift_Mime_Message $message, &$failedRecipients = null)
    {
        return parent::send($message, $failedRecipients);
    }

    public function begin()
    {
        if (!$this->transaction) {
            $this->transaction = true;

            return true;
        } else {
            return false;
        }
    }

    public function commit()
    {
        if ($this->transaction) {
            foreach ($this->logs as $message) {
                try {
                    $this->queue->publish($message);
                } catch (\Exception $e) {
                    throw new \Swift_TransportException('Failed to publish message to queue', 0, $e);
                }
            }
            $this->transaction = false;
            $this->logs        = [];

            return true;
        } else {
            return false;
        }
    }

    public function rollback()
    {
        if ($this->transaction) {
            $this->transaction = false;
            $this->logs        = [];

            return true;
        } else {
            return false;
        }
    }
}
