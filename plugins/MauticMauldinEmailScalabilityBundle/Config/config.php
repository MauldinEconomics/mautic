<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'name'        => 'Mauldin Email Scalability',
    'description' => 'Make sending of emails scalable by using parallelization and batch transactions',
    'version'     => '1.0',
    'author'      => 'Brick Abode',

    'parameters' => [
        'rabbitmq_host'          => 'rabbitmq',
        'rabbitmq_port'          => '5672',
        'rabbitmq_username'      => 'guest',
        'rabbitmq_password'      => 'guest',
        'rabbitmq_default_queue' => 'email',
    ],

    'services' => [
        'models' => [
            'mautic.mauldin.model.event' => [
                'class'     => 'MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\EventModelExtended',
                'arguments' => [
                    'mautic.helper.ip_lookup',
                    'mautic.helper.core_parameters',
                    'mautic.lead.model.lead',
                    'mautic.campaign.model.campaign',
                    'mautic.user.model.user',
                    'mautic.core.model.notification',
                    'mautic.factory',
                  ],
            ],
            'mautic.scalability.model.scalablelistmodel' => [
                'class' => 'MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\ScalableListModel',
                'arguments' => [
                    'mautic.helper.core_parameters',
                ],
            ],
            'mautic.scalability.model.scalablecampaign' => [
                'class'     => 'MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\ScalableCampaignModel',
                'arguments' => [
                    'mautic.helper.core_parameters',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.list',
                    'mautic.form.model.form',
                ],
            ],
        ],

        'other' => [
            'mauldin.transport_queue.rabbitmq' => [
                'class'     => 'MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\RabbitmqTransportQueue',
                'arguments' => [
                    '@mauldin.scalability.message_queue.channel_helper',
                    '%mautic.rabbitmq_default_queue%',
                ],
            ],
            'mautic.transport.rabbitmq' => [
                'class'        => 'MauticPlugin\MauticMauldinEmailScalabilityBundle\Swiftmailer\Transport\RabbitmqTransport',
                'serviceAlias' => 'swiftmailer.mailer.transport.%s',
                'methodCalls'  => [
                    'setUsername'       => ['%mautic.mailer_user%'],
                    'setPassword'       => ['%mautic.mailer_password%'],
                    'setTransportQueue' => ['@mauldin.transport_queue.rabbitmq'],
                ],
                'arguments' => [
                    '%mautic.mailer_host%',
                    '%mautic.mailer_port%',
                    '%mautic.mailer_encryption%',
                ],
            ],
            'mautic.mauldin.rabbitmq_connection' => [
                'class'     => 'PhpAmqpLib\Connection\AMQPStreamConnection',
                'arguments' => [
                    '%mautic.rabbitmq_host%',
                    '%mautic.rabbitmq_port%',
                    '%mautic.rabbitmq_username%',
                    '%mautic.rabbitmq_password%',
                ],
            ],
            'mauldin.scalability.message_queue.channel_helper' => [
                'class'     => 'MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper',
                'arguments' => [
                    '@mautic.mauldin.rabbitmq_connection',
                ],
            ],
        ],
    ],
];
