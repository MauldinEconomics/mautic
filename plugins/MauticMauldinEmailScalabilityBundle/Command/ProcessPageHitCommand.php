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
class ProcessPageHitCommand extends QueueProcessingCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:page:hits')
            ->setDescription('Processes page hit queue');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $container  = $this->getContainer();
        $pageModel  = $container->get('mautic.page.model.page');
        $queue      = $pageModel->getPageHitQueue();

        $this->channel = $queue->getChannel();

        $callback = function ($msg) use ($pageModel) {
            $message = unserialize($msg->body);
            $pageModel->consumeHitPage(
                $message['pageId'],
                $message['pageType'],
                QueueRequestHelper::buildRequest($message['request']),
                $message['code'],
                $message['leadId'],
                $message['query'],
                $message['ip']
            );

            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        };

        $queue->consume($callback);

        return 0;
    }
}
