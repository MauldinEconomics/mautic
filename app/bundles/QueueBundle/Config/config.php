<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

return [
    'services' => [
        'events' => [
            'mautic.queue.rabbitmq.subscriber' => [
                'class'     => \Mautic\QueueBundle\EventListener\RabbitMqSubscriber::class,
                'arguments' => 'service_container',
            ],
            'mautic.queue.beanstalkd.subscriber' => [
                'class'     => \Mautic\QueueBundle\EventListener\BeanstalkdSubscriber::class,
                'arguments' => [
                    'service_container',
                    'mautic.queue.service',
                ],
            ],
        ],
        'other' => [
            'mautic.queue.service' => [
                'class'     => \Mautic\QueueBundle\Queue\QueueService::class,
                'arguments' => [
                    'mautic.helper.core_parameters',
                    'event_dispatcher',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.queue.helper.rabbitmq_consumer' => [
                'class'     => \Mautic\QueueBundle\Helper\RabbitMqConsumer::class,
                'arguments' => 'mautic.queue.service',
            ],
        ],
    ],
    'parameters' => [
        // This is an advanced setup allowing a work queue/message broker to process page hits and email tokens outside of the web request.
        // The work queue/message broker must be configured and running outside of Mautic for this to function.
        // Currently supports rabbitmq or beanstalkd
        'queue_protocol'     => '',
        // The hostname of the RabbitMQ server
        'rabbitmq_host'      => 'localhost',
        // The port that the RabbitMQ server is listening on
        'rabbitmq_port'      => '5672',
        // The virtual host to use for this RabbitMQ server
        'rabbitmq_vhost'     => '/',
        // The username for the RabbitMQ server
        'rabbitmq_user'      => 'guest',
        // The password for the RabbitMQ server
        'rabbitmq_password'  => 'guest',

        // Number of seconds until the socket client connect system should timeout
        'rabbitmq_connection_timeout'     => 3,

        // Interval at which heartbeat frames are sent
        'rabbitmq_heartbeat'              => 2,

        // After what period of time the peer TCP connection should be
        // considered unreachable (down) by RabbitMQ and client
        // libraries. Must be at least 2x the heartbeat interval.
        'rabbitmq_read_write_timeout'     => 4,

        // Specifies the prefetch window size in octets. The server will send
        // a message in advance if it is equal to or smaller in size than the
        // available prefetch size (and also falls into other prefetch
        // limits). May be set to zero, meaning "no specific limit", although
        // other prefetch limits may still apply.
        'rabbitmq_qos_prefetch_size'      => 0,

        // Specifies a prefetch window in terms of whole messages. This field
        // may be used in combination with the prefetch-size field; a message
        // will only be sent in advance if both prefetch windows (and those at
        // the channel and connection level) allow it.
        'rabbitmq_qos_prefetch_count'     => 0,

        // Whether QOS prefetch settings are global as opposed to
        // per-channel. RabbitMQ has reinterpreted this field. The original
        // specification said: "By default the QoS settings apply to the
        // current channel only. If this field is set, they are applied to the
        // entire connection." Instead, RabbitMQ takes global=false to mean
        // that the QoS settings should apply per-consumer (for new consumers
        // on the channel; existing ones being unaffected) and global=true to
        // mean that the QoS settings should apply per-channel.
        'rabbitmq_qos_global'             => false,

        // The hostname of the Beanstalkd server
        'beanstalkd_host'    => 'localhost',
        // The port that the Beanstalkd server is listening on
        'beanstalkd_port'    => '11300',
        // The default TTR for Beanstalkd jobs
        'beanstalkd_timeout' => '60',
    ],
];
