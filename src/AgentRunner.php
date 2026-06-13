<?php

declare(strict_types=1);

namespace Desalort\Orchestrator;

use Desalort\LlmGateway\LlmGateway;
use Desalort\LlmGateway\Data\LlmRequest;
use Desalort\LlmGateway\Data\LlmResponse;
use Desalort\LlmGateway\Data\Message;
use Desalort\LlmGateway\Data\Tool;
use Desalort\LlmGateway\Data\ToolCall;
use Desalort\LlmGateway\Data\Usage;
use Desalort\Orchestrator\Contracts\VerifierInterface;
use Desalort\Orchestrator\Contracts\WorkspaceInterface;
use Desalort\Orchestrator\Data\ModelProfile;
use Desalort\Orchestrator\Data\Task;
use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;

/**
 * Núcleo inyectable. Ejecuta una tarea con un modelo:
 *   llamar modelo → aplicar ediciones → verificar → reintentar con el error.
 *
 * La entrega de ficheros usa tool calling (`write_files`), no un parser de
 * texto: tanto Anthropic como cualquier proveedor OpenAI-compatible la
 * fuerzan con `toolChoice: 'required'` vía `desalort/llm-gateway`.
 */
final class AgentRunner
{
    public function __construct(
        private readonly LlmGateway         $gateway,
        private readonly WorkspaceInterface $workspace,
        private readonly VerifierInterface  $verifier,
        private readonly string             $systemPrompt,
        private readonly int                $maxAttempts,
        private readonly string             $baseRef,
    ) {}

    public function run(Task $task, ModelProfile $profile): TaskResult
    {
        $workDir = $this->workspace->setUp($task->id, $this->baseRef);

        $request = new LlmRequest(
            messages: [
                Message::system($this->systemPrompt),
                Message::user($this->buildUserPrompt($task, $workDir)),
            ],
            model:       $profile->model,
            maxTokens:   $profile->maxTokens,
            temperature: $profile->temperature,
            tools:       [$this->writeFilesTool()],
            toolChoice:  'required',
        );

        $promptTokens     = 0;
        $completionTokens = 0;
        $lastOutput       = '';

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $response = $this->gateway->complete($request);
            $promptTokens     += $response->usage->promptTokens;
            $completionTokens += $response->usage->completionTokens;

            $edits = $this->parseEdits($response);

            if ($edits === []) {
                $request = $request->withMessages(
                    Message::assistant($response->content),
                    Message::user(
                        'No recibí ficheros a través de la tool "write_files". '
                        . 'Llama a "write_files" con al menos un fichero completo.',
                    ),
                );
                continue;
            }

            $this->workspace->applyEdits($task->id, $edits);
            $result     = $this->verifier->verify($task->verifyCommand, $workDir);
            $lastOutput = $result->output;

            $usage   = new Usage($promptTokens, $completionTokens, $promptTokens + $completionTokens);
            $costUsd = $this->cost($usage, $profile);

            if ($result->passed) {
                return new TaskResult(
                    $task->id,
                    TaskStatus::Done,
                    $attempt,
                    $this->workspace->branchName($task->id),
                    $lastOutput,
                    $usage,
                    $costUsd,
                );
            }

            // Realimenta el fallo del verificador para que se autocorrija.
            $request = $request->withMessages(
                Message::withToolCalls($this->rawToolCalls($response)),
                ...$this->toolResults(
                    $response,
                    "El verificador falló (intento {$attempt}/{$this->maxAttempts}):\n\n"
                    . $result->output
                    . "\n\nCorrige y vuelve a llamar a \"write_files\" con los ficheros completos.",
                ),
            );
        }

        $usage = new Usage($promptTokens, $completionTokens, $promptTokens + $completionTokens);

