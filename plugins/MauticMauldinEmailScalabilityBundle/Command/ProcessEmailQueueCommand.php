<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\QueueEmailEvent;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueProcessingCommand;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Swiftmailer\Transport\RabbitmqTransport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to process the e-mail queue.
 */
class ProcessEmailQueueCommand extends QueueProcessingCommand
{
    private $sendLogModel = null;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:emails:send')
            ->setDescription('Processes mail queue')
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
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $container  = $this->getContainer();
        $dispatcher = $container->get('event_dispatcher');
        /** @var RabbitmqTransport $transport */
        $transport     = $container->get('mautic.transport.rabbitmq');
        $queue         = $transport->getTransportQueue();
        $this->channel = $queue->getChannel();

        if (!$transport->isStarted()) {
            $transport->start();
        }

        $this->sendLogModel = $container->get('mautic.mauldin.model.emailsendlog');
        $this->sendLogModel->setJobId($input->getOption('job-id'));

        $sendLogModel = $this->sendLogModel;

        $callback = function ($msg) use ($transport, $dispatcher, $sendLogModel) {
            try {
                $message = unserialize($msg->body);
                $transport->sendDirect($message['emailMsg']);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
                $sendLogModel->logEmailSend($message['emailId']);
            } catch (\Swift_TransportException $e) {
                if ($dispatcher->hasListeners(EmailEvents::EMAIL_FAILED)) {
                    $event = new QueueEmailEvent($message);
                    $dispatcher->dispatch(EmailEvents::EMAIL_FAILED, $event);
                }
            }
        };

        $queue->consume($callback);

        return 0;
    }

    protected function wait(
        $maxRetries     = self::MAX_RETRIES,
        $maxItems       = PHP_INT_MAX,
        $defaultTimeout = self::DEFAULT_TIMEOUT,
        $initialTimeout = self::INITIAL_TIMEOUT)
    {
        $result = parent::wait($maxRetries, $maxItems, $defaultTimeout, $initialTimeout);
        $this->sendLogModel->logEmailSendEnd();
        return $result;
    }
}
