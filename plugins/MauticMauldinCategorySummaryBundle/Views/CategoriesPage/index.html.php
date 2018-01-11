<?php

/*
 * @package     Mauldin Category Summary
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

$view->extend('MauticCoreBundle:Default:content.html.php');
?>

<div class="panel panel-default bdr-t-wdh-0 mb-0">
    <div class="page-list">
        <?php $view['slots']->output('_content'); ?>
    </div>
</div>
