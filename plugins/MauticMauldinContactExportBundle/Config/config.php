<?php

/*
 * @package     Mauldin Filters
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'name'        => 'Mauldin CSV Batch',
    'description' => 'Export Batch CSV Lists',
    'version'     => '1.0',
    'author'      => 'Brick Abode',
    'routes'      => [
        'main' => [
            'mautic_csi_contact_action' => [
                'path'       => '/csi/lead/batchexport',
                'controller' => 'MauticMauldinContactExportBundle:Lead:batchExport',
            ],
        ],
    ],
    'services' => [
        'events' => [
            'mautic.csi.button.subscriber' => [
                'class' => 'MauticPlugin\MauticMauldinContactExportBundle\EventListener\ButtonSubscriber',
            ],
        ],
        'models' => [
            'mautic.mauldincsi.model.lead' => [
                'class'     => 'MauticPlugin\MauticMauldinContactExportBundle\Model\LeadModel',
                'arguments' => [
                    'request_stack',
                    'mautic.helper.cookie',
                    'mautic.helper.ip_lookup',
                    'mautic.helper.paths',
                    'mautic.helper.integration',
                    'mautic.lead.model.field',
                    'mautic.lead.model.list',
                    'form.factory',
                    'mautic.lead.model.company',
                    'mautic.category.model.category',
                    'mautic.channel.helper.channel_list',
                    '%mautic.track_contact_by_ip%',

                ],
            ],
        ],
    ],
];
