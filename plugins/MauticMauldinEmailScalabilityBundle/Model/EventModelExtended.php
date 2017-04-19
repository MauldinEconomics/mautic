<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 * @author      Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignDecisionEvent;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\Helper\ProgressBarHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class EventModel
 * {@inheritdoc}
 */
class EventModelExtended extends EventModel
{
    /** @var string */
    const QUEUE_PREFIX = 'trigger_start-';

    /** @var ChannelHelper */
    protected $channelHelper;

    /** @var QueueChannel */
    protected $channel;

    /**
     * Set channel helper.
     *
     * @param ChannelHelper $helper
     */
    public function setChannelHelper(ChannelHelper $helper)
    {
        $this->channelHelper = $helper;
    }

    /**
     * Get channel helper.
     *
     * @return ChannelHelper
     *
     * @throws \RuntimeException when no helper is present
     */
    public function getChannelHelper()
    {
        if (!$this->channelHelper) {
            throw new \RuntimeException('ChannelHelper missing from '.self::class);
        }

        return $this->channelHelper;
    }

    /**
     * Get channel, retrieving it from the helper if necessary.
     *
     * @return QueueChannel
     */
    public function getChannel()
    {
        if (!$this->channel) {
            $this->channel = $this->getChannelHelper()->getChannel();
        }

        return $this->channel;
    }

