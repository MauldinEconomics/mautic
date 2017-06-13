<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 * @author      Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use DateTime;
use Doctrine\DBAL\Types\Type;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\ProgressBarHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Acl\Exception\Exception;

/**
 * Class EventModel
 * {@inheritdoc}
 */
class EventModelExtended extends EventModel
{
    /** @var string */
    const START_QUEUE_PREFIX     = 'trigger_start';
    const SCHEDULED_QUEUE_PREFIX = 'trigger_scheduled';
    const NEGATIVE_QUEUE_PREFIX  = 'trigger_negative';

    /** @var ChannelHelper */
    protected $channelHelper;

    /** @var QueueChannel */
    protected $channel;

    /** @var EmailModel */
    protected $emailModel;

    private $inSample = null;

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
     * Set channel helper.
     *
     * @param ChannelHelper $helper
     */
    public function setEmailModel(QueuedEmailModel $model)
    {
        $this->emailModel = $model;
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

    public static function splitActionEvents($campaignEvents)
    {
        $nonActionEvents = [];
        $actionEvents    = [];
        foreach ($campaignEvents as $id => $e) {
            if (!empty($e['decisionPath']) && !empty($e['parent_id']) && $campaignEvents[$e['parent_id']]['eventType'] != 'condition') {
                if ($e['decisionPath'] == 'no') {
                    $nonActionEvents[$e['parent_id']][$id] = $e;
                } elseif ($e['decisionPath'] == 'yes') {
                    $actionEvents[$e['parent_id']][] = $id;
                }
            }
        }

        return ['action' => $actionEvents,
            'nonAction'  => $nonActionEvents,
        ];
    }

    /**
     * Declare campaign queue and return a QueueReference.
     *
     * @param string $prefix
     * @param int    $campaignId
     *
     * @return QueueReference
     */
    protected function declareCampaignQueue($prefix, $campaignId)
    {
        // Declare the channel's queue with $durable = true

        return $this->getChannelHelper()->declareQueue(
            $prefix.'-'.$campaignId,
            $this->getChannel(),
            true
        );
    }

    /**
     * Select leads for starting events.
     *
     * @param int  $campaignId
     * @param int  $lastLeadId
     * @param bool $limit
     *
     * @return array
     */
    public function paginateLeadsStartingEvents($campaignId,  $start, $limit = false)
    {
        $q = $this->em->getConnection()->createQueryBuilder();

        $q->select('cl.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                    $q->expr()->gt('cl.lead_id', (int) $start),
                    $q->expr()->eq('cl.manually_removed', ':false')
                )
            )
            ->setParameter('false', false, 'boolean')
            ->orderBy('cl.lead_id', 'ASC');

        if (!empty($limit)) {
            $q->setMaxResults($limit);
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
     * @param $campaign
     * @param $event
     * @param $email
     * @param $fail
     */
    public function notifyABTestError($campaign, $event, $email, $fail)
    {
        $owner = $this->userModel->getEntity($campaign->getCreatedBy());
        if ($owner != null) {
            $this->notificationModel->addNotification(
                    $campaign->getName().' / '.$event['name'],
                    'error',
                    false,
                    $this->translator->trans(
                        'mautic.campaign.abtest.failed',
                        [
                            '%message%' => $fail,
                            '%email%'   => $email->getName(),
                        ]
                    ),
                    null,
                    null,
                    $owner
                );
        }
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
        $queue        = $this->declareCampaignQueue(self::START_QUEUE_PREFIX, $campaignId);

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

        // If the startup time is not set make it now so we can check it latter
        foreach ($events as $event) {
            if ($event['triggerMode'] == 'abtest') {
                $now         = new \DateTime();
                $triggerDate = $event['triggerDate'];
                $fail        = null;
                $email       = $this->emailModel->getEntity($event['properties']['email']);

                if ($triggerDate === null) {
                    /** @var Event $ev */
                    $ev = $this->getRepository()->getEntity($event['id']);
                    $ev->setTriggerDate($now);
                    $triggerDate = $now;
                    $this->getRepository()->saveEntity($ev);
                }
                $rollout = $this->addInterval(clone $triggerDate, $event['triggerInterval'], $event['triggerIntervalUnit']);
                if ($rollout < $now) {
                    $fail = $rollout->format('Y-m-d H:i:s T').' - roll out date passed but the test has not started';
                }
                if ($fail) {
                    $this->notifyABTestError($campaign, $event, $email, $fail);
                    return 0;
                }
            }
        }

        for ($batchIdx = 0; $batchIdx < $batchCount; ++$batchIdx) {
            $this->logger->debug('CAMPAIGN: Batch #'.$batchIdx);

            //  Paginate the lead list limit  with the batch size
            if ($lastId == null) {
                $campaignLeads = ($leadId) ? [$leadId] : $campaignRepo->getCampaignLeadIds($campaignId, 0, $limit, true);
            } else {
                $this->logger->debug('LAST BATCH ID #'.$lastId);
                $campaignLeads = $this->paginateLeadsStartingEvents($campaignId, $lastId, $limit);
            }

            $queue->publish(implode(' ', $campaignLeads));

            $lastId = array_values(array_slice($campaignLeads, -1))[0];
            $currentCount += count($campaignLeads);
            $totalEventCount += count($campaignLeads);
            $progress->setProgress($currentCount);
        }

        if ($output) {
            $progress->finish();
            $output->writeln('');
        }

        $counts = [
            'events' => $totalStartingEvents,
        ];
        $this->logger->debug('CAMPAIGN: Counts - '.var_export($counts, true));

        return  $counts;
    }

    /**
     * Consume the root level action(s) in campaign(s).
     *
     * @param Campaign $campaign
     * @param $totalEventCount
     * @param OutputInterface $output
     *
     * @return int
     */
    public function consumeStartingEvents(
        $campaign,
        &$totalEventCount,
        OutputInterface $output = null
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $campaignId = $campaign->getId();

        $this->logger->debug('CAMPAIGN: Triggering starting events');

        $repo         = $this->getRepository();
        $campaignRepo = $this->getCampaignRepository();
        $logRepo      = $this->getLeadEventLogRepository();

        // Create a channel and a queue for receiving  the events
        $queue = $this->declareCampaignQueue(self::START_QUEUE_PREFIX, $campaignId);

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

        $batchDebugCounter = $rootExecutedCount = $evaluatedEventCount = $executedEventCount = 0;

        $this->logger->debug('CAMPAIGN: Processing the following events: '.implode(', ', array_keys($events)));

        $callback = function ($msg) use (
            $batchDebugCounter,
            $evaluatedEventCount,
            $decisionChildren,
            $rootEventCount,
            $executedEventCount,
            $rootExecutedCount,
            &$totalEventCount,
            $output,
            $events,
            $eventSettings,
            $campaign,
            $campaignId,
            $logRepo
        ) {
            try {
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

                    foreach ($events as $event) {
                        if ($event['triggerMode'] == 'abtest') {
                            $sampleSize                  = $event['sampleSize'];
                            $sampleIndexes[$event['id']] = array_rand($leads, floor(count($campaignLeads) * $sampleSize / 100));
                        }
                    }

                    foreach ($leads as $idx => $lead) {
                        $this->logger->debug('CAMPAIGN: Current Lead ID# '.$lead->getId().'; #'.$leadDebugCounter.' in batch #'.$batchDebugCounter);

                        // Set lead in case this is triggered by the system
                        $this->leadModel->setSystemCurrentLead($lead);

                        foreach ($events as $event) {
                            $this->inSample = $sampleIndexes ?
                                (isset($sampleIndexes[$event['id']]) ?
                                    in_array($idx, $sampleIndexes[$event['id']]) :
                                    null) :
                                null;
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
                $output->writeln('Exception while consuming message starting events');
                $output->writeln($e->getMessage());
            }
        };

        $queue->consume($callback);

        return 1;
    }

    /**
     * Get a list of scheduled events.
     *
     * @param $campaignId
     * @param $start
     * @param int $limit
     *
     * @return array
     */
    public function paginateLeadsScheduledEvents($campaignId, $start, $limit = 0)
    {
        $date = new DateTime();
        $q    = $this->em->getConnection()->createQueryBuilder();
        $q->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'o');

        if ($start === null) {
            $q->where(
                $q->expr()->andX(
                    $q->expr()->eq('campaign_id', (int) $campaignId),
                    $q->expr()->eq('o.is_scheduled', ':true'),
                    $q->expr()->lte('o.trigger_date', ':now')
                )
            )
                ->setParameter('now', $date, Type::DATETIME)
                ->setParameter('true', true, 'boolean');
        } else {
            $q->where(
                $q->expr()->andX(
                    $q->expr()->eq('campaign_id', (int) $campaignId),
                    $q->expr()->eq('o.is_scheduled', ':true'),
                    $q->expr()->lte('o.trigger_date', ':now'),
                    $q->expr()->gt('o.lead_id', (int) $start)
                )
            )
                ->setParameter('now', $date, Type::DATETIME)
                ->setParameter('true', true, 'boolean');
        }

        $q->select('id,lead_id, event_id')
            ->orderBy('o.lead_id', 'ASC');

        if ($limit) {
            $q->setFirstResult(0)
                ->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();

        // Organize by lead
        $logs = [];
        foreach ($results as $e) {
            $leadId = $e['lead_id'];
            if (isset($logs[$leadId])) {
                $logs[$leadId] = [];
            }
            unset($e['lead_id']);
            $logs[$leadId][] = $e;
        }
        unset($results);

        return $logs;
    }

    /**
     * Trigger Scheduled events.
     *
     * @param Campaign $campaign
     * @param $totalEventCount
     * @param int             $limit
     * @param bool            $max
     * @param OutputInterface $output
     * @param bool|false      $returnCounts If true, returns array of counters
     *
     * @return int
     */
    public function triggerScheduledEventsSelect(
        $campaign, &$totalEventCount, $limit, $max, OutputInterface $output = null,  $returnCounts = false
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $campaignId   = $campaign->getId();
        $campaignName = $campaign->getName();

        $this->logger->debug('CAMPAIGN: Triggering scheduled events');

        $repo = $this->getRepository();

        // Get a count
        $totalScheduledCount = $repo->getScheduledEvents($campaignId, true);
        $this->logger->debug('CAMPAIGN: '.$totalScheduledCount.' events scheduled to execute.');

        if ($output) {
            $output->writeln(
                $this->translator->trans(
                    'mautic.campaign.trigger.event_count',
                    ['%events%' => $totalScheduledCount, '%batch%' => $limit]
                )
            );
        }

        if (empty($totalScheduledCount)) {
            $this->logger->debug('CAMPAIGN: No events to trigger');

            return ($returnCounts) ? [
                'events'         => 0,
                'evaluated'      => 0,
                'executed'       => 0,
                'totalEvaluated' => 0,
                'totalExecuted'  => 0,
            ] : 0;
        }

        // Create a channel and a queue for receiving  the events

        $queue = $this->declareCampaignQueue(self::SCHEDULED_QUEUE_PREFIX, $campaignId);

        $evaluatedEventCount = $executedEventCount = $scheduledEvaluatedCount = $scheduledExecutedCount = 0;
        $maxCount            = ($max) ? $max : $totalScheduledCount;

        // Try to save some memory
        gc_enable();

        if ($output) {
            $progress = ProgressBarHelper::init($output, $maxCount);
            $progress->start();
            if ($max) {
                $progress->setProgress($totalEventCount);
            }
        }
        $batchCount = ceil($totalScheduledCount / $limit);

        // paginate
        $lastLead     = null;
        $currentCount = 0;

        $events = $repo->getCampaignActionAndConditionEvents($campaignId);

        // Check if the abtest timeout has been reached
        foreach ($events as $event) {
            if ($event['triggerMode'] == 'abtest') {
                // Check if timing has passed
                $this->inSample = false;
                $rolloutReady   = $this->checkEventTiming($event, null, false);
                $this->inSample = null;
                if ($rolloutReady === true) {
                    //Pick the winner for the ab test

                    $email = $this->emailModel->getEntity($event['properties']['email']);
                    if (!empty($email->getVariants())) {
                        $winners = $this->emailModel->getWinnerVariant($email)['winners'];
                        $fail    = null;

                        if (!empty($winners)) {
                            $winnerEmail = $this->emailModel->getEntity($winners[0]);
                            $this->emailModel->convertVariant($winnerEmail);

                            if ($event['properties']['email'] !== $winners[0]) {
                                $event['properties']['email'] = $winners[0];
                                /** @var Event $eventEntity */
                                $eventEntity = $repo->getEntity($event['id']);
                                $eventEntity->setProperties($event['properties']);
                                $repo->saveEntity($eventEntity);
                            }
                        } else {
                            $fail = 'no data available for picking the winner';
                        }
                    } else {
                        $fail = 'no variants were found';
                    }
                    if ($fail) {
                        $this->notifyABTestError($campaign, $event, $email, $fail);
                        return 0;
                    }
                }
            }
        }

        for ($batchIdx = 0; $batchIdx < $batchCount; ++$batchIdx) {
            $this->logger->debug('CAMPAIGN: Batch #'.$batchIdx);

            $events = $this->paginateLeadsScheduledEvents($campaignId, $lastLead,  $limit);

            $msg = new AMQPMessage(serialize($events));
            $queue->publish($msg);

            $currentCount += count($events);
            $progress->setProgress($currentCount);
            end($events);
            $lastLead = key($events);
        }

        if ($output) {
            $progress->finish();
            $output->writeln('');
        }

        $counts = [
            'events'         => $totalScheduledCount,
            'evaluated'      => $scheduledEvaluatedCount,
            'executed'       => $scheduledExecutedCount,
            'totalEvaluated' => $evaluatedEventCount,
            'totalExecuted'  => $executedEventCount,
        ];
        $this->logger->debug('CAMPAIGN: Counts - '.var_export($counts, true));

        return ($returnCounts) ? $counts : $executedEventCount;
    }

    /**
     * Consume leads from scheduled queue.
     *
     * @param Campaign $campaign
     * @param $totalEventCount
     * @param OutputInterface $output
     *
     * @return int
     */
    public function consumeScheduledEvents(
        $campaign, &$totalEventCount, OutputInterface $output = null
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $campaignId   = $campaign->getId();
        $campaignName = $campaign->getName();

        $this->logger->debug('CAMPAIGN: Consume triggered scheduled events');

        $repo = $this->getRepository();

        // Get events to avoid joins
        $campaignEvents = $repo->getCampaignActionAndConditionEvents($campaignId);

        if (empty($campaignEvents)) {
            // No non-action events associated with this campaign
            unset($campaignEvents);

            return 0;
        }
        // Event settings
        $eventSettings = $this->campaignModel->getEvents();

        // Create a channel and a queue for receiving  the events

        $queue = $this->declareCampaignQueue(self::SCHEDULED_QUEUE_PREFIX, $campaignId);

        $evaluatedEventCount = $executedEventCount = $scheduledEvaluatedCount = $scheduledExecutedCount = 0;

        // Try to save some memory
        gc_enable();

        $batchDebugCounter = 0;
        $callback          = function ($msg) use (
            &$batchDebugCounter,
            &$scheduledEvaluatedCount,
            &$scheduledExecutedCount,
            &$evaluatedEventCount,
            &$executedEventCount,
            &$totalEventCount,
            $output,
            $campaignEvents,
            $eventSettings,
            $campaign,
            $campaignName,
            $campaignId
        ) {
            try {
                $events = unserialize($msg->body);

                $leads = $this->leadModel->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => array_keys($events),
                                ],
                            ],
                        ],
                        'orderBy'            => 'l.id',
                        'orderByDir'         => 'asc',
                        'withPrimaryCompany' => true,
                        'withChannelRules'   => true,
                    ]
                );

                $this->em->getConnection()->beginTransaction();

                $this->logger->debug('CAMPAIGN: Processing the following contacts '.implode(', ', array_keys($events)));
                $leadDebugCounter = 1;
                foreach ($events as $leadId => $leadEvents) {
                    if (!isset($leads[$leadId])) {
                        $this->logger->debug('CAMPAIGN: Lead ID# '.$leadId.' not found');

                        continue;
                    }

                    /** @var Lead $lead */
                    $lead = $leads[$leadId];

                    $this->logger->debug('CAMPAIGN: Current Lead ID# '.$lead->getId().'; #'.$leadDebugCounter.' in batch #'.$batchDebugCounter);

                    // Set lead in case this is triggered by the system
                    $this->leadModel->setSystemCurrentLead($lead);

                    $this->logger->debug('CAMPAIGN: Processing the following events for contact ID '.$leadId.': '.implode(', ', array_keys($leadEvents)));

                    foreach ($leadEvents as $log) {
                        ++$scheduledEvaluatedCount;

                        $event = $campaignEvents[$log['event_id']];

                        // Set campaign ID
                        $event['campaign'] = [
                            'id'   => $campaignId,
                            'name' => $campaignName,
                        ];

                        // Execute event
                        if ($this->executeEvent(
                            $event,
                            $campaign,
                            $lead,
                            $eventSettings,
                            false,
                            null,
                            true,
                            $log['id'],
                            $evaluatedEventCount,
                            $executedEventCount,
                            $totalEventCount
                        )
                        ) {
                            ++$scheduledExecutedCount;
                        }
                    }

                    ++$leadDebugCounter;
                }

                // Free RAM
                $this->em->flush();
                $this->em->getConnection()->commit();
                $this->em->clear('Mautic\LeadBundle\Entity\Lead');
                $this->em->clear('Mautic\UserBundle\Entity\User');
                unset($events, $leads);

                // Free some memory
                gc_collect_cycles();

                ++$batchDebugCounter;

                $this->triggerConditions($campaign, $evaluatedEventCount, $executedEventCount, $totalEventCount);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (Exception $e) {
                $output->writeln('Exception while consuming message scheduled events');
                $output->writeln($e);
            }
        };

        $queue->consume($callback);

        return 1;
    }

