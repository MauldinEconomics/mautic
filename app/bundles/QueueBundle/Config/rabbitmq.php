<?php

/*
 * @copyright   2017 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl3.0.html
 */

$container->loadFromExtension(
    'old_sound_rabbit_mq',
    [
        'connections' => [
            'default' => [
                'host'               => '%mautic.rabbitmq_host%',
                'port'               => '%mautic.rabbitmq_port%',
                'user'               => '%mautic.rabbitmq_user%',
                'password'           => '%mautic.rabbitmq_password%',
                'vhost'              => '%mautic.rabbitmq_vhost%',
                'lazy'               => true,
                'connection_timeout' => '%mautic.rabbitmq_connection_timeout%',
                'heartbeat'          => '%mautic.rabbitmq_heartbeat%',
                'read_write_timeout' => '%mautic.rabbitmq_read_write_timeout%',
            ],
        ],
        'producers' => [
            'mautic' => [
                'class'            => 'Mautic\QueueBundle\Helper\RabbitMqProducer',
                'connection'       => 'default',
                'exchange_options' => [
                    'name'    => 'mautic',
                    'type'    => 'direct',
                    'durable' => true,
                ],
                'queue_options' => [
                    'name'        => 'email_hit',
                    'auto_delete' => false,
                    'durable'     => true,
                ],
            ],
        ],
        'consumers' => [
            'mautic' => [
                'connection'       => 'default',
                'exchange_options' => [
                    'name'    => 'mautic',
                    'type'    => 'direct',
                    'durable' => true,
                ],
                'queue_options' => [
                    'name'        => 'email_hit',
                    'auto_delete' => false,
                    'durable'     => true,
                ],
                'callback'               => 'mautic.queue.helper.rabbitmq_consumer',
                'qos_options'            => [
                    'prefetch_size'  => '%mautic.rabbitmq_qos_prefetch_size%',
                    'prefetch_count' => '%mautic.rabbitmq_qos_prefetch_count%',
                    'global'         => '%mautic.rabbitmq_qos_global%',
                ],
            ],
        ],
    ]
);
