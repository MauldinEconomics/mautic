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
 * CLI command to process the csi_survey queue.
 */
class ProcessCSISurveyResultCommand extends QueueProcessingCommand
{
    const CSI_SURVEY_ENDPOINT = 'survey/postAttributes';

    private $csiEnv;
    private $csiRequest;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mauldin:csi:surveys:send')
            ->setDescription('Processes CSI Survey Queue actions and make API calls');
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

        $dispatcher     = $container->get('event_dispatcher');

        /** @var ChannelHelper $channelHelper */
        $channelHelper = $container->get('mauldin.scalability.message_queue.channel_helper');
        $queue         = $channelHelper->declareQueue('csi_survey');
        $this->channel = $queue->getChannel();

        $callback = function ($msg) {
            $this->process($msg);
        };

        $queue->consume($callback);
        return 0;
    }

    /**
     * @param array  $data
     *
     * $data = [
     *     'email' => 'foo@bar.com',
     *     'attributes' => [
     *         'key' => 'value',
     *         ...
     *     ]
     * ]
     *
     */
    public function sendSurveyResultToCSI($data)
    {
        $urlParts = [self::CSI_SURVEY_ENDPOINT, $this->csiEnv];
        $this->csiRequest->simpleGet($urlParts, $data);
    }

    private function process($msg)
    {
        try {
            $message = unserialize($msg->body);
            $this->sendSurveyResultToCSI($message);
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        } catch (CSIAPIException $e) {
            error_log($e);
            switch ($e->getCode()) {
                case 200:
                    // Log api errors to a file in json format
                    $file = fopen('app/logs/csiapi-survey.log', 'a');
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
