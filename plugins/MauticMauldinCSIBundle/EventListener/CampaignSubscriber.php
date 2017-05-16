<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use MauticPlugin\MauticMauldinCSIBundle\CSIEvents;
use MauticPlugin\MauticMauldinCSIBundle\Model\CSIModel;

/**
 * Class CampaignSubscriber.
 */
class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var string Name used for the CampaignBuilder event
     */
    const OPT_LIST_ACTION = 'mauldin.list_option_action';

    /**
     * @var EventModel
     */
    protected $csiModel;

    /**
     * CampaignSubscriber constructor.
     *
     * @param EventModel $eventModel
     */
    public function __construct(CSIModel $csiModel)
    {
        $this->csiModel = $csiModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD     => ['onCampaignBuild', 0],
            CSIEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
        ];
    }

    /**
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $event->addAction(
            self::OPT_LIST_ACTION,
            [
                'label'       => 'mauldin.csi.campaign.event.list_optlist_action',
                'description' => 'mauldin.csi.campaign.event.list_optlist_action_description',
                'eventName'   => CSIEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                'formType'    => 'csilist_action',
            ]
        );
    }

    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerAction(CampaignExecutionEvent $event)
    {
        if (!$event->checkContext(self::OPT_LIST_ACTION)) {
            return null;
        }

        $addTo      = $event->getConfig()['addToLists'];
        $removeFrom = $event->getConfig()['removeFromLists'];

        $lead              = $event->getLead();
        $somethingHappened = false;

        if (!empty($addTo)) {
            $this->csiModel->addToList($lead, $addTo);
            $somethingHappened = true;
        }

        if (!empty($removeFrom)) {
            $this->csiModel->removeFromList($lead, $removeFrom);
            $somethingHappened = true;
        }

        return $event->setResult($somethingHappened);
    }
}
