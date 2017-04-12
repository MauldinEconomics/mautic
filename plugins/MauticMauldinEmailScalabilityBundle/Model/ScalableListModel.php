<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use Mautic\LeadBundle\Model\ListModel;
use Mautic\CoreBundle\Helper\Chart\BarChart;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\PieChart;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\ProgressBarHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\LeadBundle\Entity\OperatorListTrait;
use Mautic\LeadBundle\Event\LeadListEvent;
use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\Helper\FormFieldHelper;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class ScalableListModel extends ListModel
{
    public function rebuildListLeads(LeadList $entity, $limit = 1000, $maxLeads = false, OutputInterface $output = null)
    {
        defined('MAUTIC_REBUILDING_LEAD_LISTS') or define('MAUTIC_REBUILDING_LEAD_LISTS', 1);

        $id       = $entity->getId();
        $list     = ['id' => $id, 'filters' => $entity->getFilters()];
        $dtHelper = new DateTimeHelper();

        $batchLimiters = [
            'dateTime' => $dtHelper->toUtcString(),
        ];

        $localDateTime = $dtHelper->getLocalDateTime();

        // Get a count of leads to add
        $newLeadsCount = $this->getLeadsByList(
            $list,
            true,
            [
                'countOnly'     => true,
                'newOnly'       => true,
                'batchLimiters' => $batchLimiters,
            ]
        );

        // Ensure the same list is used each batch
        $batchLimiters['maxId'] = (int) $newLeadsCount[$id]['maxId'];

        // Number of total leads to process
        $leadCount = (int) $newLeadsCount[$id]['count'];

        if ($output) {
            $output->writeln($this->translator->trans('mautic.lead.list.rebuild.to_be_added', ['%leads%' => $leadCount, '%batch%' => $limit]));
        }

        // Handle by batches
        $start = $lastRoundPercentage = $leadsProcessed = 0;

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

                $newLeadList = $this->getLeadsByList(
                    $list,
                    true,
                    [
                        'newOnly' => true,
                        // No start set because of newOnly thus always at 0
                        'limit'         => $limit,
                        'batchLimiters' => $batchLimiters,
                    ]
                );

                if (empty($newLeadList[$id])) {
                    // Somehow ran out of leads so break out
                    break;
                }

                $processedLeads = [];

                $this->em->getConnection()->beginTransaction();
                foreach ($newLeadList[$id] as $l) {
                    $this->addLead($l, $entity, false, true, -1, $localDateTime);
                    $processedLeads[] = $l;
                    unset($l);

                    ++$leadsProcessed;
                    if ($output && $leadsProcessed < $maxCount) {
                        $progress->setProgress($leadsProcessed);
                    }

                    if ($maxLeads && $leadsProcessed >= $maxLeads) {
                        break;
                    }
                }

                $start += $limit;

                // Dispatch batch event
                if (count($processedLeads) && $this->dispatcher->hasListeners(LeadEvents::LEAD_LIST_BATCH_CHANGE)) {
                    $this->dispatcher->dispatch(
                        LeadEvents::LEAD_LIST_BATCH_CHANGE,
                        new ListChangeEvent($processedLeads, $entity, true)
                    );
                }

                $this->em->flush();
                $this->em->getConnection()->commit();
                unset($newLeadList);

                // Free some memory
                gc_collect_cycles();

                if ($maxLeads && $leadsProcessed >= $maxLeads) {
                    if ($output) {
                        $progress->finish();
                        $output->writeln('');
                    }

                    return $leadsProcessed;
                }
            }

            if ($output) {
                $progress->finish();
                $output->writeln('');
            }
        }

        // Unset max ID to prevent capping at newly added max ID
        unset($batchLimiters['maxId']);

        // Get a count of leads to be removed
        $removeLeadCount = $this->getLeadsByList(
            $list,
            true,
            [
                'countOnly'      => true,
                'nonMembersOnly' => true,
                'batchLimiters'  => $batchLimiters,
            ]
        );

        // Ensure the same list is used each batch
        $batchLimiters['maxId'] = (int) $removeLeadCount[$id]['maxId'];

        // Restart batching
        $start     = $lastRoundPercentage     = 0;
        $leadCount = $removeLeadCount[$id]['count'];

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

                $removeLeadList = $this->getLeadsByList(
                    $list,
                    true,
                    [
                        // No start because the items are deleted so always 0
                        'limit'          => $limit,
                        'nonMembersOnly' => true,
                        'batchLimiters'  => $batchLimiters,
                    ]
                );

                if (empty($removeLeadList[$id])) {
                    // Somehow ran out of leads so break out
                    break;
                }

                $processedLeads = [];
                $this->em->getConnection()->beginTransaction();
                foreach ($removeLeadList[$id] as $l) {
                    $this->removeLead($l, $entity, false, true, true);
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
                if (count($processedLeads) && $this->dispatcher->hasListeners(LeadEvents::LEAD_LIST_BATCH_CHANGE)) {
                    $this->dispatcher->dispatch(
                        LeadEvents::LEAD_LIST_BATCH_CHANGE,
                        new ListChangeEvent($processedLeads, $entity, false)
                    );
                }

                $start += $limit;
                $this->em->flush();
                $this->em->getConnection()->commit();

                unset($removeLeadList);

                // Free some memory
                gc_collect_cycles();

                if ($maxLeads && $leadsProcessed >= $maxLeads) {
                    if ($output) {
                        $progress->finish();
                        $output->writeln('');
                    }

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
