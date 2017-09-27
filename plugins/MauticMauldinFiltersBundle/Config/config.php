<?php

/*
 * @package     Mauldin Filters
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'name'        => 'Mauldin Filters',
    'description' => 'Add new filters to mautic',
    'version'     => '1.0',
    'author'      => 'Brick Abode',

    'services' => [
        'events' => [
            'mauldin.filters.leadbundle.subscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinFiltersBundle\EventListener\LeadSubscriber',
                'arguments' => [
                    'mautic.lead.model.list',
                    'mautic.mauldin.set.request',
                ],
            ],
        ],
        'model' => [
            'mautic.mauldin.set.request' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Model\SETRequestModel',
                'arguments' => [
                    '%mautic.setapi_entity_code%',
                    '%mautic.setapi_host%',
                    '%mautic.setapi_private_key%',
                    '%mautic.setapi_user_guid%',
                ],
            ],
        ],
    ],
];
