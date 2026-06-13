<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Data;

use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;
use PHPUnit\Framework\TestCase;

final class TaskResultTest extends TestCase
{
    public function testDecodesFullWorkerJson(): void
    {
        $json = json_encode([
            'status'     => 'done',
            'attempts'   => 2,
            'branch'     => 'agent/task-1',
            'lastOutput' => 'OK',
            'usage'      => [
                'prompt_tokens'     => 100,
                'completion_tokens' => 50,
                'total_tokens'      => 150,
            ],
            'cost_usd'   => 0.0042,
        ], JSON_THROW_ON_ERROR);

        $result = TaskResult::fromWorkerJson('task-1', $json);

        self::assertSame('task-1', $result->taskId);
        self::assertSame(TaskStatus::Done, $result->status);
        self::assertSame(2, $result->attempts);
        self::assertSame('agent/task-1', $result->branch);
        self::assertSame('OK', $result->lastOutput);
        self::assertSame(100, $result->usage->promptTokens);
        self::assertSame(50, $result->usage->completionTokens);
        self::assertSame(150, $result->usage->totalTokens);
        self::assertEqualsWithDelta(0.0042, $result->costUsd, 1e-9);
    }

    public function testFallsBackToFailedOnCorruptJson(): void
    {
        $result = TaskResult::fromWorkerJson('task-1', 'no es json');

        self::assertSame(TaskStatus::Failed, $result->status);
        self::assertSame(0, $result->attempts);
        self::assertNull($result->branch);
        self::assertStringContainsString('no es json', $result->lastOutput);
    }

    public function testDefaultsMissingUsageAndCost(): void
    {
        $json = json_encode([
            'status'     => 'failed',
            'attempts'   => 1,
            'branch'     => null,
            'lastOutput' => 'fallo',
        ], JSON_THROW_ON_ERROR);

        $result = TaskResult::fromWorkerJson('task-1', $json);

        self::assertSame(TaskStatus::Failed, $result->status);
        self::assertSame(0, $result->usage->totalTokens);
        self::assertSame(0.0, $result->costUsd);
    }
}
