<?php

/*
 * @package     Mauldin RabbitMQ
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'routes' => [
        'main' => [
            'mautic_rabbitmq_index' => [
                'path'       => '/rabbitmq-status',
                'controller' => 'MauticMauldinRabbitMQBundle:Default:index',
            ],
        ],
    ],
    'menu' => [
        'main' => [
            'mautic.rabbitmq.menu.index' => [
                'route'     => 'mautic_rabbitmq_index',
                'iconClass' => 'fa-barcode',
                'priority'  => -1,
            ],
        ],
    ],
];
