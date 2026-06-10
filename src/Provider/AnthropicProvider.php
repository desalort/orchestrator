<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Provider;

use Desalort\Orchestrator\Contracts\LlmProviderInterface;
use Desalort\Orchestrator\Data\ModelProfile;
use RuntimeException;

/**
 * Adaptador para la Messages API de Anthropic: el rol 'system' va en un
 * campo aparte y la autenticación usa x-api-key, no Bearer.
 */
final class AnthropicProvider implements LlmProviderInterface
{
    public function complete(array $messages, ModelProfile $profile): string
    {
        $system = '';
        $turns  = [];
        foreach ($messages as $m) {
            if ($m->role === 'system') {
                $system .= $m->content . "\n";
                continue;
            }
            $turns[] = ['role' => $m->role, 'content' => $m->content];
        }

        $payload = [
            'model'      => $profile->model,
            'max_tokens' => $profile->maxTokens,
            'system'     => trim($system),
            'messages'   => $turns,
        ];

        $ch = curl_init($profile->baseUrl . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . (string) $profile->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT    => $profile->timeout,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Fallo de red al llamar a Anthropic: {$err}");
        }
        if ($code >= 400) {
            throw new RuntimeException("Anthropic devolvió HTTP {$code}: {$raw}");
        }

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        return (string) ($body['content'][0]['text'] ?? '');
    }
}
