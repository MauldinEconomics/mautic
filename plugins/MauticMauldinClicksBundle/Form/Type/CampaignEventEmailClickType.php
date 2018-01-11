<?php

/*
 * @package     Mauldin Clicks
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinClicksBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class CampaignEventEmailClickType.
 */
class CampaignEventEmailClickType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('url', 'text', [
            'label'      => 'mauldin.click.campaign.event.form.url',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mauldin.click.campaign.event.form.url.descr',
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'mauldin_campaignevent_email_click';
    }
}
