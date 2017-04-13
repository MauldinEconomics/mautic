<?php

/*
 * @package     Mauldin Clicks
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'name'        => 'Mauldin Clicks',
    'description' => 'Add email link click decision to campaigns',
    'version'     => '1.0',
    'author'      => 'Brick Abode',

    'services' => [
        'events' => [
            'mauldin.clicks.campaignbundle.subscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinClicksBundle\EventListener\CampaignSubscriber',
                'arguments' => [
                    'mautic.lead.model.lead',
                    'mautic.email.model.email',
                    'mautic.campaign.model.event',
                    'mautic.channel.model.queue',
                ],
            ],
        ],
        'forms' => [
            'mauldin.form.type.email_click' => [
                'class' => 'MauticPlugin\MauticMauldinClicksBundle\Form\Type\CampaignEventEmailClickType',
                'alias' => 'mauldin_campaignevent_email_click',
            ],
        ],
    ],
];
