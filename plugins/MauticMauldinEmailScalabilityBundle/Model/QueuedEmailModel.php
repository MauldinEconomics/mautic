<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright 2014 Mautic Contributors. All rights reserved
 * @author    Mautic
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\Swiftmailer\Exception\BatchQueueMaxException;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueRequestHelper;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueReference;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\MemoryTransactionInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queued Email Model
 * {@inheritdoc}
 */
class QueuedEmailModel extends EmailModel implements MemoryTransactionInterface
{
    const BROADCAST_EMAIL_QUEUE = 'broadcast-email';
    const EMAIL_HIT_QUEUE = 'email-hit';

    protected $hitQueue = null;
    protected $channelHelper;
    protected $notificationModel;
    protected $counter = [];
    protected $channel = null;
    private $transaction = null;

    /**
     * Send an email to lead(s).
     *
     * @param Email $email
     * @param       $leads
     * @param       $options = array()
     *                       array source array('model', 'id')
     *                       array emailSettings
     *                       int   listId
     *                       bool  allowResends     If false, exact emails (by id) already sent to the lead will not be resent
     *                       bool  ignoreDNC        If true, emails listed in the do not contact table will still get the email
     *                       bool  sendBatchMail    If false, the function will not send batched mail but will defer to calling function to handle it
     *                       array assetAttachments Array of optional Asset IDs to attach
     *
     * @return mixed
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function sendEmail($email, $leads, $options = [])
    {
        $listId              = (isset($options['listId'])) ? $options['listId'] : null;
        $ignoreDNC           = (isset($options['ignoreDNC'])) ? $options['ignoreDNC'] : false;
        $tokens              = (isset($options['tokens'])) ? $options['tokens'] : [];
        $sendBatchMail       = (isset($options['sendBatchMail'])) ? $options['sendBatchMail'] : true;
        $assetAttachments    = (isset($options['assetAttachments'])) ? $options['assetAttachments'] : [];
        $customHeaders       = (isset($options['customHeaders'])) ? $options['customHeaders'] : [];
        $emailType           = (isset($options['email_type'])) ? $options['email_type'] : '';
        $isMarketing         = (in_array($emailType, ['marketing']) || !empty($listId));
        $emailAttempts       = (isset($options['email_attempts'])) ? $options['email_attempts'] : 3;
        $emailPriority       = (isset($options['email_priority'])) ? $options['email_priority'] : MessageQueue::PRIORITY_NORMAL;
        $messageQueue        = (isset($options['resend_message_queue'])) ? $options['resend_message_queue'] : null;
        $returnErrorMessages = (isset($options['return_errors'])) ? $options['return_errors'] : false;
        $channel             = (isset($options['channel'])) ? $options['channel'] : null;
        if (empty($channel)) {
            $channel = (isset($options['source'])) ? $options['source'] : null;
        }

        if (!$email->getId()) {
            return false;
        }

        $this->mailHelper->getTransport()->setCurrentEmailId($email->getId());

        $singleEmail = false;
        if (isset($leads['id'])) {
            $singleEmail = $leads['id'];
            $leads       = [$leads['id'] => $leads];
        }

        /** @var \Mautic\EmailBundle\Entity\StatRepository $statRepo */
        $statRepo = $this->em->getRepository('MauticEmailBundle:Stat');
        /** @var \Mautic\EmailBundle\Entity\EmailRepository $emailRepo */
        $emailRepo = $this->getRepository();

        //get email settings such as templates, weights, etc
        $emailSettings = &$this->getEmailSettings($email);

        $sendTo  = $leads;
        $leadIds = array_keys($sendTo);
        $leadIds = array_combine($leadIds, $leadIds);

        if (!$ignoreDNC) {
            $dnc = $emailRepo->getDoNotEmailList($leadIds);

            if (!empty($dnc)) {
                foreach ($dnc as $removeMeId => $removeMeEmail) {
                    unset($sendTo[$removeMeId]);
                    unset($leadIds[$removeMeId]);
                }
            }
        }

