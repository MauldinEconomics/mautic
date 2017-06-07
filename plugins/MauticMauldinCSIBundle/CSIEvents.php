<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle;

/**
 * Class CSIEvents
 * Events available for MauticMauldinCSIBundle.
 */
final class CSIEvents
{
    /**
     * The mauldin.click.on_campaign_trigger_decision event is fired when the campaign action triggers.
     *
     * The event listener receives a
     * Mautic\CampaignBundle\Event\CampaignExecutionEvent
     *
     * @var string
     */
    const ON_CAMPAIGN_TRIGGER_ACTION = 'mauldin.csi.on_campaign_trigger_decision';

    const ON_MODIFY_CSI_LIST = 'mauldin.csi.on_modify_csi_list';
}
