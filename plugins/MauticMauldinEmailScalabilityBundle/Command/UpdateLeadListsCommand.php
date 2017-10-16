<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use Mautic\LeadBundle\Entity\LeadList;
use MJS\TopSort\Implementations\StringSort;
use MJS\TopSort\CircularDependencyException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateLeadListsCommand extends ModeratedCommand
{
    private $setListModel;

    private function notifyCircularDependency($listsIds, $lists) {
        $container         = $this->getContainer();
        $notificationModel = $container->get('mautic.core.model.notification');
        $userModel         = $container->get('mautic.user.model.user');
        $translator        = $container->get('translator');

        foreach ($lists as $list) {
            $owner = $userModel->getEntity($list->getCreatedBy());
            if ($owner != null) {
                $message = $translator->trans(
                    'mauldin.segments.update.circular_dependency',
                    [
                        '%list_name%'       => $list->getName(),
                        '%list_id%'         => $list->getId(),
                        '%dependant_lists%' => $listsIds,
                    ]
                );

                $header = $translator->trans('mauldin.segments.update.circular_dependency_header');

                $notificationModel->addNotification(
                    $message,
                    'error',
                    false,
                    $header,
                    null,
                    null,
                    $owner
                );
            }
        }
    }

    protected function configure()
    {
        $this
            ->setName('mauldin:segments:update')
            ->setAliases(['mauldin:segments:rebuild'])
            ->setDescription('Update contacts in smart segments based on new contact data.')
            ->addOption('--batch-limit', '-b', InputOption::VALUE_OPTIONAL, 'Set batch size of contacts to process per round. Defaults to 300.', 300)
            ->addOption(
                '--max-contacts',
                '-m',
                InputOption::VALUE_OPTIONAL,
                'Set max number of contacts to process per segment for this script execution. Defaults to all.',
                false
            )
            ->addOption('--list-id', '-i', InputOption::VALUE_OPTIONAL, 'Specific ID to rebuild. Defaults to all.', false);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container  = $this->getContainer();
        $translator = $container->get('translator');

        /** @var \Mautic\LeadBundle\Model\ListModel $listModel */
        $listModel          = $container->get('mautic.scalability.model.scalablelistmodel');
        $this->setListModel = $container->get('mautic.mauldin.set.list');

        $id    = $input->getOption('list-id');
        $batch = $input->getOption('batch-limit');
        $max   = $input->getOption('max-contacts');

        if (!$this->checkRunStatus($input, $output, $id)) {
            return 0;
        }

        if ($id) {
            $list = $listModel->getEntity($id);
            if ($list !== null && substr($list->getAlias(), 0, 4) !== 'csi-' && $l->isPublished() && $this->processSetDependencies($list)) {
                $output->writeln('<info>'.$translator->trans('mautic.lead.list.rebuild.rebuilding', ['%id%' => $id]).'</info>');
                $processed = $listModel->rebuildListLeads($list, $batch, $max, $output);
                $output->writeln(
                    '<comment>'.$translator->trans('mautic.lead.list.rebuild.leads_affected', ['%leads%' => $processed]).'</comment>'
                );
            } else {
                $output->writeln('<error>'.$translator->trans('mautic.lead.list.rebuild.not_found', ['%id%' => $id]).'</error>');
            }
        } else {
            $lists = $listModel->getEntities();
            $deplist = new StringSort();
            $listarray = [];
            /** @var LeadList $list */
            if($lists->count() !== null) {
                foreach ($lists as $list) {
                    $deps = [];
                    foreach ($list->getFilters() as $filter) {
                        if ($filter['type'] == 'leadlist') {
                            $deps = array_merge($deps, $filter['filter']);
                        }
                    }

                    $deplist->add($list->getId(), $deps);
                    $listarray[$list->getId()] = $list;
                }

                try {
                    $result = $deplist->sort();
                } catch (CircularDependencyException $e) {
                    $result = [];
                    $nodesIds = $e->getNodes();
                    $nodes = [];
                    foreach ($nodesIds as $node) {
                        $nodes[$node] = $listarray[$node];
                    }
                    $this->notifyCircularDependency(implode(', ', $nodesIds), $nodes);

                    error_log($e);
                }

                foreach ($result as $dep) {
                    // Get first item; using reset as the key will be the ID and not 0
                    $l = $listarray[$dep];

                    if ($l !== null && substr($l->getAlias(), 0, 4) !== 'csi-' && $l->isPublished() && $this->processSetDependencies($l)) {

                        $output->writeln('<info>'.$translator->trans('mautic.lead.list.rebuild.rebuilding', ['%id%' => $l->getId()]).'</info>');
                        $processed = $listModel->rebuildListLeads($l, $batch, $max, $output);
                        $output->writeln(
                            '<comment>'.$translator->trans('mautic.lead.list.rebuild.leads_affected', ['%leads%' => $processed]).'</comment>'."\n"
                        );
                    }

                    unset($l);
                }
            }

            unset($lists);
        }

        $this->completeRun();

        return 0;
    }

    /*
     * Checks if a list depends imediately on any SET list.
     * If it depends, checks if the caches are valid.
     * If any cache is not valid, requests a cache update.
     *
     * @param LeadList list
     *
     * Returns true if all caches are valid or there is no dependency.
     * @return boolean
     */
    protected function processSetDependencies($list)
    {
        // Find SET dependencies
        $deps = [];
        foreach ($list->getFilters() as $filter) {
            if ($filter['field'] === 'lead_set_list_membership') {
                $deps = array_merge($deps, $filter['filter']);
            }
        }

        if (empty($deps)) {
            return true;
        }

        // Check if cache is valid
        $invalids = [];
        foreach ($deps as $dep) {
            if (!$this->setListModel->isCacheValid($dep)) {
                $invalids[] = $dep;
            }
        }

        if (empty($invalids)) {
            return true;
        }

        // Request cache update
        foreach ($invalids as $invalid) {
            $this->setListModel->requestCacheUpdate($invalid, $list);
        }

        return false;
    }
}
