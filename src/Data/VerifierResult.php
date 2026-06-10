<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

/** Resultado de ejecutar el verificador de una tarea. */
final readonly class VerifierResult
{
    public function __construct(
        public bool   $passed,
        public string $output,
        public int    $exitCode,
    ) {}
}
