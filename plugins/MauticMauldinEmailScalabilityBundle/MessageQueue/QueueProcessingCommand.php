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
    const MAX_RETRIES     = 10;
    const DEFAULT_TIMEOUT = 0.2;
    const INITIAL_TIMEOUT = 10;

    /**
     * {@inheritdoc}
     */
    protected $channel;

    protected function configure()
    {
        $this->addOption('--max-retries', '-r', InputOption::VALUE_REQUIRED, 'Maximum number of times the queue is allowed to time out.', self::MAX_RETRIES)
            ->addOption('--max-items', '-m', InputOption::VALUE_REQUIRED, 'Maximum amount of messages processed.', PHP_INT_MAX)
            ->addOption('--default-timeout', null, InputOption::VALUE_REQUIRED, 'Time to wait for an item.', self::DEFAULT_TIMEOUT)
            ->addOption('--initial-timeout', null, InputOption::VALUE_REQUIRED, 'Time to wait for the first item.', self::INITIAL_TIMEOUT);

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->setup($input, $output)) {
            $maxRetries     = $input->getOption('max-retries');
            $maxItems       = $input->getOption('max-items');
            $defaultTimeout = $input->getOption('default-timeout');
            $initialTimeout = $input->getOption('initial-timeout');

            $output->writeln('Batches consumed: '.$this->wait($maxRetries, $maxItems, $defaultTimeout, $initialTimeout));
        }
    }

    abstract protected function setup(InputInterface $input, OutputInterface $output);

    private function wait(
        $maxRetries     = self::MAX_RETRIES,
        $maxItems       = PHP_INT_MAX,
        $defaultTimeout = self::DEFAULT_TIMEOUT,
        $initialTimeout = self::INITIAL_TIMEOUT)
    {
        // Timeout in 10 seconds  and give up on $maxRetries
        $timeout_counter = 0;
        // Count messages
        $messages_sent = 0;
        // Exit when no handlers exist
        if($this->channel->hasCallbacks()) {
            try {
                $this->channel->wait($initialTimeout);
                ++$messages_sent;
            } catch (AMQPTimeoutException $e) {
                return 0;
            }
            while (
                $this->channel->hasCallbacks()
                && ($timeout_counter < $maxRetries)
                && ($messages_sent < $maxItems)
            ) {
                try {
                    $this->channel->wait($defaultTimeout);
                    ++$messages_sent;
                    $timeout_counter = 0;
                } catch (AMQPTimeoutException $e) {
                    ++$timeout_counter;
                }
            }
        }
        return $messages_sent;
    }
}
