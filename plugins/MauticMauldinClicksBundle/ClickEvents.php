<?php

/*
 * @package     Mauldin Clicks
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinClicksBundle;

/**
 * Class ClickEvents
 * Events available for MauticMauldinClicksBundle.
 */
final class ClickEvents
{
    const EMAIL_ON_CLICK_LINK = 'mauldin.email_on_click_link';

    /**
     * The mauldin.click.on_campaign_trigger_decision event is fired when the campaign action triggers.
     *
     * The event listener receives a
     * Mautic\CampaignBundle\Event\CampaignExecutionEvent
     *
     * @var string
     */
    const ON_CAMPAIGN_TRIGGER_DECISION = 'mauldin.click.on_campaign_trigger_decision';
}
