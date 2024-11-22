<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\BehavioralTests\TestSuite\DebugEventProjection;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Flow\Core\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Just a test to check our mock is working {@see DebugEventProjection}
 */
class DebugEventProjectionTest extends TestCase
{
    /** @test */
    public function fakeProjectionRejectsDuplicateEvents()
    {
        $debugProjection = new DebugEventProjection(
            'test_debug_projection',
            Bootstrap::$staticObjectManager->get(Connection::class)
        );

        $debugProjection->setUp();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $fakeEventEnvelope = new EventEnvelope(
            new Event(
                Event\EventId::create(),
                Event\EventType::fromString('ContentStreamWasCreated'),
                Event\EventData::fromString(json_encode(['contentStreamId' => 'cs-id']))
            ),
            ContentStreamEventStreamName::fromContentStreamId(ContentStreamId::fromString('cs-id'))->getEventStreamName(),
            Event\Version::first(),
            SequenceNumber::fromInteger(1),
            new \DateTimeImmutable()
        );

        $debugProjection->apply(
            $this->getMockBuilder(EventInterface::class)->getMock(),
            $fakeEventEnvelope
        );

        $debugProjection->apply(
            $this->getMockBuilder(EventInterface::class)->getMock(),
            $fakeEventEnvelope
        );
    }
}
