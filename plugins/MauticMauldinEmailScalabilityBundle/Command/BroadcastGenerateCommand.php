<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\QueuedEmailModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to generate the broadcast queue.
 */
class BroadcastGenerateCommand extends ModeratedCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mauldin:broadcasts:send:generate')
            ->setDescription('Enqueue emails on broadcast queue')
            ->addOption(
                '--email-id',
                '-i',
                InputOption::VALUE_OPTIONAL,
                'Send a specific email with ID.',
                null
            )
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is used to generate the broadcast queue
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
        $container = $this->getContainer();
        /** @var QueuedEmailModel $emailModel */
        $emailModel = $container->get('mautic.email.model.email');
        $em         = $container->get('doctrine')->getManager();
        $translator = $container->get('translator');

        $id     = $input->getOption('email-id');
        $emails = $emailModel->getRepository()->getPublishedBroadcasts($id);

        while (($email = $emails->next()) !== false) {
            list($sentCount, $failedCount, $ignore, $last) = $emailModel->sendEmailToListsGenerate($email[0], null, 100, true, $output);

            $output->writeln("\n".$translator->trans('mautic.email.email').': '.$email[0]->getName()."\n".var_export(['queued' => $sentCount, 'failed' => $failedCount], true));
            $em->detach($email[0]);
        }

        return 0;
    }
}
