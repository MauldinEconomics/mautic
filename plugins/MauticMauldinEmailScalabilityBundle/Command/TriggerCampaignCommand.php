<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Event\CampaignTriggerEvent;
use Mautic\CoreBundle\Command\ModeratedCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class TriggerCampaignCommand.
 */
class TriggerCampaignCommand extends ModeratedCommand
{
    /**
     * @var EventDispatcher
     */
    protected $dispatcher;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:campaigns:trigger:generate')
            ->setDescription('Trigger timed events for published campaigns.')
            ->addOption(
                '--campaign-id',
                '-i',
                InputOption::VALUE_OPTIONAL,
                'Trigger events for a specific campaign.  Otherwise, all campaigns will be triggered.',
                null
            )
            ->addOption('--scheduled-only', null, InputOption::VALUE_NONE, 'Trigger only scheduled events')
            ->addOption('--negative-only', null, InputOption::VALUE_NONE, 'Trigger only negative events, i.e. with a "no" decision path.')
            ->addOption('--batch-limit', '-l', InputOption::VALUE_OPTIONAL, 'Set batch size of contacts to process per round. Defaults to 100.', 100)
            ->addOption(
                '--max-events',
                '-m',
                InputOption::VALUE_OPTIONAL,
                'Set max number of events to process per campaign for this script execution. Defaults to all.',
                0
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        /** @var \Mautic\CampaignBundle\Model\EventModel $eventModel */
        $eventModel = $container->get('mautic.mauldin.model.event');

        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel = $container->get('mautic.campaign.model.campaign');

        $this->dispatcher = $container->get('event_dispatcher');
        $translator       = $container->get('translator');
        $em               = $container->get('doctrine')->getManager();
        $id               = $input->getOption('campaign-id');
        $scheduleOnly     = $input->getOption('scheduled-only');
        $negativeOnly     = $input->getOption('negative-only');
        $batch            = $input->getOption('batch-limit');
        $max              = $input->getOption('max-events');

        if (!$this->checkRunStatus($input, $output, $id)) {
            return 0;
        }

        $process = function ($campaign) use ($output, $translator, $negativeOnly, $scheduleOnly, $eventModel, $batch, $max) {
            if ($campaign->isPublished()) {
                if (!$this->dispatchTriggerEvent($campaign)) {
                    return 0;
                }

                $totalProcessed = 0;

                $output->writeln(
                    '<info>'.$translator->trans('mauldin.campaign.trigger.triggering_queue', ['%id%' => $campaign->getId()]).'</info>',
                    OutputInterface::VERBOSITY_VERBOSE
                );

                if (!$negativeOnly && !$scheduleOnly) {
                    //trigger starting action events for newly added contacts
                    $output->writeln(
                        '<comment>'.$translator->trans('mauldin.campaign.trigger.starting_queue').'</comment>',
                        OutputInterface::VERBOSITY_VERY_VERBOSE
                    );
                    $processed = $eventModel->triggerStartingEventsSelect($campaign, $totalProcessed, $batch, $max, $output);
                    $output->writeln(
                        '<comment>'.$translator->trans('mauldin.campaign.trigger.events_queued', ['%events%' => $processed]).'</comment>',
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                }

                if ((!$max || $totalProcessed < $max) && !$negativeOnly) {
                    //trigger starting action events for newly added contacts
                    $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.scheduled').'</comment>');
                    $processed = $eventModel->triggerScheduledEventsSelect($campaign, $totalProcessed, $batch, $max, $output);
                    $output->writeln(
                        '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', ['%events%' => $processed]).'</comment>'
                        ."\n"
                    );
                }
                if ((!$max || $totalProcessed < $max) && !$scheduleOnly) {
                    //trigger starting action events for newly added contacts
                    $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.negative').'</comment>');
                    $processed = $eventModel->triggerNegativeEventsSelect($campaign, $totalProcessed, $batch, $max, $output);
                    $output->writeln(
                        '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', ['%events%' => $processed]).'</comment>'
                        ."\n"
                    );
                }
            }
        };

        if ($id) {
            /** @var \Mautic\CampaignBundle\Entity\Campaign $campaign */
            $campaign = $campaignModel->getEntity($id);
            if ($campaign !== null) {
                $process($campaign);
            } else {
                $output->writeln('<error>'.$translator->trans('mauldin.campaign.trigger.not_found', ['%id%' => $id]).'</error>');
            }
        } else {
            $campaigns = $campaignModel->getEntities(
                [
                    'iterator_mode' => true,
                ]
            );
            while (($c = $campaigns->next()) !== false) {
                $c = reset($c);
                $process($c);

                $em->detach($c);
                unset($c);
            }

            unset($campaigns);
        }

        $this->completeRun();

        return 0;
    }

    /**
     * @param Campaign $campaign
     *
     * @return bool
     */
    protected function dispatchTriggerEvent(Campaign $campaign)
    {
        if ($this->dispatcher->hasListeners(CampaignEvents::CAMPAIGN_ON_TRIGGER)) {
            /** @var CampaignTriggerEvent $event */
            $event = $this->dispatcher->dispatch(
                CampaignEvents::CAMPAIGN_ON_TRIGGER,
                new CampaignTriggerEvent($campaign)
            );

            return $event->shouldTrigger();
        }

        return true;
    }
}
