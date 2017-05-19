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

    /*
     * This function takes a list of events and returns a list of events.
     * The difference is that the returned list is correctly ordered
     * considering the parent/child relationships.  The returned events also
     * have the information about how deep in the tree they are.
     */
    protected function getTreeViewInfo($events)
    {
        /*
         * This function builds a tree from the list of events, considering the
         * parent/child relationship between them.
         * [
         *     [
         *         "event": [info],
         *         "children": [...]
         *     ],
         *     [
         *         "event": [info],
         *         "children": [...]
         *     ]
         * ]
         * 'info' has only what is needed to pretty print it in the campaign
         * section, but more info can be added as well by using the variable $e.
         */
        function buildTree(&$names, $parentId, $events, $depth)
        {
            foreach ($events as $e) {
                if ($e['parent_id'] == $parentId) {
                    $id = $e['id'];
                    $children = [];
                    buildTree($children, $id, $events, $depth + 1);
                    // Takes portion of data needed
                    $sub_e = [
                        'name' => $e['name'],
                        'eventType' => $e['eventType'],
                        'description' => $e['description'],
                        'depth' => $depth,
                        'percent' => $e['oPercent'],
                        'logCount' => $e['oLogCount'],
                        'type' => $e['type'],
                        'decisionPath' => $e['decisionPath']
                    ];
                    $names[] = ["event" => $sub_e, "children" => $children];
                }
            }
        };

        $eventsTree = [];
        buildTree($eventsTree, null, $events, 0);

        /*
         * This function just "flattens" a tree into a list.
         */
        function buildListFromTree(&$eventsList, $eventsTree)
        {
            foreach ($eventsTree as $e) {
                $eventsList[] = $e['event'];
                buildListFromTree($eventsList, $e['children']);
            }
        }

        $eventsList = [];
        buildListFromTree($eventsList, $eventsTree);
        return $eventsList;
    }
}
