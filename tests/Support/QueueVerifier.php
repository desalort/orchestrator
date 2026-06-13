<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Support;

use Desalort\Orchestrator\Contracts\VerifierInterface;
use Desalort\Orchestrator\Data\VerifierResult;
use RuntimeException;

/** Verificador de prueba: devuelve resultados predefinidos en orden. */
final class QueueVerifier implements VerifierInterface
{
    private int $index = 0;

    /** @var list<string> comandos recibidos, en orden */
    public array $commands = [];

    /** @param list<VerifierResult> $results */
    public function __construct(private readonly array $results) {}

    public function verify(string $command, string $workingDir): VerifierResult
    {
        $this->commands[] = $command;

        if (!isset($this->results[$this->index])) {
            throw new RuntimeException('QueueVerifier: no quedan resultados en la cola.');
        }

        return $this->results[$this->index++];
    }
}
