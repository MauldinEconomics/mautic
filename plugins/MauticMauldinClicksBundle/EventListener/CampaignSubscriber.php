<?php

/*
 * @package     Mauldin Clicks
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinClicksBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\ChannelBundle\Model\MessageQueueModel;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\MauticMauldinClicksBundle\ClickEvents;

/**
 * Class CampaignSubscriber.
 */
class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var EmailModel
     */
    protected $emailModel;

    /**
     * @var EmailModel
     */
    protected $messageQueueModel;

    /**
     * @var EventModel
     */
    protected $campaignEventModel;

    /**
     * CampaignSubscriber constructor.
     *
     * @param LeadModel         $leadModel
     * @param EmailModel        $emailModel
     * @param EventModel        $eventModel
     * @param MessageQueueModel $messageQueueModel
     */
    public function __construct(LeadModel $leadModel, EmailModel $emailModel, EventModel $eventModel, MessageQueueModel $messageQueueModel)
    {
        $this->leadModel          = $leadModel;
        $this->emailModel         = $emailModel;
        $this->campaignEventModel = $eventModel;
        $this->messageQueueModel  = $messageQueueModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD         => ['onCampaignBuild', 0],
            PageEvents::PAGE_ON_HIT                   => ['onEmailClickLink', 0],
            ClickEvents::ON_CAMPAIGN_TRIGGER_DECISION => ['onCampaignTriggerDecision', 0],
        ];
    }

    /**
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $event->addDecision(
            'email.click_link',
            [
                'label'                  => 'mauldin.click.campaign.event.click_link',
                'description'            => 'mauldin.click.campaign.event.click_link_descr',
                'formType'               => 'mauldin_campaignevent_email_click',
                'eventName'              => ClickEvents::ON_CAMPAIGN_TRIGGER_DECISION,
                'connectionRestrictions' => [
                    'source' => [
                        'action' => [
                            'email.send',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Trigger campaign event for clicking an email link.
     *
     * @param EmailOpenEvent $event
     */
    public function onEmailClickLink(PageHitEvent $event)
    {
        $hit = $event->getHit();

        if ($hit->getEmail() !== null) {
            $this->campaignEventModel->triggerEvent('email.click_link', $hit, 'email', $hit->getEmail()->getId());
        }
    }
    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerDecision(CampaignExecutionEvent $event)
    {
        $eventDetails = $event->getEventDetails();
        $config       = $event->getConfig();
        $eventParent  = $event->getEvent()['parent'];

        if ($eventDetails == null) {
            return $event->setResult(false);
        }

        //check to see if the parent event is a "send email" event and that it matches the current email opened
        if (!empty($eventParent) && $eventParent['type'] === 'email.send') {
            if ($event->getEvent()['type'] == 'email.click_link') {
                $urlMatches = [];

                // Check Email URL
                if (isset($config['url']) && $config['url']) {
                    $pageUrl     = $eventDetails->getUrl();
                    $limitToUrls = explode(',', $config['url']);

                    foreach ($limitToUrls as $url) {
                        $url              = trim($url);
                        $urlMatches[$url] = fnmatch($url, $pageUrl);
                    }
                    if (in_array(true, $urlMatches)) {
                        return $event->setResult($eventDetails->getEmail()->getId() === (int) $eventParent['properties']['email']);
                    }
                } else {
                    // when  url is not set execute for any url
                    return $event->setResult($eventDetails->getEmail()->getId() === (int) $eventParent['properties']['email']);
                }
            }
        }

        return $event->setResult(false);
    }
}
