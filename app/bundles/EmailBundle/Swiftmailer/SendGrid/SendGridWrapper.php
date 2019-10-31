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

use SendGrid\Mail;
use SendGrid\Response;

/**
 * Class SendGridWrapper
 * We need to wrap \SendGrid class because of magic methods and testing.
 */
class SendGridWrapper
{
    /**
     * @var \SendGrid
     */
    private $sendGrid;

    /** @var SendGridFactory */
    private $factory;


    public function __construct(\SendGrid $sendGrid, SendGridFactory $factory)
    {
        $this->sendGrid = $sendGrid;
        $this->factory  = $factory;
    }

    /**
     * @param Mail $mail
     *
     * @return Response
     */
    public function send(Mail $mail)
    {
        return $this->sendGrid->client->mail()->send()->post($mail);
    }
}
