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
use Symfony\Component\DependencyInjection\Reference;

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
        $emailModel->addMethodCall('setChannelHelper', [new Reference('mauldin.scalability.message_queue.channel_helper')]);
        $emailModel->addMethodCall('setNotificationModel', [new Reference('mautic.core.model.notification')]);

        $mailHelper = $container->getDefinition('mautic.helper.mailer');
        $mailHelper->setClass(QueuedMailHelper::class);
    }
}
