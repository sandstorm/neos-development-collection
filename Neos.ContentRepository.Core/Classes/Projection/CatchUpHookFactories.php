<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection;

/**
 * @implements CatchUpHookFactoryInterface<ProjectionStateInterface>
 * @internal
 */
final class CatchUpHookFactories implements CatchUpHookFactoryInterface
{
    /**
     * @var array<mixed,CatchUpHookFactoryInterface<ProjectionStateInterface>>
     */
    private array $catchUpHookFactories;

    /**
     * @param CatchUpHookFactoryInterface<ProjectionStateInterface> ...$catchUpHookFactories
     */
    private function __construct(CatchUpHookFactoryInterface ...$catchUpHookFactories)
    {
        $this->catchUpHookFactories = $catchUpHookFactories;
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param CatchUpHookFactoryInterface<ProjectionStateInterface> $catchUpHookFactory
     * @return self
     */
    public function with(CatchUpHookFactoryInterface $catchUpHookFactory): self
    {
        if ($this->has($catchUpHookFactory::class)) {
            throw new \InvalidArgumentException(
                sprintf('a CatchUpHookFactory of type "%s" already exists in this set', $catchUpHookFactory::class),
                1650121280
            );
        }
        $catchUpHookFactories = $this->catchUpHookFactories;
        $catchUpHookFactories[$catchUpHookFactory::class] = $catchUpHookFactory;
        return new self(...$catchUpHookFactories);
    }

    private function has(string $catchUpHookFactoryClassName): bool
    {
        return array_key_exists($catchUpHookFactoryClassName, $this->catchUpHookFactories);
    }

    public function build(CatchUpHookFactoryDependencies $dependencies): CatchUpHookInterface
    {
        $catchUpHooks = array_map(static fn(CatchUpHookFactoryInterface $catchUpHookFactory) => $catchUpHookFactory->build($dependencies), $this->catchUpHookFactories);
        return new DelegatingCatchUpHook(...$catchUpHooks);
    }
}
