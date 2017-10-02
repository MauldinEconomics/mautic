<?php

/*
 * @package     Mauldin TreeView
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinTreeViewBundle\Model;

use Doctrine\ORM\EntityManager;

class CampaignTreeViewModel
{
    /**
     * CampaignTreeViewModel constructor.
     *
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em =$em;
    }

    /**
     * This is a modified version of getCampaignLeadCount(...),
     * which also considers contacts that have opted out of the campaign.
     *
     * @param       $campaignId
     * @param array $pendingEvents List of specific events to rule out
     *
     * @return mixed
     */
    public function getOverallCampaignLeadCount($campaignId, $pendingEvents = [])
    {
        $this->em->getConnection();
        $q = $this->em->getConnection()->createQueryBuilder();

        $q->select('count(distinct clel.lead_id) as lead_count')
            ->from(MAUTIC_TABLE_PREFIX . 'campaign_lead_event_log', 'clel')
            ->where(
                $q->expr()->eq('clel.campaign_id', (int)$campaignId)
            )
            ->setParameter('false', false, 'boolean');

        if (count($pendingEvents) > 0) {
            $sq = $this->em->getConnection()->createQueryBuilder();

            $sq->select('null')
                ->from(MAUTIC_TABLE_PREFIX . 'campaign_lead_event_log', 'e')
                ->where(
                    $sq->expr()->andX(
                        $sq->expr()->eq('clel.lead_id', 'e.lead_id'),
                        $sq->expr()->in('e.event_id', $pendingEvents)
                    )
                );

            $q->andWhere(
                sprintf('NOT EXISTS (%s)', $sq->getSQL())
            );
        }

        $results = $q->execute()->fetchAll();

        return $results[0]['lead_count'];
    }

    /**
     * Gets the "naive" count of contacts for each event.
     *
     * @param      $campaignId
     * @param bool $excludeScheduled
     *
     * @return array
     */
    public function getOverallCampaignLogCounts($campaignId, $excludeScheduled = true)
    {
        $q = $this->em->getConnection()->createQueryBuilder()
            ->select('o.event_id, count(o.lead_id) as lead_count')
            ->from(MAUTIC_TABLE_PREFIX . 'campaign_lead_event_log', 'o');

        $expr = $q->expr()->andX(
            $q->expr()->eq('o.campaign_id', (int)$campaignId),
            $q->expr()->orX(
                $q->expr()->isNull('o.non_action_path_taken'),
                $q->expr()->eq('o.non_action_path_taken', ':false')
            )
        );

        if ($excludeScheduled) {
            $expr->add(
                $q->expr()->eq('o.is_scheduled', ':false')
            );
        }

        $q->where($expr)
            ->setParameter('false', false, 'boolean')
            ->groupBy('o.event_id');

        $results = $q->execute()->fetchAll();

        $return = [];

        //group by event id
        foreach ($results as $l) {
            $return[$l['event_id']] = $l['lead_count'];
        }

        return $return;
    }

    /**
     * Return the number of leads that have been on the given campaign events.
     *
     * @param array(int) $eventIds
     * @param bool $excludeScheduled
     *
     * @return int
     */
    public function getChildrenOverallCampaignLogCounts($eventIds, $excludeScheduled = true)
    {
        if (empty($eventIds)) {
            return 0;
        }

        $q = $this->em->getConnection()->createQueryBuilder()
            ->select('o.event_id, count(distinct(o.lead_id)) as lead_count')
            ->from(MAUTIC_TABLE_PREFIX . 'campaign_lead_event_log', 'o');

        $expr = $q->expr()->andX(
            $q->expr()->in('o.event_id', $eventIds),
            $q->expr()->orX(
                $q->expr()->isNull('o.non_action_path_taken'),
                $q->expr()->eq('o.non_action_path_taken', ':false')
            )
        );

        if ($excludeScheduled) {
            $expr->add(
                $q->expr()->eq('o.is_scheduled', ':false')
            );
        }

        $q->where($expr)
            ->setParameter('false', false, 'boolean');

        $results = $q->execute()->fetch();
        return $results['lead_count'];
    }
}
