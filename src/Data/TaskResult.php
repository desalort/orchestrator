<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

/** Resultado final de una tarea tras agotar intentos o pasar el verificador. */
final readonly class TaskResult
{
    public function __construct(
        public string     $taskId,
        public TaskStatus $status,
        public int        $attempts,
        public ?string    $branch,      // rama git con el trabajo, lista para revisar/mergear
        public string     $lastOutput,
    ) {}
}
