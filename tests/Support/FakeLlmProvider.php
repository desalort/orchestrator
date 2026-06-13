<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Support;

use Desalort\LlmGateway\Contracts\LlmProviderInterface;
use Desalort\LlmGateway\Data\LlmRequest;
use Desalort\LlmGateway\Data\LlmResponse;
use RuntimeException;

/**
 * Provider de prueba: devuelve una cola fija de {@see LlmResponse} y registra
 * cada {@see LlmRequest} recibido para poder inspeccionar el historial de
 * mensajes que construye {@see \Desalort\Orchestrator\AgentRunner}.
 */
final class FakeLlmProvider implements LlmProviderInterface
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    private int $index = 0;

    /** @param list<LlmResponse> $responses */
    public function __construct(private readonly array $responses) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        if (!isset($this->responses[$this->index])) {
            throw new RuntimeException('FakeLlmProvider: no quedan respuestas en la cola.');
        }

        return $this->responses[$this->index++];
    }

    public function stream(LlmRequest $request, callable $onChunk): LlmResponse
    {
        throw new RuntimeException('FakeLlmProvider no soporta streaming.');
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function supportsJsonMode(): bool
    {
        return false;
    }
}
