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

/**
 * Class SendGridFactory
 * Creates SendGrid instances for individual API Keys
 */
class SendGridFactory
{

    /** @var string */
    protected $sendGridClass;

    /** @var string */
    protected $apiKey;

    /** @var array */
    protected $extraKeys = [];



    /**
     * Constructor.
     *
     * @param string $sendGridClass
     * @param string $apiKey
     * @param array  $extraKeys
     */
    public function __construct(/*string $sendGridClass,*/ /*string*/ $apiKey, array $extraKeys = [])
    {
        $this->sendGridClass = $sendGridClass;
        $this->apiKey        = $apiKey;
        $this->extraKeys     = $extraKeys;
    }


}
