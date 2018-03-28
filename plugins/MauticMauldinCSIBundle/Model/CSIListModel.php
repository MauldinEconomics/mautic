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

class CSIListModel
{
    private $channelHelper;
    private $queue = null;
    private $listModel;

    const COOKIES_NAMES  = ['client_tracking_id', 'session_tracking_id', 'user_tracking_id'];
    const COOKIES_PREFIX = 'exp_';

    const CSI_LIST_QUEUE = 'csi_list';

    public function __construct(ListModel $listModel, ChannelHelper $channelHelper, LeadAffiliateModel $leadAffiliateModel)
    {
        $this->listModel          = $listModel;
        $this->channelHelper      = $channelHelper;
        $this->leadAffiliateModel = $leadAffiliateModel;
    }

    public function getQueue()
    {
        if ($this->queue === null) {
            $this->queue = $this->channelHelper->declareQueue(self::CSI_LIST_QUEUE);
        }

        return $this->queue;
    }

    public function addToList(Lead $lead, array $addTo)
    {
        $leadAffiliateRepository = $leadAffiliateModel->getRepository();
        $addIfNotNull            = function (&$array, $tag) use ($lead) {
            $v = $lead->getFieldValue($tag);
            if ($v) {
                $array[$tag] = $v;
            }
        };

        foreach ($addTo as $id) {
            $message['add']['email'] = $lead->getEmail();
            $message['add']['code']  = substr($this->listModel->getEntity($id)->getAlias(), strlen('csi-free-'));

            foreach (self::COOKIES_NAMES as $name) {
                $addIfNotNull($message['add'], $name);
            }

            $message['add']['affiliate_id'] = $leadAffiliateRepository->getLeadFOF($lead);

            $this->getQueue()->publish(new AMQPMessage(serialize($message)));
        }
    }

    public function removeFromList(Lead $lead, array $removeFrom)
    {
        foreach ($removeFrom as $id) {
            $message['remove']['email'] = $lead->getEmail();
            $message['remove']['code']  = substr($this->listModel->getEntity($id)->getAlias(), strlen('csi-free-'));

            $this->getQueue()->publish(new AMQPMessage(serialize($message)));
        }
    }
}
