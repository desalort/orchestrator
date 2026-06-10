<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

/** Descriptor de modelo ya resuelto desde la config (role → profile → provider). */
final readonly class ModelProfile
{
    public function __construct(
        public string  $providerName,
        public string  $driver,       // 'openai_compatible' | 'anthropic'
        public string  $baseUrl,
        public ?string $apiKey,
        public string  $model,
        public float   $temperature,
        public int     $maxTokens,
        public int     $timeout,
    ) {}
}
