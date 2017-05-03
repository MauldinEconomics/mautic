<?php

/*
 * @package     Mauldin TreeView
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'name'        => 'Mauldin Tree View',
    'description' => 'Add a new tab with a tree view of the campaign',
    'version'     => '1.0',
    'author'      => 'Brick Abode',

    'services' => [
        'events' => [
            'mauldin.treeview.campaignbundle.contentsubscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinTreeViewBundle\EventListener\ContentSubscriber',
                'arguments' => [
                    'mautic.campaign.model.campaign',
                    'mauldin.treeview.model.campaigntreeview',
                ],
            ],
        ],
        'model' => [
            'mauldin.treeview.model.campaigntreeview' => [
                'class'     => 'MauticPlugin\MauticMauldinTreeViewBundle\Model\CampaignTreeViewModel',
                'arguments' => [ '@doctrine.orm.entity_manager' ]
            ],
        ]
    ],
];
