<?php

/*
 * @package     Mauldin Category Summary
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCategorySummaryBundle\Controller;

use Mautic\CoreBundle\Controller\AbstractStandardFormController;
use Mautic\CoreBundle\Helper\InputHelper;

class DefaultController extends AbstractStandardFormController {

    public function indexAction($page = 0) {
        // check for permission errors
        $validation = $this->checkForPermissionError();
        if ($validation["status"] === "error") {
            return $validation["error"];
        }
        $permissions = $validation["perm"];

        $session = $this->get('session');
        $this->processUiFilterSettings($session);

        // category filter
        $filter = [
            'string' => '',
            'force' => [],
        ];

        if (0 !== $page) {
            $filter['force'][] = ['column' => 'c.id', 'expr' => 'eq', 'value' => $page];
        }

        list($emailItems, $emailModel) = $this->getEmailItems($filter, $session, $permissions);
        $landingPageItems = $this->getLandingPageItems($filter, $session, $permissions);
        $campaignItems = $this->getCampaignItems($filter, $session, $permissions);
        $formItems = $this->getFormItems($filter, $session, $permissions);
        $assetItems = $this->getAssetItems($filter, $session, $permissions);

        return $this->delegateView([
            'viewParameters' => [
                'tmpl' => $this->request->get('tmpl', 'index'),
                'permissions' => $permissions,
                'emailItems' => $emailItems,
                'emailModel' => $emailModel,
                'campaignItems' => $campaignItems,
                'formItems' => $formItems,
                'pageItems' => $landingPageItems,
                'assetItems' => $assetItems,
                'page' => $page,
            ],
            'contentTemplate' => 'MauticMauldinCategorySummaryBundle:CategoriesPage:list.html.php',
        ]);
    }

    protected function processUiFilterSettings($session) {
        if ($this->request->getMethod() == 'POST') {
            $name = $this->request->get("name", "");
            $dir = "";
            $hasUpdated = false;
            if ($this->request->query->has('orderby')) {
                $orderBy = InputHelper::clean($this->request->query->get('orderby'), true);
                $dir     = $session->get("mautic.$name.orderbydir", 'ASC');
                $dir     = ($dir == 'ASC') ? 'DESC' : 'ASC';
                list($entity, $column) = explode('.', $orderBy);

                if(in_array($entity, ['c', 'cat']) && $column == "title"){
                    $session->set("mautic.campaign.orderby",    'cat.title');
                    $session->set("mautic.form.orderby",        'c.title');
                    $session->set("mautic.page.orderby",        'c.title');
                    $session->set("mautic.email.orderby",       'c.title');
                    $session->set("mautic.asset.orderby",       'c.title');
                    $hasUpdated = true;
                } else if (in_array($column, ['name', 'title'])) {
                    $session->set("mautic.campaign.orderby",    'c.name');
                    $session->set("mautic.form.orderby",        'f.name');
                    $session->set("mautic.page.orderby",        'p.title');
                    $session->set("mautic.email.orderby",       'e.name');
                    $session->set("mautic.asset.orderby",       'a.title');
                    $hasUpdated = true;
                } else if($column == "id" ){
                    $session->set("mautic.campaign.orderby",    'c.id');
                    $session->set("mautic.form.orderby",        'f.id');
                    $session->set("mautic.page.orderby",        'p.id');
                    $session->set("mautic.email.orderby",       'e.id');
                    $session->set("mautic.asset.orderby",       'a.id');
                    $hasUpdated = true;
                }
            }
            $this->setListFilters($name);
            if($hasUpdated){
                $entities = ["campaign", 'form', 'page', 'email', 'asset'];
                foreach($entities as $e){
                    $session->set("mautic.$e.orderbydir", $dir);
                }
            }
        }
    }

    protected function getCampaignItems($filter, $session, $permissions) {
        // fix categories table alias
        if (! empty($filter['force'])) {
            $filter['force'][0]['column'] = 'cat.id';
        }

        if (!$permissions['campaign:campaigns:viewother']) {
            $filter['force'][] = ['column' => 'e.createdBy', 'expr' => 'eq', 'value' => $this->user->getId()];
        }

        $model = $this->getModel('campaign');
        $repo = $model->getRepository();

        $orderBy = $session->get('mautic.campaign.orderby', $repo->getTableAlias() . '.name');
        $orderByDir = $session->get('mautic.campaign.orderbydir', 'ASC');

        $items = $model->getEntities([
                'filter' => $filter,
                'orderBy' => $orderBy,
                'orderByDir' => $orderByDir,
            ]);

        return $items;
    }

    protected function getFormItems($filter, $session, $permissions) {
        if (!$permissions['form:forms:viewother']) {
            $filter['force'][] = ['column' => 'e.createdBy', 'expr' => 'eq', 'value' => $this->user->getId()];
        }

        $model = $this->getModel('form');
        $repo = $model->getRepository();

        $orderBy = $session->get('mautic.form.orderby', $repo->getTableAlias() . '.name');
        $orderByDir = $session->get('mautic.form.orderbydir', 'ASC');

        $items = $model->getEntities([
            'filter' => $filter,
            'orderBy' => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        return $items;
    }

    protected function getLandingPageItems($filter, $session, $permissions) {
        $model = $this->getModel('page.page');

        if (!$permissions['page:pages:viewother']) {
            $filter['force'][] = ['column' => 'p.createdBy', 'expr' => 'eq', 'value' => $this->user->getId()];
        }
        $filter['force'][] = ['column' => 'p.variantParent', 'expr' => 'isNull'];
        $langSearchCommand = $this->get('translator')->trans('mautic.core.searchcommand.lang');
        if (strpos($filter['string'], "{$langSearchCommand}:") === false) {
            $filter['force'][] = ['column' => 'p.translationParent', 'expr' => 'isNull'];
        }

        $orderBy = $this->get('session')->get('mautic.page.orderby', 'p.title');
        $orderByDir = $this->get('session')->get('mautic.page.orderbydir', 'DESC');
        return $model->getEntities([
            'filter' => $filter,
            'orderBy' => $orderBy,
            'orderByDir' => $orderByDir,
        ]);
    }

    public function getEmailItems($filter, $session, $permissions) {
        if (!$permissions['email:emails:viewother']) {
            $filter['force'][] = ['column' => 'e.createdBy', 'expr' => 'eq', 'value' => $this->user->getId()];
        }
        $filter['force'][] = ['column' => 'e.variantParent', 'expr' => 'isNull'];
        $filter['force'][] = ['column' => 'e.translationParent', 'expr' => 'isNull'];

        $model = $this->getModel('email');
        $orderBy = $session->get('mautic.email.orderby', 'e.subject');
        $orderByDir = $session->get('mautic.email.orderbydir', 'DESC');
        $emails = $model->getEntities([
            'filter' => $filter,
            'orderBy' => $orderBy,
            'orderByDir' => $orderByDir,
            'ignoreListJoin' => true,
        ]);

        return array($emails, $model);
    }

    protected function getAssetItems($filter, $session, $permissions) {
        $model = $this->getModel('asset');

        if (!$permissions['asset:assets:viewother']) {
            $filter['force'][] =
                ['column' => 'a.createdBy', 'expr' => 'eq', 'value' => $this->user->getId()];
        }

        $orderBy    = $this->get('session')->get('mautic.asset.orderby', 'a.title');
        $orderByDir = $this->get('session')->get('mautic.asset.orderbydir', 'DESC');

        $assets = $model->getEntities([
            'filter'     => $filter,
            'orderBy'    => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        return $assets;
    }

    protected function getModelName(){
        return "summary-view-controller";
    }

    protected function checkForPermissionError() {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted([
            'email:emails:viewown',
            'email:emails:viewother',
            'page:pages:viewown',
            'page:pages:viewother',
            'campaign:campaigns:viewown',
            'campaign:campaigns:viewother',
            'form:forms:viewown',
            'form:forms:viewother',
            'asset:assets:viewown',
            'asset:assets:viewother',
        ], 'RETURN_ARRAY');

        if ((!$permissions['email:emails:viewown']          && !$permissions['email:emails:viewother'])         ||
            (!$permissions['page:pages:viewown']            && !$permissions['page:pages:viewother'])           ||
            (!$permissions['campaign:campaigns:viewown']    && !$permissions['campaign:campaigns:viewother'])   ||
            (!$permissions['form:forms:viewown']            && !$permissions['form:forms:viewother'])           ||
            (!$permissions['asset:assets:viewown']          && !$permissions['asset:assets:viewother'])) {
            return ["status" => "error", "error" => $this->accessDenied()];
        }

        return ["status" => "ok", "perm" => $permissions];
    }

}