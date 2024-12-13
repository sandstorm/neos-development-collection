<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Tests\Unit\Subscription;

use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFailed;
use Neos\ContentRepository\Core\Subscription\Engine\Error;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Exception\CatchUpHadErrors;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use PHPUnit\Framework\TestCase;

class CatchUpHadErrorsTest extends TestCase
{
    public function testSimple()
    {
        $exception = new \RuntimeException('This catchup hook is kaputt.');

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "onBeforeCatchup" failed: "SomeHook": This catchup hook is kaputt.',
            1733243960,
            $exception
        );

        $errors = Errors::fromArray([
            Error::create(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException->getMessage(), $expectedWrappedException),
        ]);

        // assert shape if the error had been thrown by the cr:
        $catchupHadErrors = CatchUpHadErrors::createFromErrors($errors);
        self::assertEquals($expectedWrappedException, $catchupHadErrors->getPrevious());
        self::assertEquals('Exception while catching up: "Vendor.Package:SecondFakeProjection": Hook "onBeforeCatchup" failed: "SomeHook": This catchup hook is kaputt.', $catchupHadErrors->getMessage());
    }

    public function testWithTwoHookErrors()
    {
        $exception = new \RuntimeException('This catchup hook is kaputt.');

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "onBeforeEvent" failed: "SomeHook": This catchup hook is kaputt.',
            1733243960,
            $exception
        );

        $errors = Errors::fromArray([
            Error::create(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException->getMessage(), $expectedWrappedException),
            Error::create(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException->getMessage(), null),
        ]);

        // assert shape if the error had been thrown by the cr:
        $catchupHadErrors = CatchUpHadErrors::createFromErrors($errors);
        self::assertEquals($expectedWrappedException, $catchupHadErrors->getPrevious());
        self::assertEquals('Exception while catching up: "Vendor.Package:SecondFakeProjection": Hook "onBeforeEvent" failed: "SomeHook": This catchup hook is kaputt.;
"Vendor.Package:SecondFakeProjection": Hook "onBeforeEvent" failed: "SomeHook": This catchup hook is kaputt.', $catchupHadErrors->getMessage());
    }

    public function testWith10Errors()
    {
        $exception = new \RuntimeException('Message why A failed');
        $errors = Errors::fromArray([
            Error::create(SubscriptionId::fromString('Vendor.Package:A'), $exception->getMessage(), $exception),
            Error::create(SubscriptionId::fromString('Vendor.Package:B'), 'Message why B failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:C'), 'Message why C failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:D'), 'Message why D failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:E'), 'Message why E failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:F'), 'Message why F failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:G'), 'Message why G failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:H'), 'Message why H failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:I'), 'Message why I failed', null),
            Error::create(SubscriptionId::fromString('Vendor.Package:J'), 'Message why J failed', null),
        ]);

        // assert shape if the error had been thrown by the cr:
        $catchupHadErrors = CatchUpHadErrors::createFromErrors($errors);
        self::assertEquals($exception, $catchupHadErrors->getPrevious());
        self::assertEquals(<<<MSG
        Exception while catching up: "Vendor.Package:A": Message why A failed;
        "Vendor.Package:B": Message why B failed;
        "Vendor.Package:C": Message why C failed;
        "Vendor.Package:D": Message why D failed;
        "Vendor.Package:E": Message why E failed;
        And 5 other exceptions, see log.
        MSG, $catchupHadErrors->getMessage());
    }
}
