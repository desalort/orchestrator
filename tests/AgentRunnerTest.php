<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests;

use Desalort\LlmGateway\Data\LlmResponse;
use Desalort\LlmGateway\Data\Message;
use Desalort\LlmGateway\Data\Usage;
use Desalort\LlmGateway\LlmGateway;
use Desalort\Orchestrator\AgentRunner;
use Desalort\Orchestrator\Contracts\WorkspaceInterface;
use Desalort\Orchestrator\Data\ModelProfile;
use Desalort\Orchestrator\Data\Task;
use Desalort\Orchestrator\Data\TaskStatus;
use Desalort\Orchestrator\Data\VerifierResult;
use Desalort\Orchestrator\Tests\Support\FakeLlmProvider;
use Desalort\Orchestrator\Tests\Support\InMemoryWorkspace;
use Desalort\Orchestrator\Tests\Support\QueueVerifier;
use PHPUnit\Framework\TestCase;

final class AgentRunnerTest extends TestCase
{
    private function task(): Task
    {
        return new Task(
            id: 'task-1',
            role: 'codegen',
            instruction: 'Implementa Foo',
            verifyCommand: 'vendor/bin/phpunit',
        );
    }

    private function profile(): ModelProfile
    {
        return new ModelProfile(
            providerName: 'fake',
            driver: 'openai_compatible',
            baseUrl: 'http://example.test',
            apiKey: 'x',
            model: 'fake-model',
            temperature: 0.1,
            maxTokens: 1000,
            timeout: 10,
            costInputPer1M: 1.0,
            costOutputPer1M: 2.0,
        );
    }

    private function writeFilesToolCall(string $id, array $files): LlmResponse
    {
        $arguments = ['files' => $files];

        return new LlmResponse(
            content: '',
            toolCalls: [
                new \Desalort\LlmGateway\Data\ToolCall(
                    id: $id,
                    name: 'write_files',
                    arguments: $arguments,
                    rawArguments: json_encode($arguments, JSON_THROW_ON_ERROR),
                ),
            ],
            usage: new Usage(100, 50, 150),
            finishReason: 'tool_calls',
            model: 'fake-model',
            provider: 'fake',
            latencyMs: 1.0,
        );
    }

    public function testSucceedsOnFirstAttempt(): void
    {
        $provider = new FakeLlmProvider([
            $this->writeFilesToolCall('call-1', [
                ['path' => 'src/Foo.php', 'content' => '<?php // foo'],
            ]),
        ]);
        $workspace = new InMemoryWorkspace();
        $verifier  = new QueueVerifier([
            new VerifierResult(passed: true, output: 'OK', exitCode: 0),
        ]);

        $runner = new AgentRunner(
            gateway:      new LlmGateway($provider),
            workspace:    $workspace,
            verifier:     $verifier,
            systemPrompt: 'Eres un agente.',
            maxAttempts:  3,
            baseRef:      'HEAD',
        );

        $result = $runner->run($this->task(), $this->profile());

        self::assertSame(TaskStatus::Done, $result->status);
        self::assertSame(1, $result->attempts);
        self::assertSame('agent/task-1', $result->branch);
        self::assertSame('OK', $result->lastOutput);
        self::assertSame(150, $result->usage->totalTokens);
        self::assertSame('<?php // foo', $workspace->files['src/Foo.php']);
        // 100/1e6 * 1.0 + 50/1e6 * 2.0 = 0.0002
        self::assertEqualsWithDelta(0.0002, $result->costUsd, 1e-9);
    }

    public function testRetriesAfterVerifierFailureAndSucceeds(): void
    {
        $provider = new FakeLlmProvider([
            $this->writeFilesToolCall('call-1', [
                ['path' => 'src/Foo.php', 'content' => 'primera version'],
            ]),
            $this->writeFilesToolCall('call-2', [
                ['path' => 'src/Foo.php', 'content' => 'segunda version'],
            ]),
        ]);
        $workspace = new InMemoryWorkspace();
        $verifier  = new QueueVerifier([
            new VerifierResult(passed: false, output: 'Error: falla el test', exitCode: 1),
            new VerifierResult(passed: true, output: 'OK', exitCode: 0),
        ]);

        $runner = new AgentRunner(
            gateway:      new LlmGateway($provider),
            workspace:    $workspace,
            verifier:     $verifier,
            systemPrompt: 'Eres un agente.',
            maxAttempts:  3,
            baseRef:      'HEAD',
        );

        $result = $runner->run($this->task(), $this->profile());

        self::assertSame(TaskStatus::Done, $result->status);
        self::assertSame(2, $result->attempts);
        self::assertSame('segunda version', $workspace->files['src/Foo.php']);
        // usage acumulada de ambas vueltas: (100+100, 50+50, 150+150)
        self::assertSame(300, $result->usage->totalTokens);

        // El segundo request debe llevar el historial con el tool call previo
        // y el resultado del verificador realimentado como tool result.
        $secondRequest = $provider->requests[1];
        $roles         = array_map(static fn (Message $m): string => $m->role, $secondRequest->messages);

        self::assertContains('assistant', $roles);
        self::assertContains('tool', $roles);

        $toolMessages = array_values(array_filter(
            $secondRequest->messages,
            static fn (Message $m): bool => $m->role === 'tool',
        ));
        self::assertNotEmpty($toolMessages);
        self::assertStringContainsString('Error: falla el test', (string) $toolMessages[0]->content);
    }

