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
                    $vars = $event->getVars();
                    $entity = $vars['campaign'];
                    $overallLeadCount = $this->campaignModel->getOverallCampaignLeadCount($entity->getId());
                    $campaignOverallLogCounts = $this->campaignModel->getOverallCampaignLogCounts($entity->getId());

                    // Collect all campaign events
                    $allEvents = [];
                    foreach ($vars['events'] as  $evType => $evs)
                    {
                        foreach ($evs as $ev)
                        {
                            // Get "naive" number of contacts at this event
                            $ev['oLogCount'] = isset($campaignOverallLogCounts[$ev['id']]) ? (int) $campaignOverallLogCounts[$ev['id']] : 0;
                            $allEvents[] = $ev;
                        }
                    }

                    $vars['eventsInfo'] = $this->getTreeViewInfo($allEvents, $overallLeadCount);

                    $vars['leadStats'] = [
                        'leadCount' => $this->campaignEventModel->getRepository()->getCampaignLeadCount($entity->getId()),
                        'overallLeadCount' => $overallLeadCount
                    ];

                    $event->addTemplate('MauticMauldinTreeViewBundle:Tree:treeview_content.html.php', $vars);
                    break;
            }
        }
    }

    /*
     * Takes a list of Campaign Events and process it. Basically, the events
     * are ordered by "tree position" and stats are added to it so that the
     * tree can be drawn in the view.
     *
     * @param array(CampaignEvent) $events
     * @param int $oLeadCount  Total number of leads in the campaign, considering opt-outs
     *
     * @return array(eventInfo)
     */
    protected function getTreeViewInfo($events, $oLeadCount)
    {
        /*
         * This recursive function builds a tree from the list of events,
         * considering the parent/child relationship between them.
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
        function buildTree(&$names, $parentId, $events, $depth, $oLeadCount, $campaignModel)
        {
            foreach ($events as $e) {
                if ($e['parent_id'] == $parentId) {
                    $id = $e['id'];

                    $children = [];
                    buildTree($children, $id, $events, $depth + 1, $oLeadCount, $campaignModel);

                    $childrenIds = [];
                    foreach ($children as $child) {
                        $childrenIds[] = $child['event']['id'];
                        $childrenIds = array_merge($childrenIds, $child['event']['childrenIds']);
                    }

                    /*
                     * Get total number of leads that progressed to a child event.
                     */
                    $childrenLogCount = (int) $campaignModel->getChildrenOverallCampaignLogCounts($childrenIds);

                    /*
                     * Fix the "naive" contact count
                     */
                    $logCount = $e['oLogCount'];
                    if ($childrenLogCount > $logCount) {
                        $logCount = $childrenLogCount;
                    }

                    $percent = $oLeadCount ? round($logCount / $oLeadCount * 100) : 0;

                    $sub_e = [
                        'id' => $id,
                        'name' => $e['name'],
                        'eventType' => $e['eventType'],
                        'description' => $e['description'],
                        'depth' => $depth,
                        'percent' =>  $percent,
                        'logCount' => $logCount,
                        'childrenLogCount' => $childrenLogCount,
                        'type' => $e['type'],
                        'decisionPath' => $e['decisionPath'],
                        'childrenIds' => $childrenIds,
                    ];
                    $names[] = ["event" => $sub_e, "children" => $children];
                }
            }
        };

        $eventsTree = [];
        buildTree($eventsTree, null, $events, 0, $oLeadCount, $this->campaignModel);

        /*
         * This function just "flattens" the tree into a list.
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