    /**
     * Consume the root level action(s) in campaign(s).
     *
     * @param Campaign        $campaign
     * @param                 $totalEventCount
     * @param int             $limit
     * @param bool            $max
     * @param OutputInterface $output
     * @param int|null        $leadId
     * @param bool|false      $returnCounts    If true, returns array of counters
     *
     * @return int
     */
    public function consumeStartingEvents(
        $campaign,
        &$totalEventCount,
        $limit = 100,
        $max = false,
        OutputInterface $output = null,
        $leadId = null,
        $returnCounts = false
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $campaignId = $campaign->getId();

        $this->logger->debug('CAMPAIGN: Triggering starting events');

        $repo         = $this->getRepository();
        $campaignRepo = $this->getCampaignRepository();
        $logRepo      = $this->getLeadEventLogRepository();

        // Create a channel and a queue for receiving  the events
        $queue = $this->declareCampaignQueue($campaignId);

        if ($this->dispatcher->hasListeners(CampaignEvents::ON_EVENT_DECISION_TRIGGER)) {
            // Include decisions if there are listeners
            $events = $repo->getRootLevelEvents($campaignId, true, true);

            // Filter out decisions
            $decisionChildren = [];
            foreach ($events as $event) {
                if ($event['eventType'] == 'decision') {
                    $decisionChildren[$event['id']] = $repo->getEventsByParent($event['id']);
                }
            }
        } else {
            $events = $repo->getRootLevelEvents($campaignId);
        }

        $rootEventCount = count($events);

        // Event settings
        $eventSettings = $this->campaignModel->getEvents();

        // Try to save some memory
        gc_enable();

        $this->logger->debug('CAMPAIGN: Processing the following events: '.implode(', ', array_keys($events)));

        $callback = function ($msg) use ($output, $events, $eventSettings, $campaign, $campaignId) {
            try {
                // Get list of all campaign leads; start is always zero in practice because of $pendingOnly
                $campaignLeads = explode(' ', $msg->body);

                if (!empty($campaignLeads)) {
                    $leads = $this->leadModel->getEntities(
                        [
                            'filter' => [
                                'force' => [
                                    [
                                        'column' => 'l.id',
                                        'expr'   => 'in',
                                        'value'  => $campaignLeads,
                                    ],
                                ],
                            ],
                            'orderBy'            => 'l.id',
                            'orderByDir'         => 'asc',
                            'withPrimaryCompany' => true,
                            'withChannelRules'   => true,
                        ]
                    );

                    /** @var \Mautic\LeadBundle\Entity\Lead $lead */
                    $leadDebugCounter = 1;
                    $this->em->getConnection()->beginTransaction();
                    foreach ($leads as $lead) {
                        $this->logger->debug('CAMPAIGN: Current Lead ID# '.$lead->getId().'; #'.$leadDebugCounter.' in batch #'.$batchDebugCounter);

                        // Set lead in case this is triggered by the system
                        $this->leadModel->setSystemCurrentLead($lead);

                        foreach ($events as $event) {
                            if ($event['eventType'] == 'decision') {
                                ++$evaluatedEventCount;
                                ++$totalEventCount;

                                $event['campaign'] = [
                                    'id'   => $campaign->getId(),
                                    'name' => $campaign->getName(),
                                ];

                                $decisionEvent = [
                                    $campaignId => [
                                        array_merge(
                                            $event,
                                            ['children' => $decisionChildren[$event['id']]]
                                        ),
                                    ],
                                ];
                                $decisionTriggerEvent = new CampaignDecisionEvent($lead, $event['type'], null, $decisionEvent, $eventSettings, true);
                                $this->dispatcher->dispatch(
                                    CampaignEvents::ON_EVENT_DECISION_TRIGGER,
                                    $decisionTriggerEvent
                                );
                                if ($decisionTriggerEvent->wasDecisionTriggered()) {
                                    ++$executedEventCount;
                                    ++$rootExecutedCount;

                                    $this->logger->debug(
                                        'CAMPAIGN: Decision ID# '.$event['id'].' for contact ID# '.$lead->getId()
                                        .' noted as completed by event listener thus executing children.'
                                    );

                                    // Decision has already been triggered by the lead so process the associated events
                                    $decisionLogged = false;
                                    foreach ($decisionEvent['children'] as $childEvent) {
                                        if ($this->executeEvent(
                                                $childEvent,
                                                $campaign,
                                                $lead,
                                                $eventSettings,
                                                false,
                                                null,
                                                null,
                                                false,
                                                $evaluatedEventCount,
                                                $executedEventCount,
                                                $totalEventCount
                                            )
                                            && !$decisionLogged
                                        ) {
                                            // Log the decision
                                            $log = $this->getLogEntity($decisionEvent['id'], $campaign, $lead, null, true);
                                            $log->setDateTriggered(new \DateTime());
                                            $log->setNonActionPathTaken(true);
                                            $logRepo->saveEntity($log);
                                            $this->em->detach($log);
                                            unset($log);

                                            $decisionLogged = true;
                                        }
                                    }
                                }

                                unset($decisionEvent);
                            } else {
                                if ($this->executeEvent(
                                    $event,
                                    $campaign,
                                    $lead,
                                    $eventSettings,
                                    false,
                                    null,
                                    null,
                                    false,
                                    $evaluatedEventCount,
                                    $executedEventCount,
                                    $totalEventCount
                                )
                                ) {
                                    ++$rootExecutedCount;
                                }
                            }

                            unset($event);
                        }

                        // Free some RAM
                        $this->em->detach($lead);
                        unset($lead);

                        ++$leadDebugCounter;
                    }
                }

                $this->em->flush();
                $this->em->getConnection()->commit();
                $this->em->clear('Mautic\LeadBundle\Entity\Lead');
                $this->em->clear('Mautic\UserBundle\Entity\User');

                unset($leads, $campaignLeads);

                // Free some memory
                gc_collect_cycles();

                $this->triggerConditions($campaign, $evaluatedEventCount, $executedEventCount, $totalEventCount);

                ++$batchDebugCounter;
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (\Exception $e) {
                $output->writeln('Exception while consuming message');
                $output->writeln($e->getMessage());
            }
        };

        $queue->consume($callback);

        return 1;
    }

    /**
     * Get lead IDs of a campaign.
     *
     * @param            $campaignId
     * @param int        $start
     * @param bool|false $limit
     * @param bool|false  getCampaignLeadIds
     *
     * @return array
     */
    public function getCampaignLeadIdsNext($campaignId, $lastLeadId, $start = 0, $limit = false, $pendingOnly = false)
    {
        $q = $this->em->getConnection()->createQueryBuilder();

        $q->select('cl.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                    $q->expr()->andX(
                        $q->expr()->gt('cl.lead_id', (int) $lastLeadId),
                        $q->expr()->eq('cl.manually_removed', ':false'))
                )
            )
            ->setParameter('false', false, 'boolean')
            ->orderBy('cl.lead_id', 'ASC');

        if (!empty($limit)) {
            $q->setMaxResults($limit);
        }

        if (!$pendingOnly && $start) {
            $q->setFirstResult($start);
        }

        $results = $q->execute()->fetchAll();

        $leads = [];
        foreach ($results as $r) {
            $leads[] = $r['lead_id'];
        }

        unset($results);