    public function testFailsAfterExhaustingAttempts(): void
    {
        $provider = new FakeLlmProvider([
            $this->writeFilesToolCall('call-1', [['path' => 'a.php', 'content' => 'v1']]),
            $this->writeFilesToolCall('call-2', [['path' => 'a.php', 'content' => 'v2']]),
        ]);
        $workspace = new InMemoryWorkspace();
        $verifier  = new QueueVerifier([
            new VerifierResult(passed: false, output: 'falla 1', exitCode: 1),
            new VerifierResult(passed: false, output: 'falla 2', exitCode: 1),
        ]);

        $runner = new AgentRunner(
            gateway:      new LlmGateway($provider),
            workspace:    $workspace,
            verifier:     $verifier,
            systemPrompt: 'Eres un agente.',
            maxAttempts:  2,
            baseRef:      'HEAD',
        );

        $result = $runner->run($this->task(), $this->profile());

        self::assertSame(TaskStatus::Failed, $result->status);
        self::assertSame(2, $result->attempts);
        self::assertSame('falla 2', $result->lastOutput);
    }

    public function testRequestsWriteFilesAgainWhenNoToolCallReturned(): void
    {
        $emptyResponse = new LlmResponse(
            content: 'No voy a usar tools.',
            toolCalls: [],
            usage: new Usage(10, 5, 15),
            finishReason: 'stop',
            model: 'fake-model',
            provider: 'fake',
            latencyMs: 1.0,
        );

        $provider = new FakeLlmProvider([
            $emptyResponse,
            $this->writeFilesToolCall('call-1', [['path' => 'a.php', 'content' => 'v1']]),
        ]);
        $workspace = new InMemoryWorkspace();
        $verifier  = new QueueVerifier([
            new VerifierResult(passed: true, output: 'OK', exitCode: 0),
        ]);

        $runner = new AgentRunner(
            gateway:      new LlmGateway($provider),
            workspace:    $workspace,
            verifier:     $verifier,
            systemPrompt: 'Eres un agente.',
            maxAttempts:  3,
            baseRef:      'HEAD',
        );

        $result = $runner->run($this->task(), $this->profile());

        self::assertSame(TaskStatus::Done, $result->status);
        // La respuesta sin tool call cuenta como intento 1; el éxito llega en el 2.
        self::assertSame(2, $result->attempts);
        self::assertSame(['a.php' => 'v1'], $workspace->files);
        self::assertCount(1, $verifier->commands);

        $secondRequest = $provider->requests[1];
        $roles         = array_map(static fn (Message $m): string => $m->role, $secondRequest->messages);
        self::assertContains('user', $roles);
    }

    public function testInjectsCurrentScopeFileContentIntoPrompt(): void
    {
        // Workspace real con un esqueleto en el ámbito: el prompt debe incluir su contenido
        // para que el agente conserve firma/imports en vez de reconstruirlos de memoria.
        $dir = sys_get_temp_dir() . '/orch-scope-' . uniqid('', true);
        mkdir($dir . '/src', 0775, true);
        $stub = "<?php\n\nnamespace App;\n\nuse App\\Contracts\\FooInterface;\n\nfinal class Foo implements FooInterface\n{\n    public function bar(): int { throw new \\LogicException('TODO'); }\n}\n";
        file_put_contents($dir . '/src/Foo.php', $stub);

        $workspace = new class($dir) implements WorkspaceInterface {
            public function __construct(private readonly string $dir)
            {
            }

            public function setUp(string $taskId, string $baseRef): string
            {
                return $this->dir;
            }

            public function applyEdits(string $taskId, array $files): void
            {
            }

            public function branchName(string $taskId): string
            {
                return 'agent/' . $taskId;
            }

            public function tearDown(string $taskId): void
            {
            }
        };

        $provider = new FakeLlmProvider([
            $this->writeFilesToolCall('call-1', [['path' => 'src/Foo.php', 'content' => '<?php // done']]),
        ]);
        $verifier = new QueueVerifier([new VerifierResult(passed: true, output: 'OK', exitCode: 0)]);

        $task = new Task(
            id: 'task-scope',
            role: 'codegen',
            instruction: 'Implementa Foo::bar',
            verifyCommand: 'vendor/bin/phpunit',
            scopePaths: ['src/Foo.php'],
        );

        $runner = new AgentRunner(
            gateway:      new LlmGateway($provider),
            workspace:    $workspace,
            verifier:     $verifier,
            systemPrompt: 'Eres un agente.',
            maxAttempts:  3,
            baseRef:      'HEAD',
        );

        $runner->run($task, $this->profile());

        $userMessages = array_values(array_filter(
            $provider->requests[0]->messages,
            static fn (Message $m): bool => $m->role === 'user',
        ));
        self::assertNotEmpty($userMessages);
        $prompt = (string) $userMessages[0]->content;

        self::assertStringContainsString('FICHEROS ACTUALES DE TU ÁMBITO', $prompt);
        self::assertStringContainsString('use App\\Contracts\\FooInterface;', $prompt);
        self::assertStringContainsString('final class Foo implements FooInterface', $prompt);

        @unlink($dir . '/src/Foo.php');
        @rmdir($dir . '/src');
        @rmdir($dir);
    }
}
