<?php

/*
 * @package     Mauldin Categories
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

return [
    'routes' => [
        'main' => [
            'mautic_category_summary_index' => [
                'path' => '/category/items/{page}',
                'controller' => 'MauticMauldinCategorySummaryBundle:Default:index',
            ],
        ],
    ],
    'menu' => [
        'main' => [
            'mautic.categorySummary.menu.index' => [
                'route' => 'mautic_category_index',
                'iconClass' => 'fa-folder',
                'priority' => -1,
            ],
        ],
    ],
];
