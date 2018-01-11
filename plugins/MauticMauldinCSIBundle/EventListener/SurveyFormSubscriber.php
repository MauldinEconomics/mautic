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
use MauticPlugin\MauticMauldinCSIBundle\CSIEvents;
use MauticPlugin\MauticMauldinCSIBundle\Model\CSISurveyModel;

class SurveyFormSubscriber extends CommonSubscriber
{
    protected $csiSurveyModel;

    public function __construct(CSISurveyModel $csiSurveyModel)
    {
        $this->csiSurveyModel = $csiSurveyModel;
    }

    public static function getSubscribedEvents()
    {
        return [
            FormEvents::FORM_ON_BUILD        => ['onFormBuilder', 0],
            CSIEvents::ON_SEND_SURVEY_TO_CSI => ['onSendSurveyToCSI', 0],
        ];
    }

    /**
     * Add the Form Submission event
     */
    public function onFormBuilder(FormBuilderEvent $event)
    {
        $action = [
            'group'             => 'mautic.lead.lead.submitaction',
            'label'             => 'mauldin.lead.lead.events.sendsurveytocsi',
            'description'       => 'mauldin.lead.lead.events.sendsurveytocsi.descr',
            'formType'          => 'csisurvey_action',
            'eventName'         => CSIEvents::ON_SEND_SURVEY_TO_CSI,
            'allowCampaignForm' => true,
        ];
        $event->addSubmitAction('lead.sendsurveytocsi', $action);
    }

    public function onSendSurveyToCSI(SubmissionEvent $event)
    {
        $properties = $event->getActionConfig();
        $lead       = $event->getLead();
        $values     = $event->getPost();
        $result     = array();

        foreach ($values as $key => $value) {
            if (isset($properties[$key]) && $properties[$key] === 1) {
                if (is_array($value)) {
                    $result[$key] = $this->csiSurveyModel->encodeArray($value);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        $this->csiSurveyModel->sendSurveyResult($lead, $result);
    }
}
