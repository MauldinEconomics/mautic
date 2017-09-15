<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\EventListener;

use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use MauticPlugin\MauticMauldinCSIBundle\CSIEvents;
use MauticPlugin\MauticMauldinCSIBundle\Model\CSIListModel;

/**
 * Class FormSubscriber.
 */
class FormSubscriber extends CommonSubscriber
{
    /**
     * @var EmailModel
     */
    protected $csiListModel;

    /**
     * FormSubscriber constructor.
     *
     * @param EmailModel $emailModel
     */
    public function __construct(CSIListModel $csiListModel)
    {
        $this->csiListModel = $csiListModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::FORM_ON_BUILD     => ['onFormBuilder', 0],
            CSIEvents::ON_MODIFY_CSI_LIST => ['onChangeLists', 0],
        ];
    }

    /**
     * Add a lead generation action to available form submit actions.
     *
     * @param FormBuilderEvent $event
     */
    public function onFormBuilder(FormBuilderEvent $event)
    {

        //add to lead list
        $action = [
            'group'             => 'mautic.lead.lead.submitaction',
            'label'             => 'mauldin.lead.lead.events.changecsilist',
            'description'       => 'mauldin.lead.lead.events.changecsilist_descr',
            'formType'          => 'csilist_action',
            'eventName'         => CSIEvents::ON_MODIFY_CSI_LIST,
            'allowCampaignForm' => true,
        ];
        $event->addSubmitAction('lead.changecsilist', $action);
    }

    /**
     * @param $action
     * @param $factory
     */
    public function onChangeLists(SubmissionEvent $event)
    {
        $properties = $event->getActionConfig();

        /** @var \Mautic\LeadBundle\Model\LeadModel $leadModel */
        $lead       = $event->getLead();
        $addTo      = $properties['addToLists'];
        $removeFrom = $properties['removeFromLists'];

        if (!empty($addTo)) {
            $this->csiListModel->addToList($lead, $addTo);
        }

        if (!empty($removeFrom)) {
            $this->csiListModel->removeFromList($lead, $removeFrom);
        }
    }
}
