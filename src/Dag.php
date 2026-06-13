<?php

declare(strict_types=1);

namespace Desalort\Orchestrator;

use Desalort\Orchestrator\Data\Task;
use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;

/**
 * Lógica pura del grafo de dependencias del plan. Separada de
 * `Orchestrator` para poder testearla sin `proc_open`.
 */
final class Dag
{
    /** @param array<string,TaskResult> $done */
    public static function ready(Task $task, array $done): bool
    {
        foreach ($task->dependsOn as $dep) {
            if (!isset($done[$dep])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,TaskResult> $done */
    public static function failed(Task $task, array $done): bool
    {
        foreach ($task->dependsOn as $dep) {
            if (in_array($done[$dep]->status, [TaskStatus::Failed, TaskStatus::Skipped], true)) {
                return true;
            }
        }

        return false;
    }
}
