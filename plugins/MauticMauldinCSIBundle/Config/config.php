<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'name'        => 'Mauldin CSI',
    'description' => 'CSI Integration Plugin',
    'version'     => '1.0',
    'author'      => 'Brick Abode',

    'services' => [
        'events' => [
            'mauldin.csi.campaignbundle.subscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\EventListener\CampaignSubscriber',
                'arguments' => [
                    'mautic.mauldin.csi.csi',
                ],
            ],
            'mauldin.csi.formbundle.subscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\EventListener\FormSubscriber',
                'arguments' => [
                    'mautic.mauldin.csi.csi',
                ],
            ],
        ],
        'model' => [
            'mautic.mauldin.csi.csi' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Model\CSIModel',
                'arguments' => [
                    'mautic.lead.model.list',
                    '@mauldin.scalability.message_queue.channel_helper',
                ],
            ],
        ],
        'other' => [
            'mautic.mauldin.csi.rabbitmq_connection' => [
                'class'     => 'PhpAmqpLib\Connection\AMQPStreamConnection',
                'arguments' => [
                    '%mautic.rabbitmq_host%',
                    '%mautic.rabbitmq_port%',
                    '%mautic.rabbitmq_username%',
                    '%mautic.rabbitmq_password%',
                ],
            ],
        ],
        'forms' => [
            'mautic.form.type.csilist_choices' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Form\Type\CSIListType',
                'arguments' => ['mautic.factory'],
                'alias'     => 'csilist_choices',
            ],
            'mautic.form.type.csilist_action' => [
                'class' => 'MauticPlugin\MauticMauldinCSIBundle\Form\Type\CSIListActionType',
                'alias' => 'csilist_action',
            ],
        ],

    ],
];
