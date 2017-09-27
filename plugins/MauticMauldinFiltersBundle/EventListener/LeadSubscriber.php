<?php

/*
 * @package     Mauldin Filters
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinFiltersBundle\EventListener;

use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\LeadBundle\Event\LeadListFilteringEvent;
use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\MauticMauldinFiltersBundle\ClickEvents;
use MauticPlugin\MauticMauldinCSIBundle\Model\SETRequestModel;

/**
 * Class CampaignSubscriber.
 */
class LeadSubscriber extends CommonSubscriber
{
    /**
     * @var EventModel
     */
    protected $listModel;

    protected $setRequestModel;

    /**
     * CampaignSubscriber constructor.
     *
     * @param EventModel $eventModel
     */
    public function __construct(ListModel $listModel, SETRequestModel $setRequestModel)
    {
        $this->listModel = $listModel;
        $this->setRequestModel = $setRequestModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['onListChoicesGenerate', 0],
            LeadEvents::LIST_FILTERS_ON_FILTERING => ['onListFiltering', 0],
        ];
    }

    public function onListChoicesGenerate(LeadListFiltersChoicesEvent $event)
    {
        $event->addChoice(
            'lead',
            'lead_custom_sql',
            [   'label'      => $this->translator->trans('mauldin.lead.list.filter.lead_custom_sql'),
                'properties' => [
                    'type' => 'text',
                ],
                'operators' => $this->listModel->getOperatorsForFieldType('multiselect'),
            ]);
        $event->addChoice('lead',
            'lead_asset_download',
            [   'label'      => $this->translator->trans('mauldin.lead.list.filter.lead_asset_download'),
                'properties' => [
                    'type' => 'assets',
                ],
                'operators' => $this->listModel->getOperatorsForFieldType('multiselect'),
            ]);

        $event->addChoice(
            'lead',
            'lead_set_list_membership',
            [   'label'      => $this->translator->trans('mauldin.lead.list.filter.lead_set_list_membership'),
                'properties' => [
                    'type' => 'select',
                    'list' => $this->setRequestModel->getSetLists(),
                ],
                'operators' => $this->listModel->getOperatorsForFieldType('multiselect'),
            ]);
    }

    /**
     * @param LeadListFilteringEvent $event
     */
    public function onListFiltering(LeadListFilteringEvent $event)
    {

        $details           = $event->getDetails();
        $em                = $event->getEntityManager();
        $func              = $event->getFunc();
        $leadId            = $event->getLeadId();
        $currentFilter     = $details['field'];

        switch ($currentFilter) {
            case 'lead_custom_sql':
                $func = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';

                $event->setSubQuery(sprintf('%s (%s)', $func, $details['filter']));
                $event->setFilteringStatus(true);
                break;

            case 'lead_asset_download':
                $alias = $this->generateRandomParameterName();
                $func = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';

                foreach ($details['filter'] as &$value) {
                    $value = (int) $value;
                }

                $subQb   = $em->getConnection()->createQueryBuilder();
                $subExpr = $subQb->expr()->andX(
                    $subQb->expr()->eq($alias.'.lead_id', 'l.id')
                );

                // Specific lead
                if (!empty($leadId)) {
                    $subExpr->add(
                        $subQb->expr()->eq($alias.'.lead_id', $leadId)
                    );
                }

                $table  = 'asset_downloads';
                $column = 'asset_id';

                $subExpr->add(
                    $subQb->expr()->in(sprintf('%s.%s', $alias, $column), $details['filter'])
                );

                $subQb->select('null')
                    ->from(MAUTIC_TABLE_PREFIX.$table, $alias)
                    ->where($subExpr);

                $event->setSubQuery(sprintf('%s (%s)', $func, $subQb->getSQL()));
                $event->setFilteringStatus(true);
                break;

            case 'lead_set_list_membership':
                $alias = $this->generateRandomParameterName();
                $func = in_array($func, ['eq', 'in']) ? 'EXISTS' : 'NOT EXISTS';

                foreach ($details['filter'] as &$value) {
                    $value = (int) $value;
                }

                $subQb   = $em->getConnection()->createQueryBuilder();
                $subExpr = $subQb->expr()->andX(
                    $subQb->expr()->eq($alias.'.lead_id', 'l.id')
                );

                // Specific lead
                if (!empty($leadId)) {
                    $subExpr->add(
                        $subQb->expr()->eq($alias.'.lead_id', $leadId)
                    );
                }

                $table  = 'setlists_leads';
                $column = 'setlist_id';

                $subExpr->add(
                    $subQb->expr()->in(sprintf('%s.%s', $alias, $column), $details['filter'])
                );

                $subQb->select('null')
                    ->from(MAUTIC_TABLE_PREFIX.$table, $alias)
                    ->where($subExpr);

                $event->setSubQuery(sprintf('%s (%s)', $func, $subQb->getSQL()));
                $event->setFilteringStatus(true);
                break;
        }
    }
    /**
     * @return string
     */
    protected function generateRandomParameterName()
    {
        $alpha_numeric = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        return substr(str_shuffle($alpha_numeric), 0, 8);
    }
}
