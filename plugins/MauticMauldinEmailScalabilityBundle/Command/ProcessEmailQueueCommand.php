<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\QueueEmailEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to process the e-mail queue.
 */
class ProcessEmailQueueCommand extends ModeratedCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:emails:send')
            ->setDescription('Processes mail queue')
            ->addOption('--max-retries', '-r', InputOption::VALUE_REQUIRED, 'Maximum number of times the queue is allowed to time out.', 10)
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is used to process the application's e-mail queue

<info>php %command.full_name%</info>
EOT
        );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container  = $this->getContainer();
        $dispatcher = $container->get('event_dispatcher');
        $transport  = $container->get('mautic.transport.rabbitmq');
        $queue      = $transport->getTransportQueue();

        if (!$transport->isStarted()) {
            $transport->start();
        }

        $callback = function ($msg) use ($transport, $dispatcher) {
            try {
                $message = unserialize($msg->body);
                $transport->sendDirect($message);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (\Swift_TransportException $e) {
                if ($dispatcher->hasListeners(EmailEvents::EMAIL_FAILED)) {
                    $event = new QueueEmailEvent($message);
                    $dispatcher->dispatch(EmailEvents::EMAIL_FAILED, $event);
                }
            }
        };

        $queue->consume($callback);

        // Give up after '--max-retries' (default: 10)
        $maxRetries     = $input->getOption('max-retries');
        $timoutPeriod   = 0.2;
        $timeoutCounter = 0;

        while ($queue->hasChannelCallbacks() && ($timeoutCounter < $maxRetries)) {
            try {
                $queue->wait($timeoutPeriod);
                $timeoutCounter = 0;
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                $output->writeln('Email wait timeout counter ' + $timeoutCounter);
                $timeoutCounter += 1;
            }
        }

        return 0;
    }
}
