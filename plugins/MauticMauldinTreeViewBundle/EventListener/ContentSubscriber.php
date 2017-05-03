<?php

/*
 * @package     Mauldin TreeView
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinTreeViewBundle\EventListener;

use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use MauticPlugin\MauticMauldinTreeViewBundle\Model\CampaignTreeViewModel;

class ContentSubscriber extends CommonSubscriber
{
    protected $campaignEventModel;
    protected $campaignModel;

    /**
     * CampaignSubscriber constructor.
     *
     * @param EventModel $eventModel
     */
    public function __construct(CampaignModel $eventModel, CampaignTreeViewModel $extendedModel)
    {
        $this->campaignEventModel = $eventModel;
        $this->campaignModel = $extendedModel;
    }

    public static function getSubscribedEvents()
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['injectViewTabs', 0]
        ];
    }

    /**
     * @param CustomButtonEvent $event
     */
    public function injectViewTabs(CustomContentEvent $event)
    {
        if ($event->getViewName() == 'MauticCampaignBundle:Campaign:details.html.php')
        {
            switch ($event->getContext())
            {
                case 'tabs':
                    $event->addTemplate('MauticMauldinTreeViewBundle:Tree:treeview_header.html.php');
                    break;
                case 'tabs.content':
                    $newVars = $event->getVars();
                    $entity = $newVars['campaign'];
                    $overallLeadCount = $this->campaignModel->getOverallCampaignLeadCount($entity->getId());
                    $campaignOverallLogCounts = $this->campaignModel->getOverallCampaignLogCounts($entity->getId(), true);

                    $evs = $newVars['events'];
                    // This `getTreeViewInfo` function must be called after the foreach that
                    // adds logCount and percent.

                    foreach ($evs as  $k =>  $types)
                    {
                        // logCount and percent keys already existed in Mautic code. oLogCount and oPercent keys were
                        // added by BrickAbode. The reason for that is the need to show opted out users, which simply
                        // are not considered in logCount.
                        foreach ($types as $ev)
                        {
                            $ev['oLogCount'] = (isset($campaignOverallLogCounts[$ev['id']])) ? (int)$campaignOverallLogCounts[$ev['id']] : 0;
                            $ev['oPercent'] = ($overallLeadCount) ? round($ev['oLogCount'] / $overallLeadCount * 100) : 0;
                            $sortedEvents[] = $ev;
                        }
                    }

                    $treeEvents = $this->getTreeViewInfo($sortedEvents);
                    $leadCount  = $this->campaignEventModel->getRepository()->getCampaignLeadCount($entity->getId());
                    $leadStats  = ['leadCount' => $leadCount, 'overallLeadCount' => $overallLeadCount];

                    $newVars['leadStats'] = $leadStats;
                    $newVars['eventTree'] = $treeEvents;

                    $event->addTemplate('MauticMauldinTreeViewBundle:Tree:treeview_content.html.php', $newVars);
                    break;
            }
        }
    }

    protected function getTreeViewInfo($events)
    {
        /*
        * getTree returns a map with the structure parent/child like the following.
        * [
            [
                "event": [info],
                "children": [...]
            ],
            [
                "event": [info],
                "children": [...]
            ]
        ]
        * Info has only what is needed to pretty print it in the campaign section,
        * but more info can be added as well by using the variable $e.
        */

        foreach ($events as $e) {
            $order[]= $e['order'];
        }
        // Workaround for when the order with other int
        $minOrder = min($order);
        function getTree(&$names, $order, $parentId, $events, $minOrder)
        {
            foreach ($events as $e) {
                // Each call to the function considers a specific order (the height in the tree structure)
                $isOrder = $e['order'] == $order;
                //IMPORTANT (PHP note): Do not change || by "or". The precedence of "or" is different and
                // does not work here.
                if ($isOrder) {
                    $correctChild = ($e['parent_id'] == null) || ($e['parent_id'] == $parentId);

                    if ($correctChild) {
                        $id = $e['id'];
                        $children = [];
                        getTree($children, $order + 1, $id, $events, $minOrder);
                        // Takes portion of data needed
                        $sub_e = [
                            'name' => $e['name'],
                            'eventType' => $e['eventType'],
                            'description' => $e['description'],
                            'order' => $e['order'] - $minOrder,
                            'percent' => $e['oPercent'],
                            'logCount' => $e['oLogCount'],
                            'type' => $e['type'],
                            'decisionPath' => $e['decisionPath']
                        ];
                        $names[] = ["event" => $sub_e, "children" => $children];
                    }
                }
            }
        };
        $eventsDict = [];

        // The value -1 is arbitrary. It will just be ignored for the root events as
        // they have no parent.
        getTree($eventsDict, $minOrder, null, $events, $minOrder);

        //Step: convert dictionary to an array of events. Each with the needed info in the correct order.
        function getArrayFromDict(&$array, $eventsDict)
        {
            foreach ($eventsDict as $e) {
                $array[] = $e['event'];
                getArrayFromDict($array, $e['children']);
            }
        }

        $array = [];
        getArrayFromDict($array, $eventsDict);
        return $array;
    }
}
