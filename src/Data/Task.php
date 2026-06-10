<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

/**
 * Una unidad de trabajo encapsulada con su contrato de "hecho".
 *
 * El plan.php del proyecto es una lista de estos objetos. Lo genera el
 * modelo fuerte (Opus) en design-time; el orquestador y los agentes
 * baratos solo lo ejecutan.
 */
final readonly class Task
{
    /**
     * @param string       $verifyCommand comando que define "hecho" (p. ej. phpunit). exit 0 = pasa.
     * @param list<string> $scopePaths    ficheros/globs que esta tarea puede tocar
     * @param list<string> $dependsOn     ids de tareas que deben completarse antes
     * @param list<string> $contextPaths  ficheros a inyectar como contexto (interfaces, tests…)
     */
    public function __construct(
        public string $id,
        public string $role,
        public string $instruction,
        public string $verifyCommand,
        public array  $scopePaths = [],
        public array  $dependsOn = [],
        public array  $contextPaths = [],
    ) {}
}
