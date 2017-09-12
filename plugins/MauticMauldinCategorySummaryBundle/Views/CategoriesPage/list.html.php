<?php

/*
 * @package     Mauldin Category Summary
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

if ($tmpl == 'index') {
    $view->extend('MauticMauldinCategorySummaryBundle:CategoriesPage:index.html.php');
}
?>
<div class="page-header">
    <div class="box-layout">
        <div class="col-xs-5 col-sm-6 col-md-5 va-m">
            <h3 class="pull-left">Campaigns</h3>
            <div class="col-xs-2 text-right pull-left"> </div>
        </div>
    </div>
</div>
<div class="table-responsive">
<table class="table table-hover table-striped table-bordered campaign-list" id="campaignTable">
    <thead>
        <tr>
<?php
echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'campaign',
        'text' => 'mautic.core.name',
        'orderBy' => 'c.name',
        'class' => 'col-campaign-name',
        'default' => true,
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'campaign',
        'text' => 'mautic.core.category',
        'orderBy' => 'cat.title',
        'class' => 'visible-md visible-lg col-campaign-category',
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'campaign',
        'orderBy'    => 'c.id',
        'text'       => 'mautic.core.id',
        'class'      => 'visible-md visible-lg col-campaign-id',
    ]
);
?>
        </tr>
    </thead>
    <tbody>
<?php foreach ($campaignItems as $item): ?>
        <?php $mauticTemplateVars['item'] = $item; ?>
        <tr>
            <td>
                <div>
                <a href="<?php
echo $view['router']->path(
    'mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $item->getId()]
);
?>" data-toggle="ajax">
                    <?php echo $item->getName(); ?>
                       <?php echo $view['content']->getCustomContent('campaign.name', $mauticTemplateVars); ?>
                    </a>
                </div>
            </td>
            <td class="visible-md visible-lg">
<?php $category = $item->getCategory(); ?>
                <?php $catName = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
                <?php $color = ($category) ? '#' . $category->getColor() : 'inherit'; ?>
                <span style="white-space: nowrap;"><span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span></span>
                </td>
            <td class="visible-md visible-lg"><?php echo $item->getId(); ?></td>
            </tr>
<?php endforeach; ?>
    </tbody>
</table>

<div class="page-header">
    <div class="box-layout">
        <div class="col-xs-5 col-sm-6 col-md-5 va-m">
            <h3 class="pull-left">Forms</h3>
            <div class="col-xs-2 text-right pull-left"> </div>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover table-striped table-bordered" id="formTable">
        <thead>
            <tr>
<?php

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'form',
        'orderBy' => 'f.name',
        'text' => 'mautic.core.name',
        'class' => 'col-form-name',
        'default' => true,
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'form',
        'orderBy' => 'c.title',
        'text' => 'mautic.core.category',
        'class' => 'visible-md visible-lg col-form-category',
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'form',
        'orderBy' => 'submission_count',
        'text' => 'mautic.form.form.results',
        'class' => 'visible-md visible-lg col-form-submissions',
    ]
);
echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'form',
        'orderBy'    => 'f.id',
        'text'       => 'mautic.core.id',
        'class'      => 'visible-md visible-lg col-form-id',
    ]
);

?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($formItems as $i): ?>
                <?php $item = $i[0]; ?>
                <tr>
                    <td>
                        <div>

                        <a href="<?php
echo $view['router']->path(
    'mautic_form_action', ['objectAction' => 'view', 'objectId' => $item->getId()]
);
?>" data-toggle="ajax" data-menu-link="mautic_form_index">
<?php echo $item->getName(); ?>
<?php if ($item->getFormType() == 'campaign'): ?>
    <span data-toggle="tooltip" title="<?php
echo $view['translator']->trans(
    'mautic.form.icon_tooltip.campaign_form'
);
?>"><i class="fa fa-fw fa-cube"></i></span>
                            <?php endif; ?>
                            </a>
                            </div>
                        </td>
                    <td class="visible-md visible-lg">
                            <?php $category = $item->getCategory(); ?>
                            <?php $catName = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
                        <?php $color = ($category) ? '#' . $category->getColor() : 'inherit'; ?>
                        <span style="white-space: nowrap;"><span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span></span>
                    </td>
                    <td class="visible-md visible-lg">
                    <a href="<?php
echo $view['router']->path(
    'mautic_form_action', ['objectAction' => 'results', 'objectId' => $item->getId()]
);
?>" data-toggle="ajax" data-menu-link="mautic_form_index" class="btn btn-primary btn-xs" <?php echo ($i['submission_count'] == 0) ? 'disabled=disabled' : '';
?>>
<?php
echo $view['translator']->transChoice(
    'mautic.form.form.viewresults', $i['submission_count'], ['%count%' => $i['submission_count']]
);
?>
                        </a>
                        </td>
                <td class="visible-md visible-lg"><?php echo $item->getId(); ?></td>
                    </tr>
                <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="page-header">
    <div class="box-layout">
        <div class="col-xs-5 col-sm-6 col-md-5 va-m">
            <h3 class="pull-left">Landing Pages</h3>
            <div class="col-xs-2 text-right pull-left"> </div>
        </div>
    </div>
</div>
<div class="table-responsive page-list">
    <table class="table table-hover table-striped table-bordered pagetable-list" id="pageTable">
        <thead>
            <tr>
<?php
echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'page',
        'orderBy' => 'p.title',
        'text' => 'mautic.core.title',
        'class' => 'col-page-title',
        'default' => true,
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'page',
        'orderBy' => 'c.title',
        'text' => 'mautic.core.category',
        'class' => 'visible-md visible-lg col-page-category',
    ]
);
echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'page',
        'orderBy'    => 'p.hits',
        'text'       => 'mautic.page.thead.hits',
        'class'      => 'col-page-hits visible-md visible-lg',
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'page',
        'orderBy'    => 'p.id',
        'text'       => 'mautic.core.id',
        'class'      => 'col-page-id visible-md visible-lg',
    ]
);
?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pageItems as $item): ?>
                <tr>
                <td>
                <a href="<?php
echo $view['router']->path(
    'mautic_page_action', ['objectAction' => 'view', 'objectId' => $item->getId()]
);
?>" data-toggle="ajax">
<?php echo $item->getTitle(); ?> (<?php echo $item->getAlias(); ?>)
<?php
$hasVariants = $item->isVariant();
$hasTranslations = $item->isTranslation();

if ($hasVariants || $hasTranslations):
?>
                                <span>
                                <?php if ($hasVariants): ?>
                                        <span data-toggle="tooltip" title="<?php echo $view['translator']->trans('mautic.core.icon_tooltip.ab_test'); ?>">
                                            <i class="fa fa-fw fa-sitemap"></i>
                                        </span>
                                <?php endif; ?>
                                    <?php if ($hasTranslations): ?>
                                        <span data-toggle="tooltip" title="<?php
    echo $view['translator']->trans(
        'mautic.core.icon_tooltip.translation'
    );
?>">
                                            <i class="fa fa-fw fa-language"></i>
                                        </span>
                                    <?php endif; ?>
                                </span>
<?php endif; ?>
                        </a>
                    </td>
                    <td class="visible-md visible-lg">
                            <?php $category = $item->getCategory(); ?>
<?php $catName = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
<?php $color = ($category) ? '#' . $category->getColor() : 'inherit'; ?>
                        <span style="white-space: nowrap;"><span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span></span>
                        </td>
                <td class="visible-md visible-lg"><?php echo $item->getHits(); ?></td>
                <td class="visible-md visible-lg"><?php echo $item->getId(); ?></td>
                </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="page-header">
    <div class="box-layout">
        <div class="col-xs-5 col-sm-6 col-md-5 va-m">
            <h3 class="pull-left">Emails</h3>
            <div class="col-xs-2 text-right pull-left"> </div>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover table-striped table-bordered email-list">
        <thead>
            <tr>
<?php
echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'email',
        'orderBy' => 'e.name',
        'text' => 'mautic.core.name',
        'class' => 'col-email-name',
        'default' => true,
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php', [
        'sessionVar' => 'email',
        'orderBy' => 'c.title',
        'text' => 'mautic.core.category',
        'class' => 'visible-md visible-lg col-email-category',
    ]
);
?>

            <th class="visible-sm visible-md visible-lg col-email-stats"><?php echo $view['translator']->trans('mautic.core.stats'); ?></th>

<?php
echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'email',
        'orderBy'    => 'e.id',
        'text'       => 'mautic.core.id',
        'class'      => 'visible-md visible-lg col-email-id',
    ]
);
?>

            </tr>
        </thead>
        <tbody>
<?php foreach ($emailItems as $item): ?>
<?php
$hasVariants = $item->isVariant();
$hasTranslations = $item->isTranslation();
$type = $item->getEmailType();
?>
            <tr>
                    <td>
                            <div>
                            <a href="<?php
echo $view['router']->path(
    'mautic_email_action', ['objectAction' => 'view', 'objectId' => $item->getId()]
);
?>" data-toggle="ajax">
<?php echo $item->getName(); ?>
<?php if ($hasVariants): ?>
                                    <span data-toggle="tooltip" title="<?php echo $view['translator']->trans('mautic.core.icon_tooltip.ab_test'); ?>">
                                        <i class="fa fa-fw fa-sitemap"></i>
                                    </span>
                            <?php endif; ?>
                            <?php if ($hasTranslations): ?>
                                <span data-toggle="tooltip" title="<?php
echo $view['translator']->trans(
    'mautic.core.icon_tooltip.translation'
);
?>">
                                        <i class="fa fa-fw fa-language"></i>
                                    </span>
                                <?php endif; ?>
                                <?php if ($type == 'list'): ?>
                                    <span data-toggle="tooltip" title="<?php
echo $view['translator']->trans(
    'mautic.email.icon_tooltip.list_email'
);
?>">
                                        <i class="fa fa-fw fa-pie-chart"></i>
                                    </span>
                                <?php endif; ?>
                                      <?php echo $view['content']->getCustomContent('email.name', $mauticTemplateVars); ?>
                            </a>
                            </div>
                        </td>
                    <td class="visible-md visible-lg">
                        <?php $category = $item->getCategory(); ?>
                        <?php $catName = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
<?php $color = ($category) ? '#' . $category->getColor() : 'inherit'; ?>
                        <span style="white-space: nowrap;">
                            <span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span>
                        </span>
                        </td>

                <td class="visible-sm visible-md visible-lg col-stats">
                    <?php $queued = $emailModel->getQueuedCounts($item); ?>
                    <?php if (!empty($queued)): ?>
                    <span class="mt-xs label label-default"
                          data-toggle="tooltip"
                          title="<?php echo $view['translator']->trans('mautic.email.stat.queued.tooltip'); ?>">
<?php echo $view['translator']->trans(
    'mautic.email.stat.queued',
    ['%count%' => $queued]
); ?>
                    </span>
                    <?php endif; ?>
                    <span class="mt-xs label label-warning">
<?php echo $view['translator']->trans(
    'mautic.email.stat.sentcount',
    ['%count%' => $item->getSentCount(true)]
); ?>
                    </span>
                    <span class="mt-xs label label-success">
<?php echo $view['translator']->trans(
    'mautic.email.stat.readcount',
    ['%count%' => $item->getReadCount(true)]
); ?>
                    </span>
                    <span class="mt-xs label label-primary">
<?php echo $view['translator']->trans(
    'mautic.email.stat.readpercent',
    ['%count%' => $item->getReadPercentage(true)]
); ?>
                    </span>
                    <?php echo $view['content']->getCustomContent('email.stats', $mauticTemplateVars); ?>
                </td>
                <td class="visible-md visible-lg"><?php echo $item->getId(); ?></td>
                    </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="page-header">
    <div class="box-layout">
        <div class="col-xs-5 col-sm-6 col-md-5 va-m">
            <h3 class="pull-left">Assets</h3>
            <div class="col-xs-2 text-right pull-left"> </div>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover table-striped table-bordered asset-list" id="assetTable">
        <thead>
        <tr>
<?php

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'asset',
        'orderBy'    => 'a.title',
        'text'       => 'mautic.core.title',
        'class'      => 'col-asset-title',
        'default'    => true,
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'asset',
        'orderBy'    => 'c.title',
        'text'       => 'mautic.core.category',
        'class'      => 'visible-md visible-lg col-asset-category',
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'asset',
        'orderBy'    => 'a.downloadCount',
        'text'       => 'mautic.asset.asset.thead.download.count',
        'class'      => 'visible-md visible-lg col-asset-download-count',
    ]
);

echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
        'sessionVar' => 'asset',
        'orderBy'    => 'a.id',
        'text'       => 'mautic.core.id',
        'class'      => 'visible-md visible-lg col-asset-id',
    ]
);
?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($assetItems as $k => $item): ?>
            <tr>
                <td>
                    <div>
                    <a href="<?php echo $view['router']->path(
                        'mautic_asset_action',
                        ['objectAction' => 'view', 'objectId' => $item->getId()]
                    ); ?>"
                           data-toggle="ajax">
                            <?php echo $item->getTitle(); ?> (<?php echo $item->getAlias(); ?>)
                        </a>
                        <i class="<?php echo $item->getIconClass(); ?>"></i>
                    </div>
                </td>
                <td class="visible-md visible-lg">
                    <?php $category = $item->getCategory(); ?>
                    <?php $catName  = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
                    <?php $color    = ($category) ? '#'.$category->getColor() : 'inherit'; ?>
                    <span style="white-space: nowrap;"><span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span></span>
                </td>
                <td class="visible-md visible-lg"><?php echo $item->getDownloadCount(); ?></td>
                <td class="visible-md visible-lg"><?php echo $item->getId(); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
