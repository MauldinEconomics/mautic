<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\QueueProcessingCommand;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\QueuedEmailModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *  CLI command to consume the broadcast queue.
 */
class BroadcastConsumeCommand extends QueueProcessingCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:broadcasts:send:consume')
            ->setDescription('Consume broadcast queue')
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is used to consume the broadcast queue

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
        $container = $this->getContainer();
        /** @var QueuedEmailModel $emailModel */
        $emailModel = $container->get('mautic.email.model.email');

        /** @var Email $email */
        $this->channel = $emailModel->getChannel();

        $emails= $emailModel->getRepository()->getPublishedBroadcasts();
        while (($email = $emails->next()) !== false) {
            $emailModel->sendEmailToListsConsume($email[0],$output);
        }
        return 0;
    }
}
