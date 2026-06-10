<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Verifier;

use Desalort\Orchestrator\Contracts\VerifierInterface;
use Desalort\Orchestrator\Data\VerifierResult;

/**
 * Ejecuta el verifyCommand de la tarea en su worktree. exit 0 = pasa.
 * Es la PUERTA que convierte el uso de modelos baratos en ahorro real
 * y no en generación de deuda técnica barata.
 */
final class CommandVerifier implements VerifierInterface
{
    public function verify(string $command, string $workingDir): VerifierResult
    {
        $full = sprintf('cd %s && %s 2>&1', escapeshellarg($workingDir), $command);
        $lines = [];
        $code  = 0;
        exec($full, $lines, $code);

        return new VerifierResult(
            passed:   $code === 0,
            output:   implode("\n", $lines),
            exitCode: $code,
        );
    }
}