        // Process frequency rules for email
        if ($isMarketing && count($sendTo)) {
            $campaignEventId = (is_array($channel) && 'campaign.event' === $channel[0] && !empty($channel[1])) ? $channel[1] : null;
            $this->messageQueueModel->processFrequencyRules($sendTo, 'email', $email->getId(), $campaignEventId, $emailAttempts, $emailPriority, $messageQueue);
        }

        //get a count of leads
        $count = count($sendTo);

        //no one to send to so bail or if marketing email from a campaign has been put in a queue
        if (empty($count)) {
            return $singleEmail ? true : [];
        }

        // Hydrate contacts with company profile fields
        $this->getContactCompanies($sendTo);

        foreach ($emailSettings as $eid => $details) {
            if (isset($details['send_weight'])) {
                $emailSettings[$eid]['limit'] = ceil($count * $details['send_weight']);
            } else {
                $emailSettings[$eid]['limit'] = $count;
            }
        }

        // Store stat entities
        $errors          = [];
        $saveEntities    = [];
        $deleteEntities  = [];
        $statEntities    = [];
        $statBatchCount  = 0;
        $emailSentCounts = [];

        // Setup the mailer
        $mailer = $this->mailHelper->getMailer(!$sendBatchMail);
        $mailer->enableQueue();

        // Flushes the batch in case of using API mailers
        $flushQueue = function ($reset = true) use (&$mailer, &$statEntities, &$saveEntities, &$deleteEntities, &$errors, &$emailSentCounts, $sendBatchMail) {
            if ($sendBatchMail) {
                $flushResult = $mailer->flushQueue();
                if (!$flushResult) {
                    $sendFailures = $mailer->getErrors();

                    // Check to see if failed recipients were stored by the transport
                    if (!empty($sendFailures['failures'])) {
                        // Prevent the stat from saving
                        foreach ($sendFailures['failures'] as $failedEmail) {
                            /** @var Stat $stat */
                            $stat = $statEntities[$failedEmail];
                            // Add lead ID to list of failures
                            $errors[$stat->getLead()->getId()] = $failedEmail;

                            // Down sent counts
                            $emailId = $stat->getEmail()->getId();
                            ++$emailSentCounts[$emailId];

                            if ($stat->getId()) {
                                $deleteEntities[] = $stat;
                            }
                            unset($statEntities[$failedEmail], $saveEntities[$failedEmail]);
                        }
                    }
                }

                if ($reset) {
                    $mailer->reset(true);
                }

                return $flushResult;
            }

            return true;
        };

        // Randomize the contacts for statistic purposes
        shuffle($sendTo);

        // Organize the contacts according to the variant and translation they are to receive
        $groupedContactsByEmail = [];
        $offset                 = 0;
        foreach ($emailSettings as $eid => $details) {
            if (empty($details['limit'])) {
                continue;
            }
            $groupedContactsByEmail[$eid] = [];
            if ($details['limit']) {
                // Take a chunk of contacts based on variant weights
                if ($batchContacts = array_slice($sendTo, $offset, $details['limit'])) {
                    $offset += $details['limit'];

                    // Group contacts by preferred locale
                    foreach ($batchContacts as $key => $contact) {
                        if (!empty($contact['preferred_locale'])) {
                            $locale     = $contact['preferred_locale'];
                            $localeCore = $this->getTranslationLocaleCore($locale);

                            if (isset($details['languages'][$localeCore])) {
                                if (isset($details['languages'][$localeCore][$locale])) {
                                    // Exact match
                                    $translatedId                                  = $details['languages'][$localeCore][$locale];
                                    $groupedContactsByEmail[$eid][$translatedId][] = $contact;
                                } else {
                                    // Grab the closest match
                                    $bestMatch                                     = array_keys($details['languages'][$localeCore])[0];
                                    $translatedId                                  = $details['languages'][$localeCore][$bestMatch];
                                    $groupedContactsByEmail[$eid][$translatedId][] = $contact;
                                }

                                unset($batchContacts[$key]);
                            }
                        }
                    }

                    // If there are any contacts left over, assign them to the default
                    if (count($batchContacts)) {
                        $translatedId                                = $details['languages']['default'];
                        $groupedContactsByEmail[$eid][$translatedId] = $batchContacts;
                    }
                }
            }
        }

