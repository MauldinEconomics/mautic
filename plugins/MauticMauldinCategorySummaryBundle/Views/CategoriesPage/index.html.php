<?php

/*
 * @package     Mauldin Category Summary
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

$view->extend('MauticCoreBundle:Default:content.html.php');
?>

<div class="panel panel-default bdr-t-wdh-0 mb-0">
<?php
echo $view->render(
    'MauticCoreBundle:Helper:list_toolbar.html.php', [
        'action' => 'category-summary',
        'filters' => $filters, // filter on category
    ]);
?>

    <div class="page-list">
        <?php $view['slots']->output('_content'); ?>
    </div>
</div>

