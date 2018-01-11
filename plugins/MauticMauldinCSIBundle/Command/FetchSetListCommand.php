<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to fetch the SET lists from SET and update the local cache
 */
class FetchSetListCommand extends ModeratedCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mauldin:set:lists:fetch')
            ->setDescription('Fetches the remote cache and update the local cache for each SET list');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $setList = $this->getContainer()->get('mautic.mauldin.set.list');

        $listIds = $setList->getUpdatableLists();

        echo('Updatable: ' . json_encode($listIds) . PHP_EOL);

        foreach ($listIds as $listId) {
            $flag = $setList->maybeUpdateCache($listId);
            if ($flag) {
                $output->writeln('Updated local cache of SET list ' . $listId);
            }
        }
    }
}
