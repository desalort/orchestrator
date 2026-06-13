<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

use Desalort\LlmGateway\Data\Usage;

/** Resultado final de una tarea tras agotar intentos o pasar el verificador. */
final readonly class TaskResult
{
    public function __construct(
        public string     $taskId,
        public TaskStatus $status,
        public int        $attempts,
        public ?string    $branch,      // rama git con el trabajo, lista para revisar/mergear
        public string     $lastOutput,
        public Usage       $usage = new Usage(0, 0, 0),
        public float       $costUsd = 0.0,
    ) {}

    /**
     * Decodifica el JSON que `agent-worker.php` imprime como última línea de
     * stdout. Tolerante a JSON corrupto o campos ausentes (config antigua).
     */
    public static function fromWorkerJson(string $taskId, string $json): self
    {
        $data = json_decode(trim($json), true);
        if (!is_array($data)) {
            return new self($taskId, TaskStatus::Failed, 0, null, "Salida ilegible del worker:\n{$json}");
        }

        $usageData = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $usage = new Usage(
            promptTokens:     (int) ($usageData['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usageData['completion_tokens'] ?? 0),
            totalTokens:      (int) ($usageData['total_tokens'] ?? 0),
        );

        return new self(
            $taskId,
            TaskStatus::tryFrom((string) ($data['status'] ?? 'failed')) ?? TaskStatus::Failed,
            (int) ($data['attempts'] ?? 0),
            isset($data['branch']) ? (string) $data['branch'] : null,
            (string) ($data['lastOutput'] ?? ''),
            $usage,
            (float) ($data['cost_usd'] ?? 0.0),
        );
    }
}
