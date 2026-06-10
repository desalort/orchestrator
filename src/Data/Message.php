<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

/** Un mensaje de chat (role: system|user|assistant). */
final readonly class Message
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}
}
