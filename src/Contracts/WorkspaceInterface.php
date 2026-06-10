<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Contracts;

/**
 * Aísla el trabajo de cada agente para permitir paralelismo seguro
 * (la implementación de referencia usa git worktree + rama por tarea).
 */
interface WorkspaceInterface
{
    /** Prepara un espacio aislado y devuelve su ruta absoluta. */
    public function setUp(string $taskId, string $baseRef): string;

    /** @param array<string,string> $files ruta_relativa => contenido completo */
    public function applyEdits(string $taskId, array $files): void;

    /** Nombre de la rama git asociada a la tarea. */
    public function branchName(string $taskId): string;

    public function tearDown(string $taskId): void;
}
