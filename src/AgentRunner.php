<?php

declare(strict_types=1);

namespace Desalort\Orchestrator;

use Desalort\Orchestrator\Contracts\LlmProviderInterface;
use Desalort\Orchestrator\Contracts\VerifierInterface;
use Desalort\Orchestrator\Contracts\WorkspaceInterface;
use Desalort\Orchestrator\Data\Message;
use Desalort\Orchestrator\Data\ModelProfile;
use Desalort\Orchestrator\Data\Task;
use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;

/**
 * Núcleo inyectable. Ejecuta una tarea con un modelo:
 *   llamar modelo → aplicar ediciones → verificar → reintentar con el error.
 */
final class AgentRunner
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly WorkspaceInterface   $workspace,
        private readonly VerifierInterface    $verifier,
        private readonly string               $systemPrompt,
        private readonly int                  $maxAttempts,
        private readonly string               $baseRef,
    ) {}

    public function run(Task $task, ModelProfile $profile): TaskResult
    {
        $workDir = $this->workspace->setUp($task->id, $this->baseRef);

        $messages = [
            new Message('system', $this->systemPrompt),
            new Message('user', $this->buildUserPrompt($task, $workDir)),
        ];

        $lastOutput = '';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $response = $this->provider->complete($messages, $profile);
            $edits    = $this->parseEdits($response);

            if ($edits === []) {
                $messages[] = new Message('assistant', $response);
                $messages[] = new Message(
                    'user',
                    'No detecté bloques de fichero. Responde SOLO con bloques '
                    . '"=== FILE: ruta ===" / contenido / "=== END FILE ===".',
                );
                continue;
            }

            $this->workspace->applyEdits($task->id, $edits);
            $result     = $this->verifier->verify($task->verifyCommand, $workDir);
            $lastOutput = $result->output;

            if ($result->passed) {
                return new TaskResult(
                    $task->id,
                    TaskStatus::Done,
                    $attempt,
                    $this->workspace->branchName($task->id),
                    $lastOutput,
                );
            }

            // Realimenta el fallo del verificador para que se autocorrija.
            $messages[] = new Message('assistant', $response);
            $messages[] = new Message(
                'user',
                "El verificador falló (intento {$attempt}/{$this->maxAttempts}):\n\n"
                . $result->output
                . "\n\nCorrige y devuelve los ficheros completos de nuevo.",
            );
        }

        return new TaskResult(
            $task->id,
            TaskStatus::Failed,
            $this->maxAttempts,
            $this->workspace->branchName($task->id),
            $lastOutput,
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

        return <<<TXT
            TAREA: {$task->instruction}

            ÁMBITO (solo puedes tocar estos ficheros): {$scope}

            VERIFICADOR (tu trabajo debe hacer que pase): {$task->verifyCommand}

            CONTEXTO RELEVANTE:{$context}

            Devuelve cada fichero creado/modificado en este formato EXACTO:
            === FILE: ruta/relativa.php ===
            <contenido completo del fichero>
            === END FILE ===
            TXT;
    }

    /**
     * Parser del protocolo de salida. Alternativa más robusta para modelos
     * con tool calling fiable: function calling con un esquema write_file.
     *
     * @return array<string,string> ruta => contenido
     */
    private function parseEdits(string $response): array
    {
        $files = [];
        if (preg_match_all(
            '/=== FILE: (.+?) ===\r?\n(.*?)\r?\n=== END FILE ===/s',
            $response,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $m) {
                $files[trim($m[1])] = $m[2];
            }
        }

        return $files;
    }
}
