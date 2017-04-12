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

use PhpAmqpLib\Message\AMQPMessage;

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

        /** @var \MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\EventModelExtended $model */
        $model = $container->get('mautic.mauldin.model.event');
        $output->writeln('loaded model');
        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel    = $container->get('mautic.campaign.model.campaign');
        $output->writeln('loaded campaign model');
        $this->dispatcher = $container->get('event_dispatcher');
        $translator       = $container->get('translator');
        $em               = $container->get('doctrine')->getManager();
        $id               = $input->getOption('campaign-id');
        $scheduleOnly     = $input->getOption('scheduled-only');
        $negativeOnly     = $input->getOption('negative-only');
        $batch            = $input->getOption('batch-limit');
        $max              = $input->getOption('max-events');

        $connection       = $container->get('mautic.mauldin.rabbitmq_connection');
        $channel          = $connection->channel();

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
                $output->writeln('<info>'.$translator->trans('mautic.campaign.trigger.triggering', ['%id%' => $id]).'</info>');

                    //trigger starting action events for newly added contacts
                    $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.starting').'</comment>');
                $processed = $model->consumeStartingEvents($campaign, $totalProcessed, $batch, $max, $output, $channel);
                $output->writeln(
                        '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', ['%events%' => $processed]).'</comment>'."\n"
                    );
            }
        } else {
            $campaigns = $campaignModel->getEntities(
                [
                    'iterator_mode' => true,
                ]
            );

            $processed_list = [];
            while (($c = $campaigns->next()) !== false) {
                $totalProcessed = 0;

                // Key is ID and not 0
                $c = reset($c);

                if ($c->isPublished()) {
                    if (!$this->dispatchTriggerEvent($c)) {
                        continue;
                    }

                    $output->writeln('<info>'.$translator->trans('mautic.campaign.trigger.triggering', ['%id%' => $c->getId()]).'</info>');
                        //trigger starting action events for newly added contacts
                        $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.starting').'</comment>');
                    $model->consumeStartingEvents($c, $totalProcessed, $batch, $max, $output, $channel);
                }
            }

        // Timeout in  $timeout_period in seconds  and give up on $max_timeout retries
        // Reset counter on success
        $max_timeout = 10;
            $timeout_period = 0.2;
            $timeout_counter = 0 ;
            while (count($channel->callbacks) >= 1  && ($timeout_counter < $max_timeout)) {
                try {
                    $channel->wait(null, false, $timeout_period);
                    $timeout_counter = 0;
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    $output->writeln('trigger_start wait timeout counter ' . $timeout_counter);
                    $timeout_counter = $timeout_counter + 1;
                } catch (\Exception $e) {
                    $output->writeln('error processing - '.$campaignId.' - '.$e);
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
