<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueProcessingCommand;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueRequestHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to process the e-mail queue.
 */
class ProcessEmailHitCommand extends QueueProcessingCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:emails:hits')
            ->setDescription('Processes email hit queue');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $container  = $this->getContainer();
        $emailModel = $container->get('mautic.email.model.email');
        $queue      = $emailModel->getEmailHitQueue();

        $this->channel = $queue->getChannel();

        $callback = function ($msg) use ($emailModel) {
            $message = unserialize($msg->body);
            $emailModel->consumeHitEmail(
                $message['id_hash'],
                QueueRequestHelper::buildRequest($message['request'])
            );

            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        };

        $queue->consume($callback);

        return 0;
    }
}
