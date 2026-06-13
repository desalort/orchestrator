<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Verifier;

use Desalort\Orchestrator\Contracts\VerifierInterface;
use Desalort\Orchestrator\Data\VerifierResult;

/**
 * Ejecuta el verifyCommand de la tarea en su worktree. exit 0 = pasa.
 * Es la PUERTA que convierte el uso de modelos baratos en ahorro real
 * y no en generación de deuda técnica barata.
 *
 * Con `timeoutSeconds > 0` el comando corre bajo `timeout(1)`: si un agente
 * genera código con un bucle no terminante (p.ej. una bisección sin guardia),
 * el verificador NO cuelga la corrida entera —se mata y la tarea reintenta/falla
 * (exit 124). Requiere el binario `timeout` (coreutils); con 0 se desactiva.
 */
final class CommandVerifier implements VerifierInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 0,
    ) {
    }

    public function verify(string $command, string $workingDir): VerifierResult
    {
        $inner = sprintf('cd %s && %s', escapeshellarg($workingDir), $command);

        $full = $this->timeoutSeconds > 0
            ? sprintf('timeout %d sh -c %s 2>&1', $this->timeoutSeconds, escapeshellarg($inner))
            : sprintf('sh -c %s 2>&1', escapeshellarg($inner));

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
