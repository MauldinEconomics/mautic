<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Swiftmailer\SendGrid;

use Mautic\EmailBundle\Swiftmailer\Exception\SendGridBadLoginException;
use Mautic\EmailBundle\Swiftmailer\Exception\SendGridBadRequestException;
use Mautic\EmailBundle\Swiftmailer\SwiftmailerFacadeInterface;
use SendGrid\Mail;
use SendGrid\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SendGridApiFacade implements SwiftmailerFacadeInterface
{
    /**
     * @var SendGridWrapper
     */
    private $sendGridWrapper;

    /**
     * @var SendGridApiMessage
     */
    private $sendGridApiMessage;

    /**
     * @var SendGridApiResponse
     */
    private $sendGridApiResponse;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    public function __construct(
        SendGridWrapper $sendGridWrapper,
        SendGridApiMessage $sendGridApiMessage,
        SendGridApiResponse $sendGridApiResponse,
        EventDispatcherInterface $dispatcher
    ) {
        $this->sendGridWrapper     = $sendGridWrapper;
        $this->sendGridApiMessage  = $sendGridApiMessage;
        $this->sendGridApiResponse = $sendGridApiResponse;
        $this->dispatcher          = $dispatcher;
    }

    /**
     * @param \Swift_Mime_Message $message
     *
     * @throws \Swift_TransportException
     */
    public function send(\Swift_Mime_Message $message)
    {
        $mail = $this->sendGridApiMessage->getMessage($message);

        $this->dispatchGetMailMessageEvent($mail, $message);

        $response = $this->sendGridWrapper->send($mail);

        try {
            $this->sendGridApiResponse->checkResponse($response);
        } catch (SendGridBadLoginException $e) {
            throw new \Swift_TransportException($e->getMessage());
        } catch (SendGridBadRequestException $e) {
            throw new \Swift_TransportException($e->getMessage());
        }

        $this->dispatchMailSendResponseEvent($response, $mail, $message);
    }

    /**
     * Dispatch GET_MAIL_MESSAGE event.
     *
     * @param Mail                $mail
     * @param \Swift_Mime_Message $message
     */
    private function dispatchGetMailMessageEvent(Mail $mail, \Swift_Mime_Message $message)
    {
        $event = new Event\GetMailMessageEvent($mail, $message);

        $this->dispatcher->dispatch(SendGridMailEvents::GET_MAIL_MESSAGE, $event);
    }

    /**
     * Dispatch MAIL_SEND_RESPONSE event.
     *
     * @param Response            $response
     * @param Mail                $mail
     * @param \Swift_Mime_Message $message
     */
    private function dispatchMailSendResponseEvent(Response $response, Mail $mail, \Swift_Mime_Message $message)
    {
        $event = new Event\MailSendResponseEvent($response, $mail, $message);

        $this->dispatcher->dispatch(SendGridMailEvents::MAIL_SEND_RESPONSE, $event);
    }
}
