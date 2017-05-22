<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 * @author      Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\DependencyInjection\Compiler\QueuedTransportPass;
use Swift_Preferences;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class MauticMauldinEmailScalabilityBundle extends PluginBundleBase
{
    public function boot()
    {
        Swift_Preferences::getInstance()->setCacheType('array');
    }

    /**
     * Build.
     *
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new QueuedTransportPass());
    }
}
