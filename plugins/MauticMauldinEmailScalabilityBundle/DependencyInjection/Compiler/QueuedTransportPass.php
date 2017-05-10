<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\DependencyInjection\Compiler;

use MauticPlugin\MauticMauldinEmailScalabilityBundle\Helper\QueuedMailHelper;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Model\QueuedEmailModel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Queued Transport Pass.
 */
class QueuedTransportPass implements CompilerPassInterface
{
    /**
     * Process.
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $emailModel = $container->getDefinition('mautic.email.model.email');
        $emailModel->setClass(QueuedEmailModel::class);

        $mailHelper = $container->getDefinition('mautic.helper.mailer');
        $mailHelper->setClass(QueuedMailHelper::class);
    }
}
