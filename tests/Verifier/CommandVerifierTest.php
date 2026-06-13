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
}
