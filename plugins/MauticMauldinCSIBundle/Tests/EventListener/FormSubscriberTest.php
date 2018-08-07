<?php

/*
 * @package   Mautic CSI Bundle
 * @copyright Copyright(c) 2018 by GGC Publishing, LLC
 * @author    Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinCsiBundle\Tests\EventListener;

use MauticPlugin\MauticMauldinCsiBundle\CSIEvents;
use MauticPlugin\MauticMauldinCsiBundle\EventListener\FormSubscriber;
use MauticPlugin\MauticMauldinCsiBundle\Model\CSIListModel;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;


/**
 * Form Subscriber Test.
 */
class FormSubscriberTest extends KernelTestCase
{
    /** @var array */
    protected $leads;

    /** @var LeadModel */
    protected $leadModel;

    /** @var CSIListModel */
    protected $csiListModel;

    /** @var EventDispatcher */
    protected $dispatcher;

    /** @var FormSubscriber */
    protected $formSubscriber;

    /**
     * Set up.
     */
    public function setUp()
    {
        $this->leads          = [];
        $this->leadModel      = $this->createMock(LeadModel::class);
        $this->csiListModel   = $this->getMockCsiListModel();
        $this->dispatcher     = new EventDispatcher();
        $this->formSubscriber = new FormSubscriber($this->leadModel, $this->csiListModel);
        $this->dispatcher->addSubscriber($formSubscriber);
    }

    /**
     * Test that a lead with an email address does opt in.
     */
    public function testLeadWithEmailDoesOptIn()
    {
        $lead  = $this->getLeadWithEmail();
        $event = $this->getSubmissionEvent($lead);

        $this->dispatchEvent($event);
        $this->assertTrue($this->hasLeadInList($lead));
    }

    /**
     * Test that a lead without an email address does not opt in.
     */
    public function testLeadWithoutEmailDoesNotOptIn()
    {
        $lead  = $this->getLeadWithoutEmail();
        $event = $this->getSubmissionEvent($lead);

        $this->dispatchEvent($event);
        $this->assertFalse($this->hasLeadInList($lead));
    }

    /**
     * Get lead with email.
     *
     * @return Lead
     */
    public function getLeadWithEmail()
    {
        $lead = $this->createMock(Lead::class);
        $lead->expects($this->any())
            ->method('getEmail')
            ->willReturn('test@example.com');

        return $lead;
    }

    /**
     * Get lead without email.
     *
     * @return Lead
     */
    public function getLeadWithoutEmail()
    {
        $lead = $this->createMock(Lead::class);
        $lead->expects($this->any())
            ->method('getEmail')
            ->willReturn(null);

        return $lead;
    }

    /**
     * Get submission event.
     *
     * @param Lead $lead
     * @return SubmissionEvent
     */
    public function getSubmissionEvent(Lead $lead)
    {
        $event = $this->createMock(SubmissionEvent::class);

        $event->expects($this->any())
            ->method('getLead')
            ->willReturn($lead);

        $event->expects($this->any())
            ->method('getActionConfig')
            ->willReturn([
                'addToLists'      => ['list'],
                'removeFromLists' => [],
            ]);

        return $event;
    }

    /**
     * Add lead to list.
     *
     * @param Lead $lead
     * @param array $addTo
     */
    public function addLeadToList(Lead $lead, array $addTo)
    {
        $this->leads[] = $lead;
    }

    /**
     * Has lead in list.
     *
     * @param Lead $lead
     * @return bool
     */
    public function hasLeadInList(Lead $lead)
    {
        return in_array($lead, $this->leads);
    }

    /**
     * Get mock CSIListModel.
     *
     * @return CSIListModel
     */
    protected function getMockCsiListModel()
    {
        $csiList = $this->createMock(CSIListModel::class);

        $csiList->expects($this->any())
            ->method('addToList')
            ->will($this->returnCallback([$this, 'addLeadToList']));

        return $csiList;
    }

    /**
     * Dispatch event.
     *
     * @param SubmissionEvent $event
     */
    protected function dispatchEvent(SubmissionEvent $event)
    {
        $this->dispatcher->dispatch(CSIEvents::ON_MODIFY_CSI_LIST, $event);
    }

}
