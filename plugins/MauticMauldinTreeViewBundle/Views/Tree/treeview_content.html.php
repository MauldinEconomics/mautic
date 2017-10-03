<?php
/**
 * Tab Content
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */
?>
    <div class="tab-pane fade in bdr-w-0" id="treeview-container">
        <?php echo trim($view->render('MauticMauldinTreeViewBundle:Tree:treeview.html.php', [
            'events' => $eventsInfo,
            'sources' => $sources,
            'leadStats' => $leadStats
        ])); ?>
    </div>
<?php ?>
