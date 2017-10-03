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

<div class="page-header">
    <div class="box-layout">
        <div class="col-xs-5 col-sm-6 col-md-5 va-m">
        <h3 class="pull-left"><?php echo $view['translator']->trans('mautic.emailsendlog.section_header'); ?></h3>
            <div class="col-xs-2 text-right pull-left"> </div>
        </div>
    </div>
</div>
<div class="table-responsive panel-collapse pull out page-list">
    <table class="table table-hover table-striped table-bordered report-list" id="reportTable">
        <thead>
        <tr>
            <?php
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.emailsendlog.email_id',
                    'class'      => 'col-report-name',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.emailsendlog.email_name',
                    'class'      => 'col-report-name',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.emailsendlog.send_count',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.emailsendlog.still_in_queue',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            echo $view->render(
                'MauticCoreBundle:Helper:tableheader.html.php',
                [
                    'sessionVar' => 'report',
                    'text'       => 'mautic.emailsendlog.last_send_date',
                    'class'      => 'col-report-id visible-md visible-lg',
                ]);
            ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($sendLogs as $sendLog): ?>
            <tr>
                <td>
                    <?php echo $sendLog["email_id"]; ?>
                </td>
                <td>
                    <?php echo $sendLog["email_name"]; ?>
                </td>
                <td>
                    <?php echo $sendLog["send_count"]; ?>
                </td>
                <td>
                    <?php echo $sendLog["queued_count"] - $sendLog["send_count"]; ?>
                </td>
                <td>
                    <?php echo $sendLog["last_send_date"]; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
