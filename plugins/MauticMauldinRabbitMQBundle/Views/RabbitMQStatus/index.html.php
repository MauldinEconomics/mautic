<?php

/*
 * @package     Mauldin RabbitMQ
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

$view->extend('MauticCoreBundle:Default:content.html.php');
$view['slots']->set('headerTitle', $view['translator']->trans('mautic.rabbitmq.menu.index'));
?>

<div class="table-responsive panel-collapse pull out page-list">
    <table class="table table-hover table-striped table-bordered report-list" id="reportTable">
        <thead>
        <tr>
            <?php
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.name',
                    'class'      => 'col-report-name',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.autodelete',
                    'class'      => 'col-report-name',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.total',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.rate.in',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.rate.out',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.rate.net',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.rabbitmq.queue.tte',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <?php echo $item["pname"]; ?>
                </td>
                <td>
                    <?php echo $item["is_auto_delete"]; ?>
                </td>
                <td>
                    <?php echo $item["total"]; ?>
                </td>
                <td>
                    <?php echo $item["rate_in"]; ?>/s
                </td>
                <td>
                    <?php echo $item["rate_out"]; ?>/s
                </td>
                <td>
                    <?php echo $item["rate_net"]; ?>/s
                </td>
                <td>
                    <?php echo $item["time_to_empty"]; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
