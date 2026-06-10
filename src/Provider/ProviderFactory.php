<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Provider;

use Desalort\Orchestrator\Contracts\LlmProviderInterface;
use Desalort\Orchestrator\Data\ModelProfile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Construye el proveedor y el perfil concreto a partir de la config.
 * Aquí vive la indirección role → profile → provider que hace el sistema
 * agnóstico: cambiar de Together a Ollama es tocar la config, no el código.
 */
final class ProviderFactory
{
    /** @param array<string,mixed> $config el array devuelto por config/agents.php */
    public function __construct(private readonly array $config) {}

    public function providerForDriver(string $driver): LlmProviderInterface
    {
        return match ($driver) {
            'openai_compatible' => new OpenAiCompatibleProvider(),
            'anthropic'         => new AnthropicProvider(),
            default             => throw new InvalidArgumentException("Driver desconocido: {$driver}"),
        };
    }

    /** Resuelve un rol hasta un ModelProfile concreto. */
    public function profileForRole(string $role): ModelProfile
    {
        $roles = $this->config['roles'] ?? [];
        if (!isset($roles[$role])) {
            throw new InvalidArgumentException("Rol desconocido: {$role}");
        }
        $roleCfg = $roles[$role];

        $profiles = $this->config['profiles'] ?? [];
        if (!isset($profiles[$roleCfg['profile']])) {
            throw new InvalidArgumentException("Perfil desconocido: {$roleCfg['profile']}");
        }
        $profileCfg = $profiles[$roleCfg['profile']];

        $providers = $this->config['providers'] ?? [];
        if (!isset($providers[$profileCfg['provider']])) {
            throw new InvalidArgumentException("Proveedor desconocido: {$profileCfg['provider']}");
        }
        $providerCfg = $providers[$profileCfg['provider']];

        $apiKey = null;
        $envName = $providerCfg['api_key_env'] ?? null;
        if ($envName !== null) {
            $apiKey = getenv($envName);
            if ($apiKey === false || $apiKey === '') {
                throw new RuntimeException("Falta la variable de entorno {$envName}");
            }
        }

        return new ModelProfile(
            providerName: (string) $profileCfg['provider'],
            driver:       (string) $providerCfg['driver'],
            baseUrl:      rtrim((string) $providerCfg['base_url'], '/'),
            apiKey:       $apiKey,
            model:        (string) $profileCfg['model'],
            temperature:  (float) ($profileCfg['temperature'] ?? 0.1),
            maxTokens:    (int) ($profileCfg['max_tokens'] ?? 4000),
            timeout:      (int) ($providerCfg['timeout'] ?? 180),
        );
    }
}
