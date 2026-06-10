<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Contracts;

use Desalort\Orchestrator\Data\Message;
use Desalort\Orchestrator\Data\ModelProfile;

/**
 * Abstracción de LLM. Permite intercambiar Together / Ollama / Anthropic /
 * cualquier endpoint OpenAI-compatible sin tocar el resto del sistema.
 */
interface LlmProviderInterface
{
    /** @param list<Message> $messages */
    public function complete(array $messages, ModelProfile $profile): string;
}
