<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Provider;

use Desalort\LlmGateway\LlmGateway;
use Desalort\LlmGateway\Providers\AnthropicProvider;
use Desalort\LlmGateway\Providers\OpenAiCompatibleProvider;
use Desalort\Orchestrator\Data\ModelProfile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Construye el gateway LLM y el perfil concreto a partir de la config.
 * Aquí vive la indirección role → profile → provider que hace el sistema
 * agnóstico: cambiar de Together a Ollama es tocar la config, no el código.
 *
 * La llamada al modelo (tool calling, Usage, retries) la resuelve
 * `desalort/llm-gateway`; esta factoría solo elige y configura el provider.
 */
final class ProviderFactory
{
    /** @param array<string,mixed> $config el array devuelto por config/agents.php */
    public function __construct(private readonly array $config) {}

    /** Resuelve un rol hasta su LlmGateway, listo para `complete()`. */
    public function gatewayForRole(string $role): LlmGateway
    {
        $profile = $this->profileForRole($role);

        $provider = match ($profile->driver) {
            'openai_compatible' => OpenAiCompatibleProvider::forCustom(
                $profile->apiKey ?? '',
                $profile->baseUrl,
                $profile->timeout,
            ),
            'anthropic' => new AnthropicProvider(
                (string) $profile->apiKey,
                $profile->timeout,
            ),
            default => throw new InvalidArgumentException("Driver desconocido: {$profile->driver}"),
        };

        return new LlmGateway($provider);
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
            costInputPer1M:  (float) ($profileCfg['cost_input_per_1m'] ?? 0.0),
            costOutputPer1M: (float) ($profileCfg['cost_output_per_1m'] ?? 0.0),
        );
    }
}
