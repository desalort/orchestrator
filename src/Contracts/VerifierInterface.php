<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Contracts;

use Desalort\Orchestrator\Data\VerifierResult;

/** Ejecuta el comando que decide si una tarea está "hecha". */
interface VerifierInterface
{
    public function verify(string $command, string $workingDir): VerifierResult;
}
