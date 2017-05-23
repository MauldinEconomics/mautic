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
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em =$em;
    }

    /**
     * Get a count of leads that belong to the campaign, including the removed ones
     * Added by BrickAbode, based on getCampaignLeadCount(...)
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
     * The difference from the function getCampaignLogCounts is that this one
     * doesn't do the inner join. This way, the log counts consider even the opted outs,
     * allowing us to present it in the TreeView (RR 8 and RR 13).
     * @param      $campaignId
     * @param bool $excludeScheduled
     *
     * @return array
     */
    public function getOverallCampaignLogCounts($campaignId, $excludeScheduled = false)
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
}