        $badEmails     = [];
        $errorMessages = [];
        foreach ($groupedContactsByEmail as $parentId => $translatedEmails) {
            $useSettings = $emailSettings[$parentId];
            foreach ($translatedEmails as $translatedId => $contacts) {
                $emailEntity = ($translatedId === $parentId) ? $useSettings['entity'] : $useSettings['translations'][$translatedId];

                // Flush the mail queue if applicable
                $flushQueue();

                $mailer->setSource($channel);
                $emailConfigured = $mailer->setEmail($emailEntity, true, $useSettings['slots'], $assetAttachments);

                if (!empty($customHeaders)) {
                    $mailer->setCustomHeaders($customHeaders);
                }

                foreach ($contacts as $contact) {
                    if (!$emailConfigured) {
                        // There was an error configuring the email so fail these
                        $errors[$contact['id']]        = $contact['email'];
                        $errorMessages[$contact['id']] = $mailer->getErrors(false);
                        continue;
                    }

                    $idHash = uniqid();

                    // Add tracking pixel token
                    if (!empty($tokens)) {
                        $mailer->setTokens($tokens);
                    }

                    $mailer->setLead($contact);
                    $mailer->setIdHash($idHash);

                    try {
                        if (!$mailer->addTo($contact['email'], $contact['firstname'].' '.$contact['lastname'])) {
                            // Clear the errors so it doesn't stop the next send
                            $errorMessages[$contact['id']] = $mailer->getErrors();

                            // Bad email so note and continue
                            $errors[$contact['id']]    = $contact['email'];
                            $badEmails[$contact['id']] = $contact['email'];
                            continue;
                        }
                    } catch (BatchQueueMaxException $e) {
                        // Queue full so flush then try again
                        $flushQueue(false);

                        if (!$mailer->addTo($contact['email'], $contact['firstname'].' '.$contact['lastname'])) {
                            // Clear the errors so it doesn't stop the next send
                            $errorMessages[$contact['id']] = $mailer->getErrors();

                            // Bad email so note and continue
                            $errors[$contact['id']]    = $contact['email'];
                            $badEmails[$contact['id']] = $contact['email'];
                            continue;
                        }
                    }

                    //queue or send the message
                    if (!$mailer->queue(true)) {
                        $errors[$contact['id']] = $contact['email'];

                        continue;
                    }

                    //create a stat
                    $saveEntities[$contact['email']] = $statEntities[$contact['email']] = $mailer->createEmailStat(false, null, $listId);
                    ++$statBatchCount;

                    if (20 === $statBatchCount) {
                        // Save in batches of 20 to prevent email loops if the there are issuses with persisting a large number of stats at once
                        $statRepo->saveEntities($saveEntities);
                        $statBatchCount = 0;
                        $saveEntities   = [];
                    }

                    // Up sent counts
                    if (!isset($emailSentCounts[$translatedId])) {
                        $emailSentCounts[$translatedId] = 0;
                    }
                    ++$emailSentCounts[$translatedId];

                    // Update $emailSetting so campaign a/b tests are handled correctly
                    ++$emailSettings[$parentId]['sentCount'];

                    if (!empty($emailSettings[$parentId]['isVariant'])) {
                        ++$emailSettings[$parentId]['variantCount'];
                    }
                }
            }
        }

        // Send batched mail if applicable
        $flushQueue();

        // Persist left over stats
        if (count($saveEntities)) {
            $statRepo->saveEntities($saveEntities);
        }
        if (count($deleteEntities)) {
            $statRepo->deleteEntities($deleteEntities);
        }

        // Update bad emails as bounces
        if (count($badEmails)) {
            foreach ($badEmails as $contactId => $contactEmail) {
                $this->leadModel->addDncForLead(
                    $this->em->getReference('MauticLeadBundle:Lead', $contactId),
                    ['email' => $email->getId()],
                    $this->translator->trans('mautic.email.bounce.reason.bad_email'),
                    DoNotContact::BOUNCED,
                    true,
                    false
                );
            }
        }

