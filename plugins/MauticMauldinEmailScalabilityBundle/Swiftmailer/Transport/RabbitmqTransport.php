<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Swiftmailer\Transport;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class SendgridTransport.
 */
class RabbitmqTransport extends \Swift_SmtpTransport
{
    private $connection;

    private $rabbitmqidproperty;

    private $channel;

    /**
     * {@inheritdoc}
     */
    public function __construct($connection, $host, $port, $security)
    {
        $this->connection = $connection;
        $this->channel = $this->connection->channel();
        $this->channel->queue_declare('email', false, false, false, false);

        parent::__construct($host, $port, $security);
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
        // Send a the message add leadIdHash to track this email
        $msg = new AMQPMessage(serialize($message));
        $this->channel->basic_publish($msg, '', 'email');
        return 1 ;
    }

    public function sendDirect(\Swift_Mime_Message $message, &$failedRecipients = null)
    {
        parent::send($message, $failedRecipients);
    }
}
