<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Provider;

use Desalort\Orchestrator\Provider\ProviderFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderFactoryTest extends TestCase
{
    private const ENV_VAR = 'ORCHESTRATOR_TEST_API_KEY';

    protected function tearDown(): void
    {
        putenv(self::ENV_VAR);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return [
            'providers' => [
                'together' => [
                    'driver'      => 'openai_compatible',
                    'base_url'    => 'https://api.together.xyz/v1',
                    'api_key_env' => self::ENV_VAR,
                    'timeout'     => 180,
                ],
                'ollama' => [
                    'driver'      => 'openai_compatible',
                    'base_url'    => 'http://localhost:11434/v1',
                    'api_key_env' => null,
                    'timeout'     => 600,
                ],
            ],
            'profiles' => [
                'codegen_fast' => [
                    'provider'           => 'together',
                    'model'              => 'Qwen/Qwen3-Coder-30B',
                    'temperature'        => 0.1,
                    'max_tokens'         => 8000,
                    'cost_input_per_1m'  => 0.2,
                    'cost_output_per_1m' => 0.8,
                ],
                'codegen_local' => [
                    'provider'    => 'ollama',
                    'model'       => 'qwen3-coder',
                    'temperature' => 0.1,
                    'max_tokens'  => 8000,
                ],
            ],
            'roles' => [
                'codegen' => ['profile' => 'codegen_fast', 'prompt' => 'codegen.md'],
                'local'   => ['profile' => 'codegen_local', 'prompt' => 'codegen.md'],
            ],
        ];
    }

    public function testResolvesRoleToProfileWithCostFields(): void
    {
        putenv(self::ENV_VAR . '=secret');

        $profile = (new ProviderFactory($this->config()))->profileForRole('codegen');

        self::assertSame('together', $profile->providerName);
        self::assertSame('openai_compatible', $profile->driver);
        self::assertSame('https://api.together.xyz/v1', $profile->baseUrl);
        self::assertSame('secret', $profile->apiKey);
        self::assertSame('Qwen/Qwen3-Coder-30B', $profile->model);
        self::assertSame(0.2, $profile->costInputPer1M);
        self::assertSame(0.8, $profile->costOutputPer1M);
    }

    public function testDefaultsCostFieldsToZeroWhenMissing(): void
    {
        $profile = (new ProviderFactory($this->config()))->profileForRole('local');

        self::assertSame(0.0, $profile->costInputPer1M);
        self::assertSame(0.0, $profile->costOutputPer1M);
        self::assertNull($profile->apiKey);
    }

    public function testThrowsOnUnknownRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProviderFactory($this->config()))->profileForRole('inexistente');
    }

    public function testThrowsOnUnknownProfile(): void
    {
        $config = $this->config();
        $config['roles']['broken'] = ['profile' => 'no-existe', 'prompt' => 'x.md'];

        $this->expectException(InvalidArgumentException::class);
        (new ProviderFactory($config))->profileForRole('broken');
    }

    public function testThrowsOnUnknownProvider(): void
    {
        $config = $this->config();
        $config['profiles']['codegen_fast']['provider'] = 'no-existe';

        $this->expectException(InvalidArgumentException::class);
        (new ProviderFactory($config))->profileForRole('codegen');
    }

    public function testThrowsWhenApiKeyEnvVarIsMissing(): void
    {
        putenv(self::ENV_VAR); // asegura que no está definida

        $this->expectException(RuntimeException::class);
        (new ProviderFactory($this->config()))->profileForRole('codegen');
    }
}
