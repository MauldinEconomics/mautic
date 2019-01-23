<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinContactExportBundle\Model;

use Mautic\CategoryBundle\Model\CategoryModel;
use Mautic\ChannelBundle\Helper\ChannelListHelper;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\RequestStack;

class LeadModel extends \Mautic\LeadBundle\Model\LeadModel
{
    public function __construct(
        RequestStack $requestStack,
        CookieHelper $cookieHelper,
        IpLookupHelper $ipLookupHelper,
        PathsHelper $pathsHelper,
        IntegrationHelper $integrationHelper,
        FieldModel $leadFieldModel,
        ListModel $leadListModel,
        FormFactory $formFactory,
        CompanyModel $companyModel,
        CategoryModel $categoryModel,
        ChannelListHelper $channelListHelper,
        $trackByIp

    ) {
        parent::__construct(
            $requestStack,
         $cookieHelper,
         $ipLookupHelper,
         $pathsHelper,
         $integrationHelper,
         $leadFieldModel,
         $leadListModel,
         $formFactory,
         $companyModel,
         $categoryModel,
         $channelListHelper,
            $trackByIp);
    }
    public function getLeadsPaginated($args)
    {
        $q = $this->getRepository()->createQueryBuilder('l');
        $this->getRepository()->convertOrmProperties($this->getRepository()->getClassName(), $args);
        $this->getRepository()->buildWhereClause($q, $args);
        $this->getRepository()->buildOrderByClause($q, $args);
        $this->getRepository()->buildLimiterClauses($q, $args);
        $results = $q->getQuery()->getResult();

        return $results;
    }
}
