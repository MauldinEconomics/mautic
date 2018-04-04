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
                    'mautic.mauldin.csi.list',
                ],
            ],
            'mauldin.csi.formbundle.subscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\EventListener\FormSubscriber',
                'arguments' => [
                    'mautic.lead.model.lead',
                    'mautic.mauldin.csi.list',
                ],
            ],
            'mauldin.csi.formbundle.survey_subscriber' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\EventListener\SurveyFormSubscriber',
                'arguments' => [
                    'mautic.mauldin.csi.survey',
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
            'mautic.mauldin.set.list' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Model\SETListModel',
                'arguments' => [
                    'database_connection',
                    'mautic.mauldin.set.request',
                ],
            ],
            'mautic.mauldin.csi.request' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Model\CSIRequestModel',
                'arguments' => [
                    '%mautic.csiapi_username%',
                    '%mautic.csiapi_password%',
                    '%mautic.csiapi_key%',
                    '%mautic.csiapi_entity_code%',
                    '%mautic.csiapi_host%',
                ],
            ],
            'mautic.mauldin.csi.list' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Model\CSIListModel',
                'arguments' => [
                    'mautic.lead.model.list',
                    'mauldin.scalability.message_queue.channel_helper',
                    'mautic.mauldin_lead_affiliate.model.lead_affiliate',
                ],
            ],
            'mautic.mauldin.csi.survey' => [
                'class'     => 'MauticPlugin\MauticMauldinCSIBundle\Model\CSISurveyModel',
                'arguments' => [
                    'mautic.lead.model.list',
                    'mauldin.scalability.message_queue.channel_helper',
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
            'mautic.form.type.csisurvey_action' => [
                'class'       => 'MauticPlugin\MauticMauldinCSIBundle\Form\Type\CSISurveyActionType',
                'alias'       => 'csisurvey_action',
                'methodCalls' => [
                    'setFieldModel' => ['mautic.form.model.field'],
                    'setFormModel'  => ['mautic.form.model.form'],
                ],
            ],
        ],

    ],
];
