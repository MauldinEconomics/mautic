<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Model;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;
use PhpAmqpLib\Message\AMQPMessage;

class CSISurveyModel
{
    private $channelHelper;
    private $queue = null;
    private $listModel;

    const CSI_SURVEY_QUEUE = 'csi_survey';

    public function __construct(ListModel $listModel, ChannelHelper $channelHelper)
    {
        $this->listModel     = $listModel;
        $this->channelHelper = $channelHelper;
    }

    public function getQueue() {
        if ($this->queue === null) {
            $this->queue = $this->channelHelper->declareQueue(self::CSI_SURVEY_QUEUE);
        }
        return $this->queue;
    }

    /*
     * @return string
     */
    public function encodeArray(array $value) {
        return json_encode($value);
    }

    public function sendSurveyResult(Lead $lead, array $result)
    {
        $message = [
            'email'      => $lead->getEmail(),
            'attributes' => $result,
        ];
        $this->getQueue()->publish(new AMQPMessage(serialize($message)));
    }
}
