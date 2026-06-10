<?php

declare(strict_types=1);

/**
 * Plantilla de configuración. Copia este fichero a la raíz de tu proyecto
 * como `agents.php` y ajústalo. Las API keys NUNCA van aquí: se leen de
 * variables de entorno.
 *
 * Indirección: role -> profile -> provider.
 * Mover un rol de Together a Ollama = cambiar su 'profile'. Cero código.
 */

return [

    // ---- PROVEEDORES: dónde y cómo se llama al modelo ----
    // 'openai_compatible' cubre Together, Ollama, vLLM, LM Studio, OpenRouter…
    'providers' => [
        'together' => [
            'driver'      => 'openai_compatible',
            'base_url'    => 'https://api.together.xyz/v1',
            'api_key_env' => 'TOGETHER_API_KEY',
            'timeout'     => 180,
        ],
        'ollama' => [
            'driver'      => 'openai_compatible',
            'base_url'    => 'http://localhost:11434/v1',
            'api_key_env' => null,        // Ollama no requiere key
            'timeout'     => 600,         // local puede ir más lento
        ],
        'anthropic' => [
            'driver'      => 'anthropic',
            'base_url'    => 'https://api.anthropic.com/v1',
            'api_key_env' => 'ANTHROPIC_API_KEY',
            'timeout'     => 180,
        ],
    ],

    // ---- PERFILES: modelo concreto + parámetros ----
    'profiles' => [
        'codegen_fast' => [
            'provider'    => 'together',
            'model'       => 'Qwen/Qwen3-Coder-30B',
            'temperature' => 0.1,
            'max_tokens'  => 8000,
        ],
        'codegen_local' => [
            'provider'    => 'ollama',
            'model'       => 'qwen3-coder',
            'temperature' => 0.1,
            'max_tokens'  => 8000,
        ],
        'reasoning_review' => [
            'provider'    => 'together',
            'model'       => 'deepseek-ai/DeepSeek-V3.2',
            'temperature' => 0.0,
            'max_tokens'  => 6000,
        ],
        'docs' => [
            'provider'    => 'ollama',
            'model'       => 'qwen3-coder',
            'temperature' => 0.3,
            'max_tokens'  => 4000,
        ],
    ],

    // ---- ROLES: mapean a un perfil + su prompt de sistema ----
    'roles' => [
        'codegen'  => ['profile' => 'codegen_fast',     'prompt' => 'codegen.md'],
        'frontend' => ['profile' => 'codegen_fast',     'prompt' => 'frontend.md'],
        'tests'    => ['profile' => 'codegen_fast',     'prompt' => 'tests.md'],
        'docs'     => ['profile' => 'docs',             'prompt' => 'docs.md'],
        'review'   => ['profile' => 'reasoning_review', 'prompt' => 'review.md'],
    ],

    // ---- EJECUCIÓN ----
    'runtime' => [
        'max_concurrency'  => 3,
        'max_attempts'     => 3,
        'base_ref'         => 'HEAD',
        'prompts_dir'      => __DIR__ . '/prompts',
        'conventions_file' => __DIR__ . '/CONVENTIONS.md', // fuente única; se antepone a cada rol
        'worktrees_dir'    => sys_get_temp_dir() . '/agent-worktrees',
    ],
];
