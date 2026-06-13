<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Verifier;

use Desalort\Orchestrator\Verifier\CommandVerifier;
use PHPUnit\Framework\TestCase;

final class CommandVerifierTest extends TestCase
{
    public function testPassesOnExitZero(): void
    {
        $verifier = new CommandVerifier();
        $result   = $verifier->verify('exit 0', sys_get_temp_dir());

        self::assertTrue($result->passed);
        self::assertSame(0, $result->exitCode);
    }

    public function testFailsOnNonZeroExitAndCapturesOutput(): void
    {
        $verifier = new CommandVerifier();
        $result   = $verifier->verify('echo "boom" && exit 1', sys_get_temp_dir());

        self::assertFalse($result->passed);
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('boom', $result->output);
    }

    public function testKillsCommandThatExceedsTimeout(): void
    {
        if (trim((string) shell_exec('command -v timeout')) === '') {
            self::markTestSkipped('binario `timeout` no disponible');
        }

        $verifier = new CommandVerifier(timeoutSeconds: 1);
        $result   = $verifier->verify('sleep 5', sys_get_temp_dir());

        self::assertFalse($result->passed);
        self::assertSame(124, $result->exitCode); // timeout(1) devuelve 124 al matar
    }

    public function testCompoundCommandRunsWholeUnderTimeout(): void
    {
        if (trim((string) shell_exec('command -v timeout')) === '') {
            self::markTestSkipped('binario `timeout` no disponible');
        }

        // El comando compuesto entero corre bajo timeout (no solo el primer programa).
        $verifier = new CommandVerifier(timeoutSeconds: 10);
        $result   = $verifier->verify('echo "ok" && exit 0', sys_get_temp_dir());

        self::assertTrue($result->passed);
        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('ok', $result->output);
    }
}
