<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Tests\Unit\SharedModel\Workspace;

use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus;
use PHPUnit\Framework\TestCase;

class WorkspacesTest extends TestCase
{
    public function provideGetBaseWorkspacesExamples()
    {
        yield 'empty workspaces' => [
            [],
            'random',
            []
        ];

        yield 'not in set' => [
            [
                self::workspace('a', null),
                self::workspace('b', 'a'),
            ],
            'random',
            []
        ];

        yield 'one deep (b) -> a' => [
            [
                self::workspace('a', null),
                self::workspace('b', 'a'),
            ],
            'b',
            ['a']
        ];

        yield 'recursive (d) -> c -> b -> a' => [
            [
                self::workspace('a', null),
                self::workspace('b', 'a'),
                self::workspace('c', 'b'),
                self::workspace('d', 'c'),
            ],
            'd',
            ['a', 'b', 'c']
        ];

        yield 'recursive, exclude descendants d -> (c) -> b -> a' => [
            [
                self::workspace('a', null),
                self::workspace('b', 'a'),
                self::workspace('c', 'b'),
                self::workspace('d', 'c'),
            ],
            'c',
            ['a', 'b']
        ];

        yield 'recursive, exclude descendants and other chains d -> (c) -> b -> a && f -> e -> b -> a && g -> a && y -> z' => [
            [
                self::workspace('a', null),
                self::workspace('b', 'a'),
                self::workspace('c', 'b'),
                self::workspace('d', 'c'),

                self::workspace('e', 'b'),
                self::workspace('f', 'e'),

                self::workspace('g', 'a'),

                self::workspace('z', null),
                self::workspace('y', 'z'),
            ],
            'c',
            ['a', 'b']
        ];
    }

    /**
     * @dataProvider provideGetBaseWorkspacesExamples
     */
    public function testGetBaseWorkspaces(array $workspaces, string $requestedWorkspaceName, array $expectedWorkspaceNames): void
    {
        $actual = Workspaces::fromArray($workspaces)->getBaseWorkspaces(WorkspaceName::fromString($requestedWorkspaceName));
        // todo order should be fixed
        self::assertEqualsCanonicalizing(
            $expectedWorkspaceNames,
            $actual->map(fn (Workspace $workspace) => $workspace->workspaceName->value)
        );
    }

    private static function workspace(string $name, string|null $baseWorkspace): Workspace
    {
        return Workspace::create(
            WorkspaceName::fromString($name),
            $baseWorkspace ? WorkspaceName::fromString($baseWorkspace) : null,
            ContentStreamId::create(),
            WorkspaceStatus::UP_TO_DATE,
            false
        );
    }
}
