<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue;

use Mautic\CoreBundle\Command\ModeratedCommand;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to process a rabbitmq queue.
 */
abstract class QueueProcessingCommand extends ModeratedCommand
{
    const DEFAULT_MAX_TIMEOUT = 10;
    /**
     * {@inheritdoc}
     */
    protected $channel;

    protected function configure()
    {
        $this->addOption('--max-retries', '-r', InputOption::VALUE_REQUIRED, 'Maximum number of times the queue is allowed to time out.', self::DEFAULT_MAX_TIMEOUT)
            ->addOption('--max-items', '-m', InputOption::VALUE_REQUIRED, 'Maximum number of times the queue is allowed to time out.', PHP_INT_MAX);

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->setup($input, $output)) {
            $maxRetries = $input->getOption('max-retries');
            $maxItems   = $input->getOption('max-items');

            $output->writeln('Batches consumed: '.$this->wait($maxItems, $maxRetries));
        }
    }

    abstract protected function setup(InputInterface $input, OutputInterface $output);

    private function wait($message_limit = PHP_INT_MAX, $max_timeout = self::DEFAULT_MAX_TIMEOUT, $timeout_period = 0.2)
    {
        // Timeout in 10 seconds  and give up on $max_timeout
        $timeout_counter = 0;
        // Count messages
        $messages_sent = 0;

        while (
            count($this->channel->hasCallbacks()) >= 1
            && ($timeout_counter < $max_timeout)
            && ($messages_sent < $message_limit)
        ) {
            try {
                $this->channel->wait($timeout_period);
                ++$messages_sent;
                $timeout_counter = 0;
            } catch (AMQPTimeoutException $e) {
                ++$timeout_counter;
            }
        }

        return $messages_sent;
    }
}
