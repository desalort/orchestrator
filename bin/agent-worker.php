<?php

declare(strict_types=1);

/**
 * Worker de UN agente, lanzado por el orquestador como subproceso:
 *   php agent-worker.php <configPath> <planPath> <taskId>
 *
 * Trabaja en su propio git worktree (aislamiento). Imprime, como última
 * línea de stdout, un JSON con el resultado de la tarea.
 */

use Desalort\Orchestrator\AgentRunner;
use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;
use Desalort\Orchestrator\Provider\ProviderFactory;
use Desalort\Orchestrator\Verifier\CommandVerifier;
use Desalort\Orchestrator\Workspace\GitWorktreeWorkspace;

(static function (): void {
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ];
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require $autoload;
            return;
        }
    }
    fwrite(STDERR, "No se encontró el autoloader de Composer.\n");
    exit(1);
})();

$configPath = $argv[1] ?? '';
$planPath   = $argv[2] ?? '';
$taskId     = $argv[3] ?? '';

try {
    /** @var array<string,mixed> $config */
    $config = require $configPath;
    /** @var array<string,\Desalort\Orchestrator\Data\Task> $tasks */
    $tasks = require $planPath;

    if (!isset($tasks[$taskId])) {
        throw new RuntimeException("La tarea '{$taskId}' no está en el plan");
    }
    $task = $tasks[$taskId];

    /** @var array<string,mixed> $runtime */
    $runtime = $config['runtime'] ?? [];
    $factory = new ProviderFactory($config);

    $profile  = $factory->profileForRole($task->role);
    $provider = $factory->providerForDriver($profile->driver);

    // Prompt de sistema = CONVENTIONS.md (fuente única de verdad) + prompt de rol.
    // Así las convenciones se incluyen POR REFERENCIA: viven en un solo fichero.
    $promptsDir = (string) ($runtime['prompts_dir'] ?? (getcwd() . '/prompts'));
    $promptFile = $promptsDir . '/' . ($config['roles'][$task->role]['prompt'] ?? '');
    $rolePrompt = is_file($promptFile)
        ? (string) file_get_contents($promptFile)
        : 'Eres un agente de desarrollo. Sigue estrictamente las convenciones del proyecto.';

    $conventions = '';
    $conventionsFile = (string) ($runtime['conventions_file'] ?? (getcwd() . '/CONVENTIONS.md'));
    if (is_file($conventionsFile)) {
        $conventions = "# CONVENCIONES DEL PROYECTO (vinculantes)\n\n"
            . (string) file_get_contents($conventionsFile) . "\n\n---\n\n";
    }

    $system = $conventions . $rolePrompt;

    $workspace = new GitWorktreeWorkspace(
        repoRoot:     getcwd() ?: '.',
        worktreesDir: (string) ($runtime['worktrees_dir'] ?? (sys_get_temp_dir() . '/agent-worktrees')),
    );

    $runner = new AgentRunner(
        provider:     $provider,
        workspace:    $workspace,
        verifier:     new CommandVerifier(),
        systemPrompt: $system,
        maxAttempts:  (int) ($runtime['max_attempts'] ?? 3),
        baseRef:      (string) ($runtime['base_ref'] ?? 'HEAD'),
    );

    $result = $runner->run($task, $profile);
    $workspace->tearDown($task->id); // quita el worktree; la rama se conserva

} catch (\Throwable $e) {
    $result = new TaskResult((string) $taskId, TaskStatus::Failed, 0, null, $e->getMessage());
}

// Última línea de stdout = contrato con el orquestador.
echo "\n" . json_encode([
    'status'     => $result->status->value,
    'attempts'   => $result->attempts,
    'branch'     => $result->branch,
    'lastOutput' => mb_substr($result->lastOutput, 0, 4000),
], JSON_THROW_ON_ERROR) . "\n";