    /**
     * Paginate over leads for negative events.
     *
     * @param $campaignId
     * @param $start
     * @param bool $limit
     *
     * @return array
     */
    public function paginateLeadsNegativeEvents($campaignId, $start, $limit = false)
    {
        $q = $this->em->getConnection()->createQueryBuilder();

        if ($start == null) {
            $subExpr = $q->expr()->andX(
                $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                $q->expr()->eq('cl.manually_removed', ':false'));
        } else {
            $subExpr = $q->expr()->andX(
                $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                $q->expr()->gt('cl.lead_id', (int) $start),
                $q->expr()->eq('cl.manually_removed', ':false'));
        }

        $q->select('cl.lead_id,cl.date_added')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where($subExpr)
            ->setParameter('false', false, 'boolean')
            ->orderBy('cl.lead_id', 'ASC');

        if (!empty($limit)) {
            $q->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * Find and trigger the negative events, i.e. the events with a no decision path.
     *
     * @param Campaign        $campaign
     * @param int             $totalEventCount
     * @param int             $limit
     * @param bool            $max
     * @param OutputInterface $output
     * @param bool|false      $returnCounts    If true, returns array of counters
     *
     * @return int
     */
    public function triggerNegativeEventsSelect(
        $campaign, &$totalEventCount, $limit, $max, OutputInterface $output = null, $returnCounts = false
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $this->logger->debug('CAMPAIGN: Triggering negative events');

        $campaignId = $campaign->getId();

        $repo         = $this->getRepository();
        $campaignRepo = $this->getCampaignRepository();

        // Get events to avoid large number of joins
        $campaignEvents = $repo->getCampaignEvents($campaignId);

        // Event settings
        $eventSettings = $this->campaignModel->getEvents();

        $queue = $this->declareCampaignQueue(self::NEGATIVE_QUEUE_PREFIX, $campaignId);
        // Get an array of events that are non-action based

        $eventsSplit = self::splitActionEvents($campaignEvents);

        $this->logger->debug('CAMPAIGN: Processing the children of the following events: '.implode(', ', array_keys($eventsSplit['nonAction'])));
        if (empty($eventsSplit['nonAction'])) {
            // No non-action events associated with this campaign
            unset($campaignEvents);

            return 0;
        }

        // Get a count
        $leadCount = $campaignRepo->getCampaignLeadCount($campaignId);

        if ($output) {
            $output->writeln(
                $this->translator->trans(
                    'mautic.campaign.trigger.lead_count_analyzed',
                    ['%leads%' => $leadCount, '%batch%' => $limit]
                )
            );
        }

        $executedEventCount  = $evaluatedEventCount  = $negativeExecutedCount  = $negativeEvaluatedCount  = 0;
        $nonActionEventCount = $leadCount * count($eventsSplit['nonAction']);
        $maxCount            = ($max) ? $max : $nonActionEventCount;

        // Try to save some memory
        gc_enable();

        if ($leadCount) {
            if ($output) {
                $progress = ProgressBarHelper::init($output, $maxCount);
                $progress->start();
                if ($max) {
                    $progress->advance($totalEventCount);
                }
            }

            $batchDebugCounter = 1;
            $batchCount        = ceil($nonActionEventCount / $limit);

            // paginate
            $lastLead     = null;
            $currentCount = 0;

            for ($batchIdx = 0; $batchIdx < $batchCount; ++$batchIdx) {
                $this->logger->debug('CAMPAIGN: Batch #'.$batchDebugCounter);

                // Get batched campaign ids

                $this->logger->debug('LAST BATCH ID #'.$lastLead);

                $campaignLeads = $this->paginateLeadsNegativeEvents($campaignId, $lastLead,  $limit);

                $campaignLeadIds   = [];
                $campaignLeadDates = [];
                foreach ($campaignLeads as $r) {
                    $campaignLeadIds[]                = $r['lead_id'];
                    $campaignLeadDates[$r['lead_id']] = $r['date_added'];
                }

                unset($campaignLeads);

                $this->logger->debug('CAMPAIGN: Processing the following contacts: '.implode(', ', $campaignLeadIds));

                foreach ($eventsSplit['nonAction'] as $parentId => $events) {
                    // Just a check to ensure this is an appropriate action
                    if ($campaignEvents[$parentId]['eventType'] === 'action') {
                        $this->logger->debug('CAMPAIGN: Parent event ID #'.$parentId.' is an action.');

                        continue;
                    }

                    // Get only leads who have had the action prior to the decision executed
                    $grandParentId = $campaignEvents[$parentId]['parent_id'];

                    // Get the lead log for this batch of leads limiting to those that have already triggered
                    // the decision's parent and haven't executed this level in the path yet
                    if ($grandParentId) {
                        $this->logger->debug('CAMPAIGN: Checking for contacts based on grand parent execution.');

                        $leadLog                    = $repo->getEventLog($campaignId, $campaignLeadIds, [$grandParentId], array_keys($events), true);
                        $applicableLeads[$parentId] = array_keys($leadLog);
                    } else {
                        $this->logger->debug('CAMPAIGN: Checking for contacts based on exclusion due to being at root level');

                        // The event has no grandparent (likely because the decision is first in the campaign) so find leads that HAVE
                        // already executed the events in the root level and exclude them
                        $havingEvents = (isset($eventsSplit['action'][$parentId])) ? array_merge($eventsSplit['action'][$parentId], array_keys($events)) : array_keys(
                            $events
                        );
                        $leadLog           = $repo->getEventLog($campaignId, $campaignLeadIds, $havingEvents);
                        $unapplicableLeads = array_keys($leadLog);

                        // Only use leads that are not applicable
                        $applicableLeads[$parentId] = array_diff($campaignLeadIds, $unapplicableLeads);

                        unset($unapplicableLeads);
                    }

                    if (empty($applicableLeads[$parentId])) {
                        $this->logger->debug('CAMPAIGN: No events are applicable');

                        continue;
                    }
                    $leadEventMap     = [];
                    $leadDebugCounter = 1;
                    foreach ($applicableLeads[$parentId] as $lead) {
                        ++$negativeEvaluatedCount;

                        $this->logger->debug('CAMPAIGN: contact ID #'.$lead.'; #'.$leadDebugCounter.' in batch #'.$batchDebugCounter);

                        // Prevent path if lead has already gone down this path
                        if (!isset($leadLog[$lead]) || !array_key_exists($parentId, $leadLog[$lead])) {

                            // Get date to compare against
                            $utcDateString = ($grandParentId) ? $leadLog[$lead][$grandParentId]['date_triggered'] : $campaignLeadDates[$lead];

                            // Convert to local DateTime
                            $grandParentDate = (new DateTimeHelper($utcDateString))->getLocalDateTime();

                            // Non-decision has not taken place yet, so cycle over each associated action to see if timing is right
                            $eventTiming   = [];
                            $executeAction = false;
                            foreach ($events as $id => $e) {
                                if (isset($leadLog[$lead]) && array_key_exists($id, $leadLog[$lead])) {
                                    $this->logger->debug('CAMPAIGN: Event (ID #'.$id.') has already been executed');
                                    echo 'already executed\n';
                                    unset($e);
                                    continue;
                                }

                                if (!isset($eventSettings[$e['eventType']][$e['type']])) {
                                    echo 'no longer exists\n';
                                    $this->logger->debug('CAMPAIGN: Event (ID #'.$id.') no longer exists');
                                    unset($e);
                                    continue;
                                }

                                // First get the timing for all the 'non-decision' actions
                                $eventTiming[$id] = $this->checkEventTiming($e, $grandParentDate, true);
                                if ($eventTiming[$id] === true) {
                                    // Includes events to be executed now then schedule the rest if applicable
                                    $executeAction = true;
                                }

                                unset($e);
                            }
                            if ($executeAction) {
                                $leadEventMap[$lead] = $eventTiming;
                            }
                        }
                    }
                    if (!empty($leadEventMap)) {
                        $applicableLeads[$parentId] = $leadEventMap;
                    } else {
                        unset($applicableLeads[$parentId]);
                    }
                }
                if (!empty($leadEventMap)) {
                    $msg = new AMQPMessage(serialize($applicableLeads));
                    $queue->publish($msg);
                }

                $currentCount += count($campaignLeadIds);
                $progress->setProgress($currentCount);

                $lastLead = end($campaignLeadIds);
            }
        }

        $counts = [
            'events'         => $nonActionEventCount,
            'evaluated'      => $negativeEvaluatedCount,
            'executed'       => $negativeExecutedCount,
            'totalEvaluated' => $evaluatedEventCount,
            'totalExecuted'  => $executedEventCount,
        ];
        $this->logger->debug('CAMPAIGN: Counts - '.var_export($counts, true));

        return ($returnCounts) ? $counts : $executedEventCount;
    }

    /**
     * @param Campaign $campaign
     * @param $totalEventCount
     * @param OutputInterface $output
     *
     * @return int
     */
    public function consumeNegativeEvents(
        $campaign, &$totalEventCount, OutputInterface $output = null
    ) {
        defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED') or define('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED', 1);

        $campaignId   = $campaign->getId();
        $campaignName = $campaign->getName();

        $this->logger->debug('CAMPAIGN: Triggering negative events');
        $logRepo = $this->getLeadEventLogRepository();
        $repo    = $this->getRepository();

        // Get events to avoid joins
        $campaignEvents = $repo->getCampaignEvents($campaignId);

        // Get an array of events that are non-action based
        $eventsSplit = self::splitActionEvents($campaignEvents);
        $this->logger->debug('CAMPAIGN: Processing the children of the following events: '.implode(', ', array_keys($eventsSplit['nonAction'])));
        if (empty($eventsSplit['nonAction'])) {
            // No non-action events associated with this campaign
            unset($campaignEvents);

            return 0;
        }
        // Event settings
        $eventSettings = $this->campaignModel->getEvents();

        // Create a channel and a queue for receiving  the events

        $queue = $this->declareCampaignQueue(self::NEGATIVE_QUEUE_PREFIX, $campaignId);

        // Try to save some memory
        gc_enable();

        $batchDebugCounter = 0;
        $callback          = function ($msg) use (
            &$totalEventCount,
            &$batchDebugCounter,
            $campaignName,
            $logRepo,
            $output,
            $eventsSplit,
            $eventSettings,
            $campaign,
            $campaignId
        ) {
            try {
                ++$batchDebugCounter;
                $applicableLeadsList = unserialize($msg->body);
                $this->em->getConnection()->beginTransaction();
                foreach ($applicableLeadsList as $parentId => $applicableLeads) {
                    $events = $eventsSplit['nonAction'][$parentId];
                    $this->logger->debug('CAMPAIGN: These contacts have not gone down the positive path: '.implode(', ', $applicableLeads));

                    // Get the leads
                    $leads = $this->leadModel->getEntities(
                        [
                            'filter' => [
                                'force' => [
                                    [
                                        'column' => 'l.id',
                                        'expr'   => 'in',
                                        'value'  => array_keys($applicableLeads),
                                    ],
                                ],
                            ],
                            'orderBy'            => 'l.id',
                            'orderByDir'         => 'asc',
                            'withPrimaryCompany' => true,
                            'withChannelRules'   => true,
                        ]
                    );

                    // Loop over the non-actions and determine if it has been processed for this lead

                    $leadDebugCounter = 1;
                    /** @var Lead $lead */
                    foreach ($leads as $lead) {

                        // Set lead for listeners
                        $this->leadModel->setSystemCurrentLead($lead);

                        $this->logger->debug('CAMPAIGN: contact ID #'.$lead->getId().'; #'.$leadDebugCounter.' in batch #'.$batchDebugCounter);

                        if ($applicableLeads[$lead->getId()] !== null) {
                            $eventTiming = $applicableLeads[$lead->getId()];
                        } else {
                            continue;
                        }

                        $decisionLogged = false;

                        // Execute or schedule events
                        $this->logger->debug(
                            'CAMPAIGN: Processing the following events for contact ID# '.$lead->getId().': '.implode(
                                ', ', array_keys($eventTiming)
                            )
                        );

                        foreach ($eventTiming as $id => $eventTriggerDate) {
                            // Set event
                            $event             = $events[$id];
                            $event['campaign'] = [
                                'id'   => $campaignId,
                                'name' => $campaignName,
                            ];

                            // Set lead in case this is triggered by the system
                            $this->leadModel->setSystemCurrentLead($lead);

                            if ($this->executeEvent(
                                $event,
                                $campaign,
                                $lead,
                                $eventSettings,
                                false,
                                null,
                                $eventTriggerDate,
                                false,
                                $evaluatedEventCount,
                                $executedEventCount,
                                $totalEventCount
                            )
                            ) {
                                if (!$decisionLogged) {
                                    // Log the decision
                                    $log = $this->getLogEntity($parentId, $campaign, $lead, null, true);
                                    $log->setDateTriggered(new DateTime());
                                    $log->setNonActionPathTaken(true);
                                    $logRepo->saveEntity($log);
                                    $this->em->detach($log);
                                    unset($log);

                                    $decisionLogged = true;
                                }
                            }
                        }

                        ++$leadDebugCounter;

                        // Save RAM
                        $this->em->detach($lead);
                        unset($lead);
                    }
                }
                // Save RAM
                $this->em->flush();
                $this->em->getConnection()->commit();
                $this->em->clear('Mautic\LeadBundle\Entity\Lead');
                $this->em->clear('Mautic\UserBundle\Entity\User');

                unset($leads);

                // Free some memory
                gc_collect_cycles();

                ++$batchDebugCounter;
                $this->triggerConditions($campaign, $evaluatedEventCount, $executedEventCount, $totalEventCount);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (Exception $e) {
                $output->writeln('Exception while consuming message of negative events');
                $output->writeln($e);
            }
        };
        $queue->consume($callback);

        return 1;
    }

    public function addInterval($date, $interval, $preunit)
    {
        $unit = strtoupper($preunit);

        switch ($unit) {
            case 'Y':
            case 'M':
            case 'D':
                $dt = "P{$interval}{$unit}";
                break;
            case 'I':
                $dt = "PT{$interval}M";
                break;
            case 'H':
            case 'S':
                $dt = "PT{$interval}{$unit}";
                break;
        }

        $dv = new \DateInterval($dt);
        $date->add($dv);

        return $date;
    }

    /**
     * Check to see if the interval between events are appropriate to fire currentEvent.
     *
     * @param           $action
     * @param \DateTime $parentTriggeredDate
     * @param bool      $allowNegative
     *
     * @return bool|DateTime
     */
    public function checkEventTiming($action, \DateTime $parentTriggeredDate = null, $allowNegative = false)
    {
        $now = new \DateTime();

        $this->logger->debug('CAMPAIGN: Check timing for '.ucfirst($action['eventType']).' ID# '.$action['id']);

        if ($action instanceof Event) {
            $action = $action->convertToArray();
        }

        if ($action['decisionPath'] == 'no' && !$allowNegative) {
            $this->logger->debug('CAMPAIGN: '.ucfirst($action['eventType']).' is attached to a negative path which is not allowed');

            return false;
        } else {
            $negate = ($action['decisionPath'] == 'no' && $allowNegative);
            if ($action['triggerMode'] == 'abtest') {
                if ($this->inSample) {
                    $triggerMode = 'date';
                } else {
                    $triggerMode = 'interval';
                }
                $this->inSample = null;
            } else {
                $triggerMode = $action['triggerMode'];
            }
            if ($triggerMode == 'interval') {
                if ($negate) {
                    $triggerOn = clone $parentTriggeredDate;
                } else {
                    $trigger   = new DateTimeHelper(isset($action['triggerDate']) ? clone $action['triggerDate'] : null);
                    $triggerOn = $trigger->getDateTime();
                    unset($trigger);
                }

                if ($triggerOn == null) {
                    $triggerOn = new \DateTime();
                }

                $this->logger->debug('CAMPAIGN: Adding interval of '.$action['triggerInterval'].$action['triggerIntervalUnit'].' to '.$triggerOn->format('Y-m-d H:i:s T'));
                $this->addInterval($triggerOn, $action['triggerInterval'], $action['triggerIntervalUnit']);
                if ($triggerOn >= $now) {
                    $this->logger->debug(
                        'CAMPAIGN: Date to execute ('.$triggerOn->format('Y-m-d H:i:s T').') is later than now ('.$now->format('Y-m-d H:i:s T')
                        .')'.(($action['decisionPath'] == 'no') ? ' so ignore' : ' so schedule')
                    );

                    // Save some RAM for batch processing
                    unset($now, $action, $dv, $dt);

                    //the event is to be scheduled based on the time interval
                    return $triggerOn;
                }
            } elseif ($triggerMode == 'date') {
                if (!$action['triggerDate'] instanceof \DateTime) {
                    $triggerDate           = new DateTimeHelper($action['triggerDate']);
                    $action['triggerDate'] = $triggerDate->getDateTime();
                    unset($triggerDate);
                }

                $this->logger->debug('CAMPAIGN: Date execution on '.$action['triggerDate']->format('Y-m-d H:i:s T'));

                $pastDue = $now >= $action['triggerDate'];

                if ($negate) {
                    $this->logger->debug(
                        'CAMPAIGN: Negative comparison; Date to execute ('.$action['triggerDate']->format('Y-m-d H:i:s T').') compared to now ('
                        .$now->format('Y-m-d H:i:s T').') and is thus '.(($pastDue) ? 'overdue' : 'not past due')
                    );

                    //it is past the scheduled trigger date and the lead has done nothing so return true to trigger
                    //the event otherwise false to do nothing
                    $return = ($pastDue) ? true : $action['triggerDate'];

                    // Save some RAM for batch processing
                    unset($now, $action);

                    return $return;
                } elseif (!$pastDue) {
                    $this->logger->debug(
                        'CAMPAIGN: Non-negative comparison; Date to execute ('.$action['triggerDate']->format('Y-m-d H:i:s T').') compared to now ('
                        .$now->format('Y-m-d H:i:s T').') and is thus not past due'
                    );

                    //schedule the event
                    return $action['triggerDate'];
                }
            }
        }

        $this->logger->debug('CAMPAIGN: Nothing stopped execution based on timing.');

        //default is to trigger the event
        return true;
    }
}
