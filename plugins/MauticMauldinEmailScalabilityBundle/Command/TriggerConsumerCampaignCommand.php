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
 * Class TriggerConsumerCampaignCommand.
 */
class TriggerConsumerCampaignCommand extends ModeratedCommand
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
            ->setName('mauldin:campaigns:trigger:consume')
            ->setDescription('Trigger timed events for published campaigns.')
            ->addOption(
                '--campaign-id',
                '-i',
                InputOption::VALUE_OPTIONAL,
                'Trigger events for a specific campaign.  Otherwise, all campaigns will be triggered.',
                null
            )
            ->addOption('--batch-limit', '-l', InputOption::VALUE_OPTIONAL, 'Set batch size of contacts to process per round. Defaults to 100.', 100)
            ->addOption('--max-retries', '-r', InputOption::VALUE_REQUIRED, 'Maximum number of times the queue is allowed to time out.', 10)
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

        /** @var \MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\EventModelExtended $eventModel */
        $eventModel = $container->get('mautic.mauldin.model.event');
        $output->writeln('Loaded event model', OutputInterface::VERBOSITY_DEBUG);

        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel = $container->get('mautic.campaign.model.campaign');
        $output->writeln('Loaded campaign model', OutputInterface::VERBOSITY_DEBUG);

        $this->dispatcher = $container->get('event_dispatcher');
        $translator       = $container->get('translator');
        $em               = $container->get('doctrine')->getManager();

        $id         = $input->getOption('campaign-id');
        $batch      = $input->getOption('batch-limit');
        $maxEvents  = $input->getOption('max-events');
        $maxRetries = $input->getOption('max-retries');

        if (!$this->checkRunStatus($input, $output, $id)) {
            return 0;
        }

        if ($id) {
            /** @var \Mautic\CampaignBundle\Entity\Campaign $campaign */
            $campaign = $campaignModel->getEntity($id);

            if ($campaign !== null && $campaign->isPublished()) {
                if (!$this->dispatchTriggerEvent($campaign)) {
                    return 0;
                }

                $totalProcessed = 0;

                $output->writeln(
                    '<info>'.$translator->trans('mauldin.campaign.trigger.triggering_consume', ['%id%' => $id]).'</info>',
                    OutputInterface::VERBOSITY_VERBOSE
                );

                $output->writeln(
                    '<comment>'.$translator->trans('mauldin.campaign.trigger.starting_consume').'</comment>',
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );

                // trigger starting action events for newly added contacts
                $processed = $eventModel->consumeStartingEvents($campaign, $totalProcessed, $batch, $maxEvents, $output);

                $output->writeln(
                    '<comment>'.$translator->trans('mauldin.campaign.trigger.events_consumed', ['%events%' => $processed]).'</comment>'."\n",
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }
        } else {
            $campaigns = $campaignModel->getEntities(
                [
                    'iterator_mode' => true,
                ]
            );

            while (($c = $campaigns->next()) !== false) {
                $totalProcessed = 0;

                // Key is ID and not 0
                $c = reset($c);

                if ($c->isPublished()) {
                    if (!$this->dispatchTriggerEvent($c)) {
                        continue;
                    }

                    $output->writeln(
                        '<info>'.$translator->trans('mauldin.campaign.trigger.triggering_consume', ['%id%' => $c->getId()]).'</info>',
                        OutputInterface::VERBOSITY_VERBOSE
                    );

                    $output->writeln(
                        '<comment>'.$translator->trans('mauldin.campaign.trigger.starting_consume').'</comment>',
                        OutputInterface::VERBOSITY_VERBOSE
                    );

                    //trigger starting action events for newly added contacts
                    $eventModel->consumeStartingEvents($c, $totalProcessed, $batch, $maxEvents, $output);
                }
            }

            // Timeout in $timeoutPeriod in seconds and give up on $maxRetries retries
            // Reset counter on success
            $timeoutPeriod  = 0.2;
            $timeoutCounter = 0;

            $channel = $eventModel->getChannel();

            while ($channel->hasCallbacks() && ($timeoutCounter < $maxRetries)) {
                try {
                    $channel->wait($timeoutPeriod);
                    $timeoutCounter = 0;
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    $timeoutCounter += 1;
                    $output->writeln(
                        sprintf('Campaign consumer wait timeout counter %d/%d.', $timeoutCounter, $maxRetries),
                        OutputInterface::VERBOSITY_DEBUG
                    );
                } catch (\Exception $e) {
                    $output->writeln(sprintf(
                        '<error>error processing campaign %d</error> - %s',
                        $campaignId,
                        $e->getMessage()
                    ));
                }
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