        return new TaskResult(
            $task->id,
            TaskStatus::Failed,
            $this->maxAttempts,
            $this->workspace->branchName($task->id),
            $lastOutput,
            $usage,
            $this->cost($usage, $profile),
        );
    }

    private function buildUserPrompt(Task $task, string $workDir): string
    {
        $context = '';
        foreach ($task->contextPaths as $rel) {
            $abs = $workDir . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                $context .= "\n--- {$rel} ---\n" . (string) file_get_contents($abs) . "\n";
            }
        }

        $scope = $task->scopePaths === []
            ? '(libre dentro del módulo)'
            : implode(', ', $task->scopePaths);

        // Inyecta el contenido ACTUAL de los ficheros del ámbito (si existen): así el agente
        // conserva firma, namespace e imports del esqueleto y solo reemplaza el cuerpo, en vez
        // de reconstruirlos de memoria (fuente típica de errores en ficheros con muchos `use`).
        $scopeContenido = '';
        foreach ($task->scopePaths as $rel) {
            $abs = $workDir . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                $scopeContenido .= "\n--- {$rel} ---\n" . (string) file_get_contents($abs) . "\n";
            }
        }
        $scopeBloque = $scopeContenido === ''
            ? ''
            : "\n\nFICHEROS ACTUALES DE TU ÁMBITO (mantén firma/namespace/imports; reemplaza el cuerpo):{$scopeContenido}";

        return <<<TXT
            TAREA: {$task->instruction}

            ÁMBITO (solo puedes tocar estos ficheros): {$scope}

            VERIFICADOR (tu trabajo debe hacer que pase): {$task->verifyCommand}

            CONTEXTO RELEVANTE:{$context}{$scopeBloque}

            Entrega tu trabajo llamando a la tool "write_files" con el contenido
            completo de cada fichero creado o modificado.
            TXT;
    }

    private function writeFilesTool(): Tool
    {
        return Tool::function(
            name: 'write_files',
            description: 'Escribe el contenido completo de uno o más ficheros en el workspace de la tarea.',
            parameters: [
                'type'       => 'object',
                'properties' => [
                    'files' => [
                        'type'        => 'array',
                        'description' => 'Ficheros a crear o sobrescribir, con su contenido completo.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'path'    => ['type' => 'string', 'description' => 'Ruta relativa del fichero.'],
                                'content' => ['type' => 'string', 'description' => 'Contenido completo del fichero.'],
                            ],
                            'required' => ['path', 'content'],
                        ],
                    ],
                ],
                'required' => ['files'],
            ],
        );
    }

    /** @return array<string,string> ruta => contenido */
    private function parseEdits(LlmResponse $response): array
    {
        $files = [];
        foreach ($response->toolCalls as $toolCall) {
            if ($toolCall->name !== 'write_files') {
                continue;
            }
            foreach ((array) ($toolCall->arguments['files'] ?? []) as $file) {
                if (!is_array($file) || !isset($file['path'], $file['content'])) {
                    continue;
                }
                $files[(string) $file['path']] = (string) $file['content'];
            }
        }

        return $files;
    }

    /** @return list<array{id:string,type:string,function:array{name:string,arguments:string}}> */
    private function rawToolCalls(LlmResponse $response): array
    {
        return array_map(
            static fn (ToolCall $tc): array => [
                'id'       => $tc->id,
                'type'     => 'function',
                'function' => ['name' => $tc->name, 'arguments' => $tc->rawArguments],
            ],
            $response->toolCalls,
        );
    }

    /** @return list<Message> */
    private function toolResults(LlmResponse $response, string $content): array
    {
        return array_map(
            static fn (ToolCall $tc): Message => Message::toolResult($tc->id, $content, $tc->name),
            $response->toolCalls,
        );
    }

    private function cost(Usage $usage, ModelProfile $profile): float
    {
        return ($usage->promptTokens / 1_000_000) * $profile->costInputPer1M
            + ($usage->completionTokens / 1_000_000) * $profile->costOutputPer1M;
    }
}
