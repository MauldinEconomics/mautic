<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright 2014 Mautic Contributors. All rights reserved
 * @author    Mautic
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\Swiftmailer\Exception\BatchQueueMaxException;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\QueuedTransportInterface;

/**
 * Queued Email Model
 * {@inheritdoc}
 */
class QueuedEmailModel extends EmailModel
{
    /**
     * Send an email to lead(s).
     *
     * @param   $email
     * @param   $leads
     * @param   $options = array()
     *                   array source array('model', 'id')
     *                   array emailSettings
     *                   int   listId
     *                   bool  allowResends     If false, exact emails (by id) already sent to the lead will not be resent
     *                   bool  ignoreDNC        If true, emails listed in the do not contact table will still get the email
     *                   bool  sendBatchMail    If false, the function will not send batched mail but will defer to calling function to handle it
     *                   array assetAttachments Array of optional Asset IDs to attach
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

        // CODE IGNORED WHEN USING THE RABBITMQ TRANSPORT
        $transport = $mailer->getTransport();

        if (!$transport instanceof QueuedTransportInterface) {
            // Update sent counts
            foreach ($emailSentCounts as $emailId => $count) {
                // Retry a few times in case of deadlock errors
                $strikes = 3;
                while ($strikes >= 0) {
                    try {
                        $this->getRepository()->upCount($emailId, 'sent', $count, $emailSettings[$emailId]['isVariant']);
                        break;
                    } catch (\Exception $exception) {
                        error_log($exception);
                    }
                    --$strikes;
                }
            }
        }
        // END CODE IGNORED

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
}
