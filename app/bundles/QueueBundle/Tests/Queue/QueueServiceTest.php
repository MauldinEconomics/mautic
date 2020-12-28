<?php

/*
 * @copyright   2020 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\QueueBundle\Tests\Queue;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\QueueBundle\Queue\QueueConsumerResults;
use Mautic\QueueBundle\Queue\QueueService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class QueueServiceTest extends TestCase
{
    /**
     * @var CoreParametersHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $coreParametersHelper;

    /**
     * @var EventDispatcher|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventDispatcher;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var QueueService
     */
    private $queueService;

    protected function setUp(): void
    {
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->eventDispatcher      = $this->createMock(EventDispatcher::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->queueService = new QueueService(
            $this->coreParametersHelper,
            $this->eventDispatcher,
            $this->logger
        );
    }

    /**
     * Tests that the logger is called with QUEUE ERROR message, that the
     * event is not dispatched, and that the event result is REJECT.
     *
     * @dataProvider emptyPayloadProvider
     */
    public function testDispatchConsumerEventFromEmptyPayload($payload)
    {
        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('QUEUE ERROR: Skipped empty queue message');

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $this->assertEquals(
            QueueConsumerResults::REJECT,
            $this->queueService->dispatchConsumerEventFromPayload($payload)->getResult()
        );
    }

    public function emptyPayloadProvider()
    {
        return [
            'null'         => [null],
            'empty-string' => [''],
            'zero-string'  => ['0'],
            'zero'         => [0],
        ];
    }

    /**
     * Tests that the event is dispatched and the event result is not REJECT.
     *
     * @dataProvider nonemptyPayloadProvider
     */
    public function testDispatchConsumerEventFromNonemptyPayload($payload)
    {
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->withAnyParameters();

        $this->assertNotEquals(
            QueueConsumerResults::REJECT,
            $this->queueService->dispatchConsumerEventFromPayload($payload)->getResult()
        );
    }

    public function nonemptyPayloadProvider()
    {
        $payloads = [
            'email-hit' => [
                'mauticQueueName' => 'email_hit',
            ],
            'page-hit' => [
                'mauticQueueName' => 'page_hit',
            ],
            'page-hit-with-request' => [
                'mauticQueueName' => 'page_hit',
                'request' => [
                    'attributes' => [],
                    'request'    => [],
                    'query'      => [],
                    'cookies'    => [],
                    'files'      => [],
                    'server'     => [],
                    'headers'    => [],
                ],
            ],
        ];

        foreach ($payloads as &$payload) {
            $payload = [json_encode($payload)];
        }

        return $payloads;
    }
}
