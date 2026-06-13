<?php

declare(strict_types=1);

namespace Desalort\Orchestrator;

use Closure;
use Desalort\Orchestrator\Data\Task;
use Desalort\Orchestrator\Data\TaskResult;
use Desalort\Orchestrator\Data\TaskStatus;
use RuntimeException;

/**
 * Núcleo inyectable. Resuelve el grafo de dependencias (DAG) y lanza hasta
 * `max_concurrency` workers en paralelo, un subproceso por tarea.
 *
 * Regla: paralelizar entre unidades independientes, serializar dentro de
 * una unidad compartida (las dependencias del plan modelan esa serialización).
 */
final class Orchestrator
{
    /**
     * @param array<string,mixed> $runtimeCfg sección 'runtime' de la config
     * @param Closure(string):void $log
     */
    public function __construct(
        private readonly array   $runtimeCfg,
        private readonly string  $configPath,
        private readonly string  $planPath,
        private readonly string  $workerBin,
        private readonly Closure $log,
        private readonly ?string $workerLogDir = null,
    ) {}

    /**
     * @param list<Task> $tasks
     * @return array<string,TaskResult>
     */
    public function run(array $tasks): array
    {
        /** @var array<string,Task> $byId */
        $byId = [];
        foreach ($tasks as $t) {
            $byId[$t->id] = $t;
        }

        $maxConc = (int) ($this->runtimeCfg['max_concurrency'] ?? 3);

        /** @var array<string,TaskResult> $done */
        $done = [];
        /** @var array<string,array{handle:resource,stdout:resource}> $running */
        $running = [];
        $pending = $byId;

        while ($pending !== [] || $running !== []) {

            // 1) Lanza tareas cuyas dependencias estén satisfechas.
            foreach ($pending as $id => $task) {
                if (count($running) >= $maxConc) {
                    break;
                }
                if (!Dag::ready($task, $done)) {
                    continue;
                }
                if (Dag::failed($task, $done)) {
                    $done[$id] = new TaskResult($id, TaskStatus::Skipped, 0, null, 'dependencia fallida');
                    unset($pending[$id]);
                    ($this->log)("skip {$id}: dependencia fallida");
                    continue;
                }
                ($this->log)("run  {$id} ({$task->role})");
                $running[$id] = $this->spawn($id);
                unset($pending[$id]);
            }

            // 2) Recoge los workers que hayan terminado.
            foreach ($running as $id => $proc) {
                $status = proc_get_status($proc['handle']);
                if ($status['running']) {
                    continue;
                }
                $stdout = (string) stream_get_contents($proc['stdout']);
                fclose($proc['stdout']);
                proc_close($proc['handle']);
                unset($running[$id]);

                $result    = $this->decodeResult($id, $stdout);
                $done[$id] = $result;
                ($this->log)(sprintf('done %s -> %s (%d intentos)', $id, $result->status->value, $result->attempts));
            }

            usleep(200_000); // evita busy-loop
        }

        return $done;
    }

    /** @return array{handle:resource,stdout:resource} */
    private function spawn(string $taskId): array
    {
        $cmd = sprintf(
            'php %s %s %s %s',
            escapeshellarg($this->workerBin),
            escapeshellarg($this->configPath),
            escapeshellarg($this->planPath),
            escapeshellarg($taskId),
        );

        // Con worker_log_dir, el STDERR del worker (fatales de PHP, errores de composer,
        // trazas fuera del verificador) se persiste por tarea en vez de descartarse.
        if ($this->workerLogDir !== null) {
            if (!is_dir($this->workerLogDir) && !mkdir($this->workerLogDir, 0775, true) && !is_dir($this->workerLogDir)) {
                throw new RuntimeException("No se pudo crear {$this->workerLogDir}");
            }
            $stderrLog   = $this->workerLogDir . '/' . $this->slug($taskId) . '.worker.log';
            $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $stderrLog, 'a']];
            $pipes       = [];
            $handle      = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($handle)) {
                throw new RuntimeException("No se pudo lanzar el worker para {$taskId}");
            }
            stream_set_blocking($pipes[1], false);

            return ['handle' => $handle, 'stdout' => $pipes[1]];
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $handle = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($handle)) {
            throw new RuntimeException("No se pudo lanzar el worker para {$taskId}");
        }
        stream_set_blocking($pipes[1], false);
        fclose($pipes[2]); // sin worker_log_dir: se descarta el stderr (comportamiento histórico)

        return ['handle' => $handle, 'stdout' => $pipes[1]];
    }

    private function slug(string $taskId): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/i', '-', $taskId);
    }

    private function decodeResult(string $id, string $stdout): TaskResult
    {
        // El worker imprime su resultado como ÚLTIMA línea JSON.
        $trimmed = trim($stdout);
        $lastNl  = strrpos($trimmed, "\n");
        $line    = $lastNl === false ? $trimmed : substr($trimmed, $lastNl + 1);

        return TaskResult::fromWorkerJson($id, $line);
    }
}
