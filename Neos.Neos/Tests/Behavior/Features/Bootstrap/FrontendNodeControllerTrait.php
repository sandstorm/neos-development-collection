<?php

declare(strict_types=1);

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Behat\Gherkin\Node\PyStringNode;
use GuzzleHttp\Psr7\Message;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\CRTestSuiteRuntimeVariables;
use Neos\Fusion\Core\Cache\ContentCache;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;

/**
 * @internal only for behat tests within the Neos.Neos package
 */
trait FrontendNodeControllerTrait
{
    use CRTestSuiteRuntimeVariables;

    private ResponseInterface|null $frontendNodeControllerResponse = null;

    /**
     * @template T of object
     * @param class-string<T> $className
     *
     * @return T
     */
    abstract private function getObject(string $className): object;

    /**
     * @BeforeScenario
     */
    public function setupFrontendNodeControllerTrait(): void
    {
        $this->getObject(ContentCache::class)->flush();
        $this->getObject(\Neos\Neos\Testing\TestingFusionAutoIncludeHandler::class)->reset();
        $this->frontendNodeControllerResponse = null;
    }

    /**
     * @When the Fusion code for package :package is:
     */
    public function iHaveTheFollowingFusionCodeForTheSite(PyStringNode $fusionCode, string $package)
    {
        $testingFusionHandler = $this->getObject(\Neos\Neos\Testing\TestingFusionAutoIncludeHandler::class);
        $testingFusionHandler->setFusionForPackage($package, \Neos\Fusion\Core\FusionSourceCodeCollection::fromString($fusionCode->getRaw()));
    }

    /**
     * @When I dispatch the following request :requestUri
     */
    public function iDispatchTheFollowingRequest(string $requestUri)
    {
        $testingFusionHandler = $this->getObject(\Neos\Neos\Testing\TestingFusionAutoIncludeHandler::class);
        $testingFusionHandler->setIncludeFusionPackage('Neos.Fusion');
        $testingFusionHandler->setIncludeFusionPackage('Neos.Neos');

        $httpRequest = $this->getObject(ServerRequestFactoryInterface::class)->createServerRequest('GET', $requestUri);

        $this->frontendNodeControllerResponse = $this->getObject(\Neos\Flow\Http\Middleware\MiddlewaresChain::class)->handle(
            $httpRequest
        );
    }

    /**
     * @Then I expect the following response header:
     */
    public function iExpectTheFollowingResponseHeader(PyStringNode $expectedResult): void
    {
        Assert::assertNotNull($this->frontendNodeControllerResponse);
        Assert::assertSame($expectedResult->getRaw(), $this->frontendNodeControllerResponse->getBody()->getContents());
    }

    /**
     * @Then I expect the following response:
     */
    public function iExpectTheFollowingResponse(PyStringNode $expectedResult): void
    {
        Assert::assertNotNull($this->frontendNodeControllerResponse);
        Assert::assertEquals($expectedResult->getRaw(), str_replace("\r\n", "\n", Message::toString($this->frontendNodeControllerResponse)));
    }
}
