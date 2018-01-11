<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Command;

use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueProcessingCommand;
use MauticPlugin\MauticMauldinCSIBundle\Exception\CSIAPIException;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to process the csi_list queue.
 */
class ProcessCSIListOptCommand extends QueueProcessingCommand
{
    const CSI_LIST_ENDPOINT = 'listmanager';
    const CSI_OPT_OUT       = 'optOut';
    const CSI_OPT_IN        = 'optIn';

    private $csiEnv;
    private $csiRequest;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mauldin:csi:lists:update')
            ->setDescription('Processes CSI List Queue actions and make API Calls');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        $this->csiEnv     = $container->getParameter('mautic.csiapi_env');
        $this->csiRequest = $container->get('mautic.mauldin.csi.request');

        $dispatcher = $container->get('event_dispatcher');

        /** @var ChannelHelper $channelHelper */
        $channelHelper = $container->get('mauldin.scalability.message_queue.channel_helper');
        $queue         = $channelHelper->declareQueue('csi_list');
        $this->channel = $queue->getChannel();

        $callback = function ($msg) {
            $this->process($msg);
        };

        $queue->consume($callback);
        return 0;
    }

    /**
     * $data = [
     *     'email' => 'foo@bar.com',
     *     'code' => 'list',
     *     'session_tracking_id' => '1234abcd', // optional
     *     'client_tracking_id' => '1234abcd',  // optional
     *     'user_tracking_id' => '1234abcd',    // optional
     * ]
     *
     * @throws CSIAPIException
     */
    public function optIn(array $data)
    {
        $urlParts = [self::CSI_LIST_ENDPOINT, self::CSI_OPT_IN, $this->csiEnv];
        $this->csiRequest->simpleGet($urlParts, $data);
    }

    /**
     * $data = [
     *     'email' => 'foo@bar.com',
     *     'code' => 'list'
     * ]
     *
     * @throws CSIAPIException
     */
    public function optOut(array $data)
    {
        $urlParts = [self::CSI_LIST_ENDPOINT, self::CSI_OPT_OUT, $this->csiEnv];
        $this->csiRequest->simpleGet($urlParts, $data);
    }

    private function process($msg)
    {
        $message = unserialize($msg->body);
        try {
            if (isset($message['add'])) {
                $this->optIn($message['add']);
            }
            if (isset($message['remove'])) {
                $this->optOut($message['remove']);
            }
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        } catch (CSIAPIException $e) {
            error_log($e);
            switch ($e->getCode()) {
                case 200:
                    // Log api errors to a file in json format
                    $file = fopen('app/logs/csiapi-opt.log', 'a');
                    fwrite($file, json_encode($e->getErrorData())."\n");
                    fclose($file);
                    // Remove api return errors from queue
                    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
                    break;
                default:
                    // Don't acknowledge the message in the other case
                    // So the message is not removed from the queue
            }
        }
    }
}
