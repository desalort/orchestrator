<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Support;

use Desalort\Orchestrator\Contracts\WorkspaceInterface;

/** Workspace de prueba: guarda los ficheros aplicados en memoria, sin git. */
final class InMemoryWorkspace implements WorkspaceInterface
{
    /** @var array<string,string> ruta => contenido */
    public array $files = [];

    public bool $tornDown = false;

    public function setUp(string $taskId, string $baseRef): string
    {
        return '/tmp/fake-workspace/' . $taskId;
    }

    public function applyEdits(string $taskId, array $files): void
    {
        foreach ($files as $path => $content) {
            $this->files[$path] = $content;
        }
    }

    public function branchName(string $taskId): string
    {
        return 'agent/' . $taskId;
    }

    public function tearDown(string $taskId): void
    {
        $this->tornDown = true;
    }
}
