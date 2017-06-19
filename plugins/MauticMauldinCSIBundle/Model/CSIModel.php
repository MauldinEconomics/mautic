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

class CSIModel
{
    private $channelHelper;
    private $queue = null;
    private $listModel;

    const CSI_LIST_QUEUE = 'csi_list';

    public function __construct(ListModel $listModel, ChannelHelper $channelHelper)
    {
        $this->listModel     = $listModel;
        $this->channelHelper = $channelHelper;
    }

    public function getQueue(){
        if ($this->queue === null) {
            $this->queue = $this->channelHelper->declareQueue(self::CSI_LIST_QUEUE);
        }
        return $this->queue;
    }

    public function addToList(Lead $lead, array $addTo)
    {

        foreach ($addTo as $id) {
            $message['add']['lead'] = $lead->getEmail();
            $message['add']['list'] = substr($this->listModel->getEntity($id)->getAlias(), strlen('csi-free-'));
            $msg                    = new AMQPMessage(serialize($message));
            $this->getQueue()->publish($msg);
        }
    }

    public function removeFromList(Lead $lead, array $removeFrom)
    {
        foreach ($removeFrom as $id) {
            $message['remove']['lead'] = $lead->getEmail();
            $message['remove']['list'] = substr($this->listModel->getEntity($id)->getAlias(), strlen('csi-free-'));
            $msg                       = new AMQPMessage(serialize($message));
            $this->getQueue()->publish($msg);
        }
    }
}
