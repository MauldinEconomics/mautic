<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event as Events;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\ProgressBarHelper;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\Console\Output\OutputInterface;

class ScalableCampaignModel extends CampaignModel
{
    /** {@inheritdoc} */
    public function rebuildCampaignLeads(Campaign $campaign, $limit = 1000, $maxLeads = false, OutputInterface $output = null)
    {
        defined('MAUTIC_REBUILDING_CAMPAIGNS') or define('MAUTIC_REBUILDING_CAMPAIGNS', 1);

        $repo = $this->getRepository();

        // Get a list of lead lists this campaign is associated with
        $lists = $repo->getCampaignListIds($campaign->getId());

        $batchLimiters = [
            'dateTime' => (new DateTimeHelper())->toUtcString(),
        ];

        if (count($lists)) {
            // Get a count of new leads
            $newLeadsCount = $repo->getCampaignLeadsFromLists(
                $campaign->getId(),
                $lists,
                [
                    'countOnly'     => true,
                    'batchLimiters' => $batchLimiters,
                ]
            );

            // Ensure the same list is used each batch
            $batchLimiters['maxId'] = (int) $newLeadsCount['maxId'];

            // Number of total leads to process
            $leadCount = (int) $newLeadsCount['count'];
        } else {
            // No lists to base campaign membership off of so ignore
            $leadCount = 0;
        }

        if ($output) {
            $output->writeln($this->translator->trans('mautic.campaign.rebuild.to_be_added', ['%leads%' => $leadCount, '%batch%' => $limit]));
        }

        // Handle by batches
        $start = $leadsProcessed = 0;

        // Try to save some memory
        gc_enable();

        if ($leadCount) {
            $maxCount = ($maxLeads) ? $maxLeads : $leadCount;

            if ($output) {
                $progress = ProgressBarHelper::init($output, $maxCount);
                $progress->start();
            }

            // Add leads
            while ($start < $leadCount) {
                // Keep CPU down for large lists; sleep per $limit batch
                $this->batchSleep();

                // Get a count of new leads
                $newLeadList = $repo->getCampaignLeadsFromLists(
                    $campaign->getId(),
                    $lists,
                    [
                        'limit'         => $limit,
                        'batchLimiters' => $batchLimiters,
                    ]
                );

                $start += $limit;

                $processedLeads = [];
                $this->em->getConnection()->beginTransaction();
                foreach ($newLeadList as $l) {
                    $this->addLeads($campaign, [$l], false, true, -1);
                    $processedLeads[] = $l;
                    ++$leadsProcessed;
                    if ($output && $leadsProcessed < $maxCount) {
                        $progress->setProgress($leadsProcessed);
                    }

                    unset($l);

                    if ($maxLeads && $leadsProcessed >= $maxLeads) {
                        break;
                    }
                }

                // Dispatch batch event
                if (count($processedLeads) && $this->dispatcher->hasListeners(CampaignEvents::LEAD_CAMPAIGN_BATCH_CHANGE)) {
                    $this->dispatcher->dispatch(
                        CampaignEvents::LEAD_CAMPAIGN_BATCH_CHANGE,
                        new Events\CampaignLeadChangeEvent($campaign, $processedLeads, 'added')
                    );
                }

                $this->em->flush();
                $this->em->getConnection()->commit();
                unset($newLeadList);

                // Free some memory
                gc_collect_cycles();

                if ($maxLeads && $leadsProcessed >= $maxLeads) {
                    // done for this round, bye bye
                    if ($output) {
                        $progress->finish();
                    }

                    return $leadsProcessed;
                }
            }

            if ($output) {
                $progress->finish();
                $output->writeln('');
            }
        }

        // Get a count of leads to be removed
        $removeLeadCount = $repo->getCampaignOrphanLeads(
            $campaign->getId(),
            $lists,
            [
                'countOnly'     => true,
                'batchLimiters' => $batchLimiters,
            ]
        );

        // Restart batching
        $start                  = $lastRoundPercentage                  = 0;
        $leadCount              = $removeLeadCount['count'];
        $batchLimiters['maxId'] = $removeLeadCount['maxId'];

        if ($output) {
            $output->writeln($this->translator->trans('mautic.lead.list.rebuild.to_be_removed', ['%leads%' => $leadCount, '%batch%' => $limit]));
        }

        if ($leadCount) {
            $maxCount = ($maxLeads) ? $maxLeads : $leadCount;

            if ($output) {
                $progress = ProgressBarHelper::init($output, $maxCount);
                $progress->start();
            }

            // Remove leads
            while ($start < $leadCount) {
                // Keep CPU down for large lists; sleep per $limit batch
                $this->batchSleep();

                $removeLeadList = $repo->getCampaignOrphanLeads(
                    $campaign->getId(),
                    $lists,
                    [
                        'limit'         => $limit,
                        'batchLimiters' => $batchLimiters,
                    ]
                );

                $processedLeads = [];

                $this->em->getConnection()->beginTransaction();
                foreach ($removeLeadList as $l) {
                    $this->removeLeads($campaign, [$l], false, true, true);
                    $processedLeads[] = $l;
                    ++$leadsProcessed;
                    if ($output && $leadsProcessed < $maxCount) {
                        $progress->setProgress($leadsProcessed);
                    }

                    if ($maxLeads && $leadsProcessed >= $maxLeads) {
                        break;
                    }
                }

                // Dispatch batch event
                if (count($processedLeads) && $this->dispatcher->hasListeners(CampaignEvents::LEAD_CAMPAIGN_BATCH_CHANGE)) {
                    $this->dispatcher->dispatch(
                        CampaignEvents::LEAD_CAMPAIGN_BATCH_CHANGE,
                        new Events\CampaignLeadChangeEvent($campaign, $processedLeads, 'removed')
                    );
                }

                $start += $limit;

                $this->em->flush();
                $this->em->getConnection()->commit();
                unset($removeLeadList);

                // Free some memory
                gc_collect_cycles();

                if ($maxLeads && $leadsProcessed >= $maxLeads) {
                    // done for this round, bye bye
                    $progress->finish();

                    return $leadsProcessed;
                }
            }

            if ($output) {
                $progress->finish();
                $output->writeln('');
            }
        }

        return $leadsProcessed;
    }
}
