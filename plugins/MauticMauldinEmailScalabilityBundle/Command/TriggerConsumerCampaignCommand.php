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
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueProcessingCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class TriggerConsumerCampaignCommand.
 */
class TriggerConsumerCampaignCommand extends QueueProcessingCommand
{
    /**
     * @var EventDispatcher
     */
    protected $dispatcher;

    const DEFAULT_MAX_TIMEOUT = 10;
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:campaigns:trigger:consume')
            ->setDescription('Trigger timed events for published campaigns.')
            ->addOption(
                '--campaign-id',
                '-i',
                InputOption::VALUE_OPTIONAL,
                'Trigger events for a specific campaign.  Otherwise, all campaigns will be triggered.',
                null
            )
            ->addOption('--scheduled-only', null, InputOption::VALUE_NONE, 'Trigger only scheduled events')
            ->addOption('--negative-only', null, InputOption::VALUE_NONE, 'Trigger only negative events, i.e. with a "no" decision path.');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        /** @var \MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\EventModelExtended $eventModel */
        $eventModel    = $container->get('mautic.mauldin.model.event');
        $this->channel = $eventModel->getChannel();
        $output->writeln('Loaded event eventModel', OutputInterface::VERBOSITY_DEBUG);

        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel = $container->get('mautic.campaign.model.campaign');
        $output->writeln('Loaded campaign eventModel', OutputInterface::VERBOSITY_DEBUG);

        $this->dispatcher = $container->get('event_dispatcher');
        $translator       = $container->get('translator');

        $id = $input->getOption('campaign-id');

        $scheduleOnly = $input->getOption('scheduled-only');
        $negativeOnly = $input->getOption('negative-only');

        $process = function ($campaign) use ($output, $translator, $negativeOnly, $scheduleOnly, $eventModel) {
            if ($campaign->isPublished()) {
                if (!$this->dispatchTriggerEvent($campaign)) {
                    return 0;
                }

                $totalProcessed = 0;

                $output->writeln(
                    '<info>'.$translator->trans('mauldin.campaign.consume.consuming_queue', ['%id%' => $campaign->getId()]).'</info>',
                    OutputInterface::VERBOSITY_VERBOSE
                );

                if (!$negativeOnly && !$scheduleOnly) {
                    $output->writeln('<info>'.$translator->trans('mauldin.campaign.consume.consuming', ['%id%' => $campaign->getId()]).'</info>');
                    //trigger starting action events for newly added contacts
                    $output->writeln('<comment>'.$translator->trans('mauldin.campaign.consume.starting').'</comment>');
                    $eventModel->consumeStartingEvents($campaign, $totalProcessed, $output);
                }
                if (!$negativeOnly) {
                    $output->writeln('<info>'.$translator->trans('mauldin.campaign.consume.consuming', ['%id%' => $campaign->getId()]).'</info>');
                    //trigger scheduled action events for newly added contacts
                    $output->writeln('<comment>'.$translator->trans('mauldin.campaign.consume.scheduled').'</comment>');
                    $eventModel->consumeScheduledEvents($campaign, $totalProcessed, $output);
                }
                if (!$scheduleOnly) {
                    $output->writeln('<info>'.$translator->trans('mauldin.campaign.consume.consuming', ['%id%' => $campaign->getId()]).'</info>');
                    //trigger negative action events for newly added contacts
                    $output->writeln('<comment>'.$translator->trans('mauldin.campaign.consume.negative').'</comment>');
                    $eventModel->consumeNegativeEvents($campaign, $totalProcessed, $output);
                }
            }
        };

        if ($id) {
            /** @var \Mautic\CampaignBundle\Entity\Campaign $campaign */
            $campaign = $campaignModel->getEntity($id);

            if ($campaign !== null) {
                $process($campaign);
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
            }
            unset($campaigns);
        }

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
