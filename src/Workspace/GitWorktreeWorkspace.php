<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Workspace;

use Desalort\Orchestrator\Contracts\WorkspaceInterface;
use RuntimeException;

/**
 * Cada agente trabaja en su propio `git worktree` con rama `agent/<id>`.
 * Esto es lo que permite que varios agentes corran en paralelo SIN
 * pisarse ficheros: el aislamiento es físico, no por convención.
 *
 * El worktree se elimina al terminar (tearDown); la RAMA se conserva,
 * porque contiene el trabajo a revisar e integrar.
 */
final class GitWorktreeWorkspace implements WorkspaceInterface
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly string $worktreesDir,
    ) {}

    public function setUp(string $taskId, string $baseRef): string
    {
        $path   = $this->pathFor($taskId);
        $branch = $this->branchName($taskId);

        if (!is_dir($this->worktreesDir) && !mkdir($this->worktreesDir, 0775, true) && !is_dir($this->worktreesDir)) {
            throw new RuntimeException("No se pudo crear {$this->worktreesDir}");
        }

        // Limpia restos previos por si se re-ejecuta el plan.
        $this->git('worktree remove --force ' . escapeshellarg($path), allowFail: true);
        $this->git('branch -D ' . escapeshellarg($branch), allowFail: true);

        $this->git(sprintf(
            'worktree add -b %s %s %s',
            escapeshellarg($branch),
            escapeshellarg($path),
            escapeshellarg($baseRef),
        ));

        return $path;
    }

    public function applyEdits(string $taskId, array $files): void
    {
        $base = $this->pathFor($taskId);
        foreach ($files as $relPath => $contents) {
            $abs = $base . '/' . ltrim($relPath, '/');
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($abs, $contents);
        }
        // Commit del avance para que el diff sea revisable.
        $this->inDir($base, "git add -A && git commit -q --allow-empty -m " . escapeshellarg("agent: {$taskId}"), allowFail: true);
    }

    public function branchName(string $taskId): string
    {
        return 'agent/' . $this->slug($taskId);
    }

    public function tearDown(string $taskId): void
    {
        $this->git('worktree remove --force ' . escapeshellarg($this->pathFor($taskId)), allowFail: true);
    }

    private function pathFor(string $taskId): string
    {
        return $this->worktreesDir . '/' . $this->slug($taskId);
    }

    private function slug(string $taskId): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/i', '-', $taskId);
    }

    /** Ejecuta un comando git desde la raíz del repo. */
    private function git(string $args, bool $allowFail = false): void
    {
        $this->inDir($this->repoRoot, 'git ' . $args, $allowFail);
    }

    private function inDir(string $dir, string $cmd, bool $allowFail = false): void
    {
        $full = 'cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>&1';
        $out  = [];
        $code = 0;
        exec($full, $out, $code);
        if ($code !== 0 && !$allowFail) {
            throw new RuntimeException("Comando falló ({$cmd}):\n" . implode("\n", $out));
        }
    }
}
