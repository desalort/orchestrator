<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Provider;

use Desalort\Orchestrator\Contracts\LlmProviderInterface;
use Desalort\Orchestrator\Data\Message;
use Desalort\Orchestrator\Data\ModelProfile;
use RuntimeException;

/**
 * Un solo driver para todos los endpoints con esquema OpenAI
 * (/chat/completions): Together, Ollama, vLLM, LM Studio, OpenRouter,
 * llama.cpp server… Solo cambia base_url + model (+ key opcional).
 */
final class OpenAiCompatibleProvider implements LlmProviderInterface
{
    public function complete(array $messages, ModelProfile $profile): string
    {
        $payload = [
            'model'       => $profile->model,
            'temperature' => $profile->temperature,
            'max_tokens'  => $profile->maxTokens,
            'messages'    => array_map(
                static fn (Message $m): array => ['role' => $m->role, 'content' => $m->content],
                $messages,
            ),
        ];

        $headers = ['Content-Type: application/json'];
        if ($profile->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $profile->apiKey;
        }

        $body = $this->post(
            $profile->baseUrl . '/chat/completions',
            $payload,
            $headers,
            $profile->timeout,
        );

        return (string) ($body['choices'][0]['message']['content'] ?? '');
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string>        $headers
     * @return array<string,mixed>
     */
    private function post(string $url, array $payload, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Fallo de red al llamar al modelo: {$err}");
        }
        if ($code >= 400) {
            throw new RuntimeException("El proveedor devolvió HTTP {$code}: {$raw}");
        }

        /** @var array<string,mixed> */
        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
