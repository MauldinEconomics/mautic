<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\EventListener;

use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMauldinCSIBundle\CSIEvents;
use MauticPlugin\MauticMauldinCSIBundle\Model\CSIListModel;

/**
 * Class FormSubscriber.
 */
class FormSubscriber extends CommonSubscriber
{
    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var CSIListModel
     */
    protected $csiListModel;

    /**
     * FormSubscriber constructor.
     */
    public function __construct(LeadModel $leadModel, CSIListModel $csiListModel)
    {
        $this->leadModel    = $leadModel;
        $this->csiListModel = $csiListModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::FORM_ON_BUILD     => ['onFormBuilder', 0],
            FormEvents::FORM_ON_SUBMIT    => ['onFormSubmit', 0],
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
     * Callback: queue the action for adding or removing from a CSI list.
     * Also handles FoF.
     *
     * @param SubmissionEvent $event
     */
    public function onChangeLists(SubmissionEvent $event)
    {
        /** @var \Mautic\LeadBundle\Model\Lead $lead */
        $lead      = $event->getLead();
        $leadEmail = $lead->getEmail();

        // Short-circuit if the lead doesn't have an email address.
        if (empty($leadEmail)) {
            return;
        }

        $properties = $event->getActionConfig();
        $addTo      = $properties['addToLists'];
        $removeFrom = $properties['removeFromLists'];

        if (!empty($addTo)) {
            // Gets the FoF cookie. This is required because the other callback
            // is always executed after this one, so the new lead does not have
            // the FoF cookies set yet.
            if ($lead->isNewlyCreated()) {
                $this->setLeadFoFCookieValues($lead, $event->getRequest()->cookies);
            }

            $this->csiListModel->addToList($lead, $addTo);
        }

        if (!empty($removeFrom)) {
            $this->csiListModel->removeFromList($lead, $removeFrom);
        }
    }

    /**
     * Callback: sets the FoF lead custom fields values if the corresponding
     * cookies exist.
     *
     * This is required so that any form submission which creates a lead
     * also set its FoF cookies.
     *
     * @param SubmissionEvent $event
     */
    public function onFormSubmit(SubmissionEvent $event)
    {
        /** @var \Mautic\LeadBundle\Entity\Lead $lead */
        $lead = $event->getLead();

        if ($lead->isNewlyCreated()) {
            $this->setLeadFoFCookieValues($lead, $event->getRequest()->cookies);
        }
    }

    /**
     * Sets the FoF lead custom fields values if the corresponding cookies
     * exist. Should only be used if the Lead 'is newly created.
     *
     * @param Lead $lead
     * @param \Symfony\Component\HttpFoundation\ParameterBag $cookies
     */
    private function setLeadFoFCookieValues(Lead $lead, $cookies)
    {
        $prefix = CsiListModel::COOKIES_PREFIX;

        $values = [];
        foreach (CsiListModel::COOKIES_NAMES as $name){
            if ($cookies->has($prefix . $name)) {
                $values[$name] = $cookies->get($prefix . $name);
            }
        }

        $this->leadModel->setFieldValues($lead, $values, false, false, false);
        $this->leadModel->saveEntity($lead);
    }
}
