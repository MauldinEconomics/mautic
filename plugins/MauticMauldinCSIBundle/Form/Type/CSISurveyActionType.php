<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class ListActionType.
 */
class CSISurveyActionType extends AbstractType
{
    use \Mautic\FormBundle\Form\Type\FormFieldTrait;

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $fields = $this->getFormFields($options['attr']['data-formid'], false);

        foreach ($fields as $alias => $label) {
            $builder->add(
                $alias,
                'yesno_button_group',
                [
                    'label'      => $label." ($alias)",
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => [
                        'class' => 'form-control',
                    ],
                    'required' => false,
                ]
            );
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'csisurvey_action';
    }
}
