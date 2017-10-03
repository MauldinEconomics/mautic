<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Swiftmailer\Transport;

use Exception;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\MemoryTransactionInterface;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\QueuedTransportInterface;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\TransportQueueInterface;
use ReflectionClass;
use SendGrid;
use SendGrid\Content;
use SendGrid\Email;
use SendGrid\Mail;
use SendGrid\Personalization;
use Swift_Mime_Headers_ParameterizedHeader;

/**
 * Class RabbitmqTransport.
 */
class RabbitmqTransport extends \Swift_SmtpTransport implements QueuedTransportInterface, MemoryTransactionInterface
{
    /** @var TransportQueueInterface */
    private $queue;

    private $mode = 'smtp';

    private $sandbox = false;
    private $apiKey;

    private $transaction = false;
    private $logs        = [];

    /*
     * Id of the current email being sent.
     * Used for enabling logging of the actual sending.
     * @var int
     */
    private $currentEmailId = null;

    /*
     * Set the current email id.
     *
     * @param int $id Id of the entity of the current email being sent.
     */
    public function setCurrentEmailId($id) {
        $this->currentEmailId = $id;
    }

    /** {@inheritdoc} */
    public function setTransportQueue(TransportQueueInterface $queue)
    {
        $this->queue = $queue;
    }

    /**
     * This are not used in production, since we use the Sendgrid API
     */
    public function isStarted() {
        if ($this->mode !== 'sendgrid_api') {
            return parent::isStarted();
        }
        return true;
    }

    /**
     * This are not used in production, since we use the Sendgrid API
     */
    public function start() {
        if ($this->mode !== 'sendgrid_api') {
            return parent::start();
        }
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
                $this->queue->publish(serialize(['emailId' => $this->currentEmailId, 'emailMsg' => $message]));
            } catch (\Exception $e) {
                throw new \Swift_TransportException('Failed to publish message to queue', 0, $e);
            }
        } else {
            $this->logs [] = serialize(['emailId' => $this->currentEmailId, 'emailMsg' => $message]);
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
    public function sendDirect(\Swift_Message $message, &$failedRecipients = null)
    {
        switch($this->mode) {
            case 'sendgrid_api':
                # Get email data
                $fromA = $message->getFrom();
                $emailValue = reset($fromA);

                # Workaround for calling a private method from Swift_Mailer
                $reflection_class = new ReflectionClass("Swift_Message");
                $reflection_method = $reflection_class->getMethod("_becomeMimePart");
                $reflection_method->setAccessible(true);
                $result = $reflection_method->invoke($message, NULL);

                $mail = new OpenMail(new Email($emailValue, key($fromA)), $message->getSubject());

                $personalization = new Personalization();
                // Add to
                foreach ($message->getTo() as $k => $v) {
                    $personalization->addTo(new Email($v, $k));
                }

                // Add bcc
                if ($message->getBcc()) {
                    foreach ($message->getBcc() as $k => $v) {
                        $personalization->addBcc(new Email($v, $k));
                    }
                }

                // Add cc
                if($message->getCc()) {
                    foreach ($message->getCc() as $v) {
                        $personalization->addCc(new Email(null, $v));
                    }
                }

                $mail->addPersonalization($personalization);
                $mail->setReplyTo(new SendGrid\ReplyTo($message->getReplyTo()[0]));

                /** @var \Swift_Mime_SimpleMimeEntity $v */
                foreach (array_reverse($message->getChildren()) as $v) {
                    if ($v->getBody() !== '') {
                        self::addContent($mail,$v);
                    }
                }
                self::addContent($mail, $result);

                $sg = new SendGrid($this->apiKey);

                # Setup Sendgrid connection
                $sg = new SendGrid($this->apiKey);
                $settings = new SendGrid\MailSettings();
                $settings->setSandboxMode(['enable' => $this->isSandbox()]);
                $mail->setMailSettings($settings);

                $response = $sg->client->mail()->send()->post($mail);

                if($response->statusCode() == 200 || $response->statusCode() == 202) {
                    return true;
                } else {
                    throw new Exception('Sendgrid API error code='. $response->statusCode(). ' message = '. $response->body());
                }

            case 'smtp':
                return parent::send($message, $failedRecipients);

            default:
                throw  new Exception('transport mode not supported: '. $this->mode);
        }
    }

    public static function addContent($mail,$v){
        if ($v->getNestingLevel() == \Swift_Mime_MimeEntity::LEVEL_MIXED) {
            $attach = new SendGrid\Attachment();
            $attach->setContent(base64_encode($v->getBody()));
            $attach->setType($v->getContentType());

            /** @var Swift_Mime_Headers_ParameterizedHeader $content */
            $content = $v->getHeaders()->get('content-disposition');
            $attach->setFilename($content->getParameter('filename'));
            $mail->addAttachment($attach);
        } else {
            $mail->addContent(new Content($v->getContentType(), $v->getBody()));
        }
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

    /**
     * @return null
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @param null $apiKey
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @param string $mode
     */
    public function setMode($mode)
    {
        $this->mode = $mode;
    }

    /**
     * @return bool
     */
    public function isSandbox()
    {
        return $this->sandbox;
    }

    /**
     * @param bool $sandbox
     */
    public function setSandbox($sandbox)
    {
        $this->sandbox = $sandbox === null ? false : $sandbox;
    }

}

class OpenMail extends Mail {

    // Create new constructor
    public function __construct($from, $subject)
    {
        $this->setFrom($from);
        $this->setSubject($subject);
    }
}
