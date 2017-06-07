<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Form\Type;

use Mautic\CoreBundle\Factory\MauticFactory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class CSIListType extends AbstractType
{
    private $model;

    /**
     * @param MauticFactory $factory
     */
    public function __construct(MauticFactory $factory)
    {
        $this->model = $factory->getModel('lead.list');
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        /** @var \Mautic\LeadBundle\Model\ListModel $model */
        $model = $this->model;
        $resolver->setDefaults([
            'choices' => function (Options $options) use ($model) {
                $lists = (empty($options['global_only'])) ? $model->getUserLists() : $model->getGlobalLists();

                $choices = [];

                foreach ($lists as $l) {
                    if (0 === strpos($l['alias'], 'csi-free-')) {
                        $choices[$l['id']] = $l['name'];
                    }
                }

                return $choices;
            },
            'global_only' => false,
            'required'    => false,
        ]);
    }

    /**
     * @return null|string|\Symfony\Component\Form\FormTypeInterface
     */
    public function getParent()
    {
        return 'choice';
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'csilist_choices';
    }
}
