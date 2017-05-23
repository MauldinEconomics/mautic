<?php
/*
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 *
 * Based on "events.html.php" from Mautic campaign view files
 */
?>

<style>
    .ul_header {
        font-size:120%;
        font-weight:bold;
        margin-bottom: 0;
    }

    .lb-sm {
        font-size: 12px;
    }
</style>

<!-- Sources -->
<?php if (!empty($sources) && is_array($sources)) : ?>
<?php $typeToName = ['lists' => 'Segments', 'forms' => 'Forms']?>
<p class="ul_header">Sources</p>
<ul class="list-group campaign-event-list">
    <?php foreach ($sources as $sourceType => $listOfSources) : ?>
        <?php if (count($listOfSources) > 0) : ?>
            <li class="list-group-item bg-auto bg-light-xs">
                <div class="box-layout">
                    <div class="col-md-4 va-m">
                        <h3>
                            <?php echo $typeToName[$sourceType]; ?>
                        </h3>
                    </div>
                    <div class="col-md-8 va-m text-right">
                        <span class="h5 fw-sb text-primary mb-xs">
                            <?php echo implode(",", $listOfSources); ?>
                        </span>
                    </div>
                </div>
            </li>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<!-- Events -->
<?php if (!empty($events) && is_array($events)) : ?>
<p class="ul_header">Campaign
    <span class="va-m">
        <small class="fw-sb text-primary mb-xs">
            Current: <?php echo $leadStats['leadCount']; ?>
            Opted-out: <?php echo($leadStats['overallLeadCount'] - $leadStats['leadCount']); ?>
        </small>
    </span>
</p>
<ul class="list-group campaign-event-list">
    <?php foreach ($events as $event) : ?>
        <li class="list-group-item bg-auto bg-light-xs">
            <div class="progress-bar progress-bar-success" style="width:<?php echo $event['percent']; ?>%"></div>
            <div class="box-layout">
                <?php
                    $margin = 20 * $event['depth'];
                ?>
                <div class="col-md-8 va-m">
                    <h3 style="<?php echo "margin-left:".($margin)."px;"; ?>" >
                        <?php if (!is_null($event['decisionPath'])) : ?>
                            <?php if ($event['decisionPath'] == 'yes') : ?>
                                <span class="label label-success lb-sm"><?php echo $event['decisionPath']?></span>
                            <?php else : ?>
                                <span class="label label-danger lb-sm"><?php echo $event['decisionPath']?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($event['eventType'] == 'decision') : ?>
                            <span class="fa fa-bullseye text-danger"></span>
                        <?php elseif ($event['eventType'] == 'condition') : ?>
                            <span class="fa fa fa-share-alt text-danger"></span>
                        <?php else : ?>
                            <span class="fa fa-rocket text-success"></span>
                        <?php endif; ?>
                        <span class="h5 fw-sb text-primary mb-xs">
                            <?php echo $event['name']; ?>
                            <small><?php echo $event['percent']; ?> % <?php echo "(".$event['logCount'].")"; ?></small>
                        </span>
                    </h3>
                    <h6 style="<?php echo "margin-left:".($margin+5)."px;"; ?>" class="text-white dark-sm"><?php echo isset($event['description']) ? $event['description']:''; ?></h6>

                </div>
                <div class="col-md-4 va-m text-right">
                    <em class="text-white dark-sm"><?php echo $view['translator']->trans('mautic.campaign.'.$event['type']); ?></em>
                </div>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