        // Update sent counts
        foreach ($emailSentCounts as $emailId => $count) {
            // Retry a few times in case of deadlock errors
                $strikes = 3;
            while ($strikes >= 0) {
                try {
                    $this->upCount($emailId, 'sent', $count, $emailSettings[$emailId]['isVariant']);
                    break;
                } catch (\Exception $exception) {
                    error_log($exception);
                }
                --$strikes;
            }
        }

        // Free RAM
        $this->em->clear('Mautic\EmailBundle\Entity\Stat');
        $this->em->clear('Mautic\LeadBundle\Entity\DoNotContact');

        unset($saveEntities, $saveEntities, $badEmails, $emailSentCounts, $emailSettings, $options, $tokens, $useEmail, $sendTo);

        $success = empty($errors);
        if (!$success && $returnErrorMessages) {
            return $singleEmail ? $errorMessages[$singleEmail] : $errorMessages;
        }

        return $singleEmail ? $success : $errors;
    }

    // No idea why this is private
    /** {@inheritdoc} */
    private function getContactCompanies(array &$sendTo)
    {
        $fetchCompanies = [];
        foreach ($sendTo as $key => $contact) {
            if (!isset($contact['companies'])) {
                $fetchCompanies[$contact['id']] = $key;
                $sendTo[$key]['companies']      = [];
            }
        }

        if (!empty($fetchCompanies)) {
            $companies = $this->companyModel->getRepository()->getCompaniesForContacts($fetchCompanies); // Simple dbal query that fetches lead_id IN $fetchCompanies and returns as array

            foreach ($companies as $contactId => $contactCompanies) {
                $key                       = $fetchCompanies[$contactId];
                $sendTo[$key]['companies'] = $contactCompanies;
            }
        }
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
     * @param Email $emailId
     * @param null  $variantIds
     * @param null  $listIds
     * @param bool  $countOnly
     * @param null  $limit
     * @param null  $lastLead
     * @param bool  $statsOnly
     *
     * @return array|int
     */
    public function getEmailPendingLeads($email, $variantIds = null, $listIds = null, $countOnly = false, $limit = null, $lastLead = null, $statsOnly = false)
    {
        // Do not include leads in the do not contact table
        $emailId = $email->getId();
        $dncQb   = $this->em->getConnection()->createQueryBuilder();
        $dncQb->select('null')
            ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact', 'dnc')
            ->where(
                $dncQb->expr()->andX(
                    $dncQb->expr()->eq('dnc.lead_id', 'l.id'),
                    $dncQb->expr()->eq('dnc.channel', $dncQb->expr()->literal('email'))
                )
            );

        // Do not include contacts where the message is pending in the message queue
        $mqQb = $this->em->getConnection()->createQueryBuilder();
        $mqQb->select('null')
            ->from(MAUTIC_TABLE_PREFIX.'message_queue', 'mq');

        $messageExpr = $mqQb->expr()->andX(
            $mqQb->expr()->eq('mq.lead_id', 'l.id'),
            $mqQb->expr()->eq('mq.channel', $mqQb->expr()->literal('email')),
            $mqQb->expr()->neq('mq.status', $mqQb->expr()->literal(MessageQueue::STATUS_SENT))
        );

        // Do not include leads that have already been emailed
        $statQb = $this->em->getConnection()->createQueryBuilder()
            ->select('null')
            ->from(MAUTIC_TABLE_PREFIX.'email_stats', 'stat');

        $statExpr = $statQb->expr()->andX(
            $statQb->expr()->eq('stat.lead_id', 'l.id')
        );

        if ($variantIds) {
            if (!in_array($emailId, $variantIds)) {
                $variantIds[] = (int) $emailId;
            }
            $statExpr->add(
                $statQb->expr()->in('stat.email_id', $variantIds)
            );
            $messageExpr->add(
                $mqQb->expr()->in('mq.channel_id', $variantIds)
            );
        } else {
            $statExpr->add(
                $statQb->expr()->eq('stat.email_id', (int) $emailId)
            );
            $messageExpr->add(
                $mqQb->expr()->eq('mq.channel_id', (int) $emailId)
            );
        }
        $statQb->where($statExpr);
        $mqQb->where($messageExpr);

        // Only include those who belong to the associated lead lists
        if (null === $listIds) {
            // Get a list of lists associated with this email
            $lists = $this->em->getConnection()->createQueryBuilder()
                ->select('el.leadlist_id')
                ->from(MAUTIC_TABLE_PREFIX.'email_list_xref', 'el')
                ->where('el.email_id = '.(int) $emailId)
                ->execute()
                ->fetchAll();

            $listIds = [];
            foreach ($lists as $list) {
                $listIds[] = $list['leadlist_id'];
            }

            if (empty($listIds)) {
                // Prevent fatal error
                return ($countOnly) ? 0 : [];
            }
        } elseif (!is_array($listIds)) {
            $listIds = [$listIds];
        }

        // Main query
        $q = $this->em->getConnection()->createQueryBuilder();
        if ($countOnly) {
            // distinct with an inner join seems faster
            $q->select('count(distinct(l.id)) as count');
            $q->innerJoin('l', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll',
                $q->expr()->andX(
                    $q->expr()->in('ll.leadlist_id', $listIds),
                    $q->expr()->eq('ll.lead_id', 'l.id'),
                    $q->expr()->eq('ll.manually_removed', ':false')
                )
            );
        } else {
            $q->select('l.*');

            // use a derived table in order to retrieve distinct leads in case they belong to multiple
            // lead lists associated with this email
            $listQb = $this->em->getConnection()->createQueryBuilder();
            $listQb->select('distinct(ll.lead_id) lead_id')
                ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll')
                ->where(
                    $listQb->expr()->andX(
                        $listQb->expr()->in('ll.leadlist_id', $listIds),
                        $listQb->expr()->eq('ll.manually_removed', ':false')
                    )
                );
            $q->innerJoin('l', sprintf('(%s)', $listQb->getSQL()), 'in_list', 'l.id = in_list.lead_id');
        }
        $statPrefix = $statsOnly ? 'EXISTS' : 'NOT EXISTS';
        $q->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->andWhere(sprintf('NOT EXISTS (%s)', $dncQb->getSQL()))
            ->andWhere(sprintf($statPrefix.' (%s)', $statQb->getSQL()))
            ->andWhere(sprintf('NOT EXISTS (%s)', $mqQb->getSQL()))
            ->setParameter('false', false, 'boolean');
        if ($lastLead !== null) {
            $q->andWhere($q->expr()->gt('l.id', $lastLead));
        }
        // Has an email
        $q->andWhere(
            $q->expr()->andX(
                $q->expr()->isNotNull('l.email'),
                $q->expr()->neq('l.email', $q->expr()->literal(''))
            )
        );

        if (!empty($limit)) {
            $q->setFirstResult(0)
                ->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();

        if ($countOnly) {
            return (isset($results[0])) ? $results[0]['count'] : 0;
        } else {
            $leads = [];
            foreach ($results as $r) {
                $leads[$r['id']] = $r;
            }

            return $leads;
        }
    }

    public function sendEmailToLists(Email $email, $lists = null, $limit = null, $batch = false, OutputInterface $output = null, $lastLead = null)
    {
        return   $this->sendEmailToListsGenerate($email, $lists, $limit, $batch, $output, true, $lastLead);
    }

    /**
     * @param Email $email
     * @param null  $listId
     * @param bool  $countOnly
     * @param null  $limit
     * @param bool  $includeVariants
     * @param null  $lastLead
     *
     * @return array|int
     */
    public function getPendingLeads(Email $email, $listId = null, $countOnly = false, $limit = null, $includeVariants = true, $lastLead = null, $statsOnly = false)
    {
        $variantIds = ($includeVariants) ? $email->getRelatedEntityIds() : null;
        $total      = $this->getEmailPendingLeads($email, $variantIds, $listId, $countOnly, $limit, $lastLead, $statsOnly);

        return $total;
    }

    public function notifyABTestError($lists, $email, $fail)
    {
        $listNames = [];
        foreach ($lists as $list) {
            $listNames[] = $list->getName();
        }
        $owner = $this->userModel->getEntity($email->getCreatedBy());
        if ($owner != null) {
            $this->notificationModel->addNotification(
                implode(',', $listNames),
                'error',
                false,
                $this->translator->trans(
                    'mautic.email.abtest.failed',
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

    protected function declareQueue($email, $create)
    {
        $name = self::BROADCAST_EMAIL_QUEUE.'-'.$email->getId();
        // Declare the channel's queue with $durable = true
        $exist = $this->getChannelHelper()->checkQueueExist($name, true,
            false,
            true);

        // Check consistency with the desired behavior
        if (!($exist xor $create)) {
            return null;
        }

        return $this->getChannelHelper()->declareQueue(
            $name,
            $this->getChannel(),
            true,
            false,
            true
        );
    }

    private function maybeRequestEC2Helper($what, $count) {
        $cmd = "mauldin-aws-helper $what $count";
        echo($cmd . PHP_EOL);
        $result = shell_exec($cmd);
        echo($result . PHP_EOL);
    }

    /**
     * Send an email to lead lists.
     *
     * @param Email           $email
     * @param array           $lists
     * @param int             $limit
     * @param bool            $batch  True to process and batch all pending leads
     * @param OutputInterface $output
     *
     * @return array array(int $sentCount, int $failedCount, array $failedRecipientsByList)
     */
    public function sendEmailToListsGenerate(Email $email, $lists = null, $limit = null, $batch = false, OutputInterface $output = null, $noQueue = false, $lastLead = null)
    {
        //get the leads
        if (empty($lists)) {
            $lists = $email->getLists();
        }

        // Safety check
        if ('list' !== $email->getEmailType()) {
            return [0, 0, []];
        }

        $options = [
            'source'        => ['email', $email->getId()],
            'allowResends'  => false,
            'customHeaders' => [
                'Precedence' => 'Bulk',
            ],
        ];

        $isAbTest     = !empty($email->getVariantChildren());
        $notRolledOut = $email->getAutoRolloutDate() !== null ?
            $email->getAutoRolloutDate() > new \DateTime() : true;
        $hasSamples = $email->getSampleSize() !== null && $email->getSampleSize() > 0;

        $isSampling  = $isAbTest && $hasSamples && $notRolledOut;
        $isRollOut   = $isAbTest && !$notRolledOut;
        $failed      = [];
        $sentCount   = 0;
        $failedCount = 0;
        $progress    = false;

        $totalLeadCount = $this->getPendingLeads($email, null, true, null, true, null, false);

        if ($isSampling) {
            $sendAlreadyCount = $this->getPendingLeads($email, null, true, null, true, null, true);
            $totalLeadCount   = floor($email->getSampleSize() * ($sendAlreadyCount + $totalLeadCount) / 100) - $sendAlreadyCount;
        } elseif ($isRollOut) {
            $sendAlreadyCount = $this->getPendingLeads($email, null, true, null, true, null, true);
            $totalLeadCount   = $totalLeadCount - $sendAlreadyCount;
        }
        if ($batch && $output) {
            $progressCounter = 0;

            if ($totalLeadCount <= 0) {
                return;
            }

            // Broadcast send through CLI
            $output->writeln("\n<info>".$email->getName().'</info>');
            $progress = new ProgressBar($output, $totalLeadCount);
        }

        if ($isRollOut) {
            list($parent, $child) = $email->getVariants();
            $published            = array_filter($child, function ($v) {
                return $v->isPublished();
            });
            // Don't pick the winner again if
            if (!empty($published)) {
                $winners = $this->getWinnerVariant($email)['winners'];
                $fail    = null;
                if (!empty($winners)) {
                    $winnerEmail = $this->getEntity($winners[0]);
                    $this->convertVariant($winnerEmail);
                    if ($email !== $winners[0]) {
                        $email = $winnerEmail;
                    }
                } else {
                    $fail = 'no data available for picking the winner';
                }
            }
            if ($fail) {
                $this->notifyABTestError($lists, $email, $fail);

                return [0, 100, [], $lastLead];
            }
        }

        /* @var QueueReference $queue */
        if ($limit && $batch) {
            $queue = $this->declareQueue($email, true);
            if ($queue === null) {
                if ($output) {
                    $output->writeln("Can't create queue for broadcast");
                }

                return [0, 0, []];
            }
        }

        $this->maybeRequestEC2Helper('broadcast', $totalLeadCount);

        foreach ($lists as $list) {
            if (!$batch && $limit !== null && $limit <= 0) {
                // Hit the max for this batch
                break;
            }

            if ($isSampling) {
                $sampleSize = $email->getSampleSize();

                $totalLeadListCount = $this->getPendingLeads($email, $list->getId(), true, null, true, $lastLead, false);
                $sendAlreadyCount   = $this->getPendingLeads($email, $list->getId(), true, null, true, $lastLead, true);

                $sampleLeadSize = round($sampleSize * ($totalLeadListCount + $sendAlreadyCount) / 100);
                $pendingSend    = $sampleLeadSize - $sendAlreadyCount;

                $sampleRatio = $pendingSend / $totalLeadListCount;

                $sampleOutput = var_export(
                    ['list'                  => $list->getName(),
                        'email'              => $email->getName(),
                        'sampleRatio'        => $sampleRatio,
                        'sendAlreadyCount'   => $sendAlreadyCount,
                        'totalLeadListCount' => $totalLeadListCount,
                        'pendingSend'        => $pendingSend,

                    ], true);
                if ($output) {
                    $output->writeln("\n<info>".$sampleOutput.'</info>');
                }
            }

            $options['listId'] = $list->getId();
            $leads             = $this->getPendingLeads($email, $list->getId(), false, $limit, true, $lastLead);
            $leadCount         = count($leads);

            while ($leadCount) {
                $sentCount += $leadCount;

                if (!$batch && $limit != null) {
                    // Only retrieve the difference between what has already been sent and the limit
                    $limit -= $leadCount;
                }
                if ($isSampling) {
                    $sampleSize = ceil($sampleRatio * $leadCount);
                    if ($sampleSize > 0) {
                        // keep the pending size update
                        $pendingSend -= $sampleSize;
                        // Correct for possible rounding errors
                        if ($pendingSend < 0) {
                            $sampleSize += $pendingSend;
                        }
                        $randomSample = array_rand($leads, $sampleSize);
                        if (!is_array($randomSample)) {
                            $randomSample = [$randomSample];
                        }
                        $sampleLeads = array_intersect_key(
                            $leads,
                            array_flip($randomSample));
                    } else {
                        $sampleLeads = [];
                    }
                } else {
                    $sampleLeads = $leads;
                }

                if (!empty($sampleLeads)) {
                    if ($noQueue) {
                        $listErrors = $this->sendEmail($email, $sampleLeads, $options);
                        if (!empty($listErrors)) {
                            $listFailedCount = count($listErrors);

                            $sentCount -= $listFailedCount;
                            $failedCount += $listFailedCount;

                            $failed[$options['listId']] = $listErrors;
                        }
                    } else {
                        $queue->publish(
                            serialize(
                                ['email' => $email->getId(),
                                 'leads' => $sampleLeads,
                                 'list'  => $list->getId(),
                                ]));
                    }
                }
                $lastLead = end($leads)['id'];
                if ($batch) {
                    if ($progress) {
                        $progressCounter += count($sampleLeads);
                        $progress->setProgress($progressCounter);
                    }

                    // Get the next batch of leads

                    $leads     = $this->getPendingLeads($email, $list->getId(), false, $limit, true, $lastLead);
                    $leadCount = count($leads);
                } else {
                    $leadCount = 0;
                }
            }
        }

        if ($progress) {
            $progress->finish();
        }

        return [$sentCount, $failedCount, $failed, $lastLead];
    }

    /**
     * Generate a the rabbitMQ consumer for processing each batch.
     *
     * @param Email $email
     *
     * @return QueueReference
     */
    public function sendEmailToListsConsume($email, $output= null)
    {
        /** @var QueueReference $queue */
        $queue = $this->declareQueue($email, false);
        if ($queue === null) {
            if($output)
                $output->writeln('queue does not exist');
            return null;
        }

        $callback = function ($msg) {
            $input = unserialize($msg->body);
            try {
                $this->em->getConnection()->beginTransaction();
                $this->begin();
                /** @var Email $email */
                $email   = $this->getEntity($input['email']);
                $options = [
                    'source'        => ['email', $email->getId()],
                    'allowResends'  => false,
                    'customHeaders' => [
                        'Precedence' => 'Bulk',
                    ],
                ];
                $options['listId'] = $input['list'];
                $listErrors        = $this->sendEmail($email, $input['leads'], $options);
                $this->em->flush();
                $this->em->getConnection()->commit();
                $this->commit();
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (\Exception $e) {
                $this->rollback();
                error_log($e);
                // And acknowledge the message
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            }
        };
        $queue->consume($callback);

        return $queue;
    }

    /**
     * @return ChannelHelper
     */
    public function getChannelHelper()
    {
        return $this->channelHelper;
    }

    /**
     * @param mixed $channelHelper
     */
    public function setChannelHelper(ChannelHelper $channelHelper)
    {
        $this->channelHelper = $channelHelper;
    }

    /**
     * @return mixed
     */
    public function getNotificationModel()
    {
        return $this->notificationModel;
    }

    /**
     * @param mixed $notificationModel
     */
    public function setNotificationModel($notificationModel)
    {
        $this->notificationModel = $notificationModel;
    }

    /**
     * Up the read/sent counts.
     *
     * @param            $id
     * @param string     $type
     * @param int        $increaseBy
     * @param bool|false $variant
     */
    public function upCount($id, $type = 'sent', $increaseBy = 1, $variant = false)
    {
        if ($this->transaction) {
            if ($this->counter[$id] === null) {
                $this->counter[$id] = ['variant' => $variant];
            } elseif ($this->counter[$id][$type] === null) {
                $this->counter[$id][$type] = 0;
            }
            $this->counter[$id][$type] += $increaseBy;
        } else {
            $this->getRepository()->upCount($id, $type, $increaseBy, $variant);
        }
    }

    public function begin()
    {
        if (!$this->transaction) {
            $this->transaction = true;
            if ($this->mailHelper->getTransport() instanceof MemoryTransactionInterface) {
                $this->mailHelper->getTransport()->begin();
            }

            return true;
        } else {
            return false;
        }
    }

    public function commit()
    {
        if ($this->transaction) {
            $db = $this->em->getConnection();
            foreach ($this->counter as $id => $c) {
                $variant = $c['variant'];
                foreach ($c as $type => $increaseBy) {
                    $set = [$type.'_count = '.$type.'_count + :increase'];

                    if ($variant) {
                        $set[] = 'variant_'.$type.'_count = '.'variant_'.$type.'_count + :increase';
                    }
                }

                $db->executeUpdate('UPDATE '.MAUTIC_TABLE_PREFIX.'emails'.' SET  '.implode(',', array_reverse($set)).' where id = :id', ['increase' => $increaseBy, 'id' => $id]);
            }
            if ($this->mailHelper->getTransport() instanceof MemoryTransactionInterface) {
                $this->mailHelper->getTransport()->commit();
            }
            $this->counter = [];

            return true;
        } else {
            return false;
        }
    }
    public function rollback()
    {
        if ($this->transaction) {
            $this->counter = [];
            if ($this->mailHelper->getTransport() instanceof MemoryTransactionInterface) {
                $this->mailHelper->getTransport()->rollback();
            }

            return true;
        } else {
            return false;
        }
    }

    public function getEmailHitQueue()
    {
        if ($this->hitQueue === null) {
            $this->hitQueue = $this->getChannelHelper()->declareQueue(self::EMAIL_HIT_QUEUE);
        }
        return $this->hitQueue;
    }

    public function queueHitEmail($idHash, $request)
    {
        $queue = $this->getEmailHitQueue();

        $queue->publish(serialize([
            'id_hash' => $idHash,
            'request' => QueueRequestHelper::flattenRequest($request),
        ]));
    }

    public function consumeHitEmail($idHash, $request)
    {
        $this->hitEmail($idHash, $request, false, false);
    }
}