        return $leads;
    }

    /**
     * Trigger and queue the root level action(s) in campaign(s).
     *
     * @param Campaign        $campaign
     * @param                 $totalEventCount
     * @param int             $limit
     * @param bool            $max
     * @param OutputInterface $output
     * @param int|null        $leadId
     * @param bool|false      $returnCounts    If true, returns array of counters
     *
     * @return int
     */
    public function triggerStartingEventsSelect(
        $campaign,
        &$totalEventCount,
        $limit = 100,
        $max = false,
        OutputInterface $output = null,
        $leadId = null,
        $returnCounts = false
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $campaignId = $campaign->getId();

        $this->logger->debug('CAMPAIGN: Queuing starting events');

        $repo         = $this->getRepository();
        $campaignRepo = $this->getCampaignRepository();
        $logRepo      = $this->getLeadEventLogRepository();
        $queue        = $this->declareCampaignQueue($campaignId);

        $events         = $repo->getRootLevelEvents($campaignId);
        $rootEventCount = count($events);

        if (empty($rootEventCount)) {
            $this->logger->debug('CAMPAIGN: No events to trigger');

            return ($returnCounts) ? [
                'events'         => 0,
                'evaluated'      => 0,
                'executed'       => 0,
                'totalEvaluated' => 0,
                'totalExecuted'  => 0,
            ] : 0;
        }

        // Get a lead count; if $leadId, then use this as a check to ensure lead is part of the campaign
        $leadCount = $campaignRepo->getCampaignLeadCount($campaignId, $leadId, array_keys($events));

        // Get a total number of events that will be processed
        $totalStartingEvents = $leadCount * $rootEventCount;

        if ($output) {
            $output->writeln(
                $this->translator->trans(
                    'mautic.campaign.trigger.event_count',
                    ['%events%' => $totalStartingEvents, '%batch%' => $limit]
                )
            );
        }

        if (empty($leadCount)) {
            $this->logger->debug('CAMPAIGN: No contacts to process');

            unset($events);

            return ($returnCounts) ? [
                'events'         => 0,
                'evaluated'      => 0,
                'executed'       => 0,
                'totalEvaluated' => 0,
                'totalExecuted'  => 0,
            ] : 0;
        }

        // Try to save some memory
        gc_enable();

        $maxCount = ($max) ? $max : $totalStartingEvents;

        if ($output) {
            $progress = ProgressBarHelper::init($output, $maxCount);
            $progress->start();
        }

        $this->logger->debug('CAMPAIGN: Processing the following events: '.implode(', ', array_keys($events)));

        $batchCount = ceil($leadCount / $limit);

        $batchIdx = 0;
        // paginate
        $lastId       = null;
        $currentCount = 0;

        while ($batchIdx < $batchCount) {
            ++$batchIdx;
            $this->logger->debug('CAMPAIGN: Batch #'.$batchDebugCounter);

            //  Paginate the lead list limit  with the batch size
            if ($lastId == null) {
                $campaignLeads = ($leadId) ? [$leadId] : $campaignRepo->getCampaignLeadIds($campaignId, 0, $limit, true);
            } else {
                $this->logger->debug('LAST BATCH ID #'.$lastId);
                $campaignLeads = $this->getCampaignLeadIdsNext($campaignId, $lastId, 0, $limit, true);
            }

            $queue->publish(implode(' ', $campaignLeads));

            $lastId = array_values(array_slice($campaignLeads, -1))[0];
            $currentCount += count($campaignLeads);
            $progress->setProgress($currentCount);
        }

        if ($output) {
            $progress->finish();
            $output->writeln('');
        }

        // TODO: This counter does not work anymore because the events are now processed
        //  in other thread
        $counts = [
            'events'         => $totalStartingEvents,
            'evaluated'      => $rootEvaluatedCount,
            'executed'       => $rootExecutedCount,
            'totalEvaluated' => $evaluatedEventCount,
            'totalExecuted'  => $executedEventCount,
        ];
        $this->logger->debug('CAMPAIGN: Counts - '.var_export($counts, true));

        return ($returnCounts) ? $counts : $executedEventCount;
    }

    /**
     * Declare campaign queue and return a QueueReference.
     *
     * @param $campaignId
     *
     * @return MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueReference
     */
    protected function declareCampaignQueue($campaignId)
    {
        // Declare the channel's queue with $durable = true

        return $this->getChannelHelper()->declareQueue(
            self::QUEUE_PREFIX.$campaignId,
            $this->getChannel(),
            true
        );
    }
}
