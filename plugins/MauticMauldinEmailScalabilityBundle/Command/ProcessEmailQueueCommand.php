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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

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
            ->setDescription('Processes SwiftMail\'s mail queue')
            ->addOption('--message-limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of messages sent at a time. Defaults to value set in config.')
            ->addOption('--time-limit', null, InputOption::VALUE_OPTIONAL, 'Limit the number of seconds per batch. Defaults to value set in config.')
            ->addOption('--do-not-clear', null, InputOption::VALUE_NONE, 'By default, failed messages older than the --recover-timeout setting will be attempted one more time then deleted if it fails again.  If this is set, sending of failed messages will continue to be attempted.')
            ->addOption('--recover-timeout', null, InputOption::VALUE_OPTIONAL, 'Sets the amount of time in seconds before attempting to resend failed messages.  Defaults to value set in config.')
            ->addOption('--clear-timeout', null, InputOption::VALUE_OPTIONAL, 'Sets the amount of time in seconds before deleting failed messages.  Defaults to value set in config.')
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
        $options    = $input->getOptions();
        $env        = (!empty($options['env'])) ? $options['env'] : 'dev';
        $container  = $this->getContainer();
        $this->dispatcher = $container->get('event_dispatcher');

        $skipClear = $input->getOption('do-not-clear');
        $quiet     = $input->getOption('quiet');
        $timeout   = $input->getOption('clear-timeout');

        $connection       = $container->get('mautic.mauldin.rabbitmq_connection');
        $channel          = $connection->channel();

        $channel->queue_declare('email', false, false, false, false);

        $this->transport = $this->getContainer()->get('mautic.transport.rabbitmq');
        if (!$this->transport->isStarted()) {
            $this->transport->start();
        }

        $callback = function ($msg) {
            try {
                $message = unserialize($msg->body);
                $this->transport->sendDirect($message);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (\Swift_TransportException $e) {
                if ($this->dispatcher->hasListeners(EmailEvents::EMAIL_FAILED)) {
                    $event = new QueueEmailEvent($message);
                    $this->dispatcher->dispatch(EmailEvents::EMAIL_FAILED, $event);
                }
            }
        };

        // TODO: improve message retry functionality for now its is just going to retry forever
        // until the message is sent, implement retry count limit, TTL expiring or dead lettered messages
        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('email', '', false, false, false, false, $callback);

        // Timeout in 10 seconds  and give up on $max_timeout
        $max_timeout = 10;
        $timeout_counter = 0 ;

        while (count($channel->callbacks) >= 1 && ($timeout_counter < $max_timeout)) {
            try {
                $channel->wait(null, false, 0.2);
                $timeout_counter = 0;
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                $output->writeln('Email wait timeout counter ' + $timeout_counter);
                $timeout_counter = $timeout_counter + 1;
            }
        }
        return 0;
    }
}
