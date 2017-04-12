<?php

/*
 * @package     Mauldin Clicks
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinClicksBundle;

/**
 * Class EmailEvents
 * Events available for EmailBundle.
 */
final class MauticMauldinEvents
{
    const EMAIL_ON_CLICK_LINK= 'mautic.email_on_click_link';

    /**
     * The mautic.email.on_campaign_trigger_decision event is fired when the campaign action triggers.
     *
     * The event listener receives a
     * Mautic\CampaignBundle\Event\CampaignExecutionEvent
     *
     * @var string
     */
    const ON_CAMPAIGN_TRIGGER_DECISION = 'mautic.email.on_campaign_trigger_decision';
}
