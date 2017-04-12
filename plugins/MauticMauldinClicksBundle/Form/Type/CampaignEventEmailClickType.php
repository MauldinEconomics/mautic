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
 * Class CampaignEventPageHitType.
 */
class CampaignEventEmailClickType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('url', 'text', [
            'label'      => 'mautic.page.campaign.event.form.url',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.page.campaign.event.form.url.descr',
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'campaignevent_email_click';
    }
}
