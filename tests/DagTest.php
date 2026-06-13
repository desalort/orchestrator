<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests;

use Desalort\LlmGateway\Data\Usage;
use Desalort\Orchestrator\Dag;
use Desalort\Orchestrator\Data\Task;
use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;
use PHPUnit\Framework\TestCase;

final class DagTest extends TestCase
{
    private function task(string $id, array $dependsOn = []): Task
    {
        return new Task(
            id: $id,
            role: 'codegen',
            instruction: 'irrelevante',
            verifyCommand: 'true',
            dependsOn: $dependsOn,
        );
    }

    private function depResult(TaskStatus $status): TaskResult
    {
        return new TaskResult('dep', $status, 1, 'agent/dep', '', new Usage(0, 0, 0), 0.0);
    }

    public function testReadyWithoutDependencies(): void
    {
        self::assertTrue(Dag::ready($this->task('a'), []));
    }

    public function testNotReadyWhenDependencyMissing(): void
    {
        $task = $this->task('a', ['dep']);
        self::assertFalse(Dag::ready($task, []));
    }

    public function testReadyWhenAllDependenciesDone(): void
    {
        $task = $this->task('a', ['dep']);
        $done = ['dep' => $this->depResult(TaskStatus::Done)];

        self::assertTrue(Dag::ready($task, $done));
        self::assertFalse(Dag::failed($task, $done));
    }

    public function testFailedWhenDependencyFailed(): void
    {
        $task = $this->task('a', ['dep']);
        $done = ['dep' => $this->depResult(TaskStatus::Failed)];

        self::assertTrue(Dag::ready($task, $done));
        self::assertTrue(Dag::failed($task, $done));
    }

    public function testFailedWhenDependencySkipped(): void
    {
        $task = $this->task('a', ['dep']);
        $done = ['dep' => $this->depResult(TaskStatus::Skipped)];

        self::assertTrue(Dag::failed($task, $done));
    }
}
