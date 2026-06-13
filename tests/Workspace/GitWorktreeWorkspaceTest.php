<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Tests\Workspace;

use Desalort\Orchestrator\Workspace\GitWorktreeWorkspace;
use PHPUnit\Framework\TestCase;

final class GitWorktreeWorkspaceTest extends TestCase
{
    private string $repoRoot;
    private string $worktreesDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/orchestrator-test-' . uniqid('', true);
        $this->repoRoot     = $base . '/repo';
        $this->worktreesDir = $base . '/worktrees';

        mkdir($this->repoRoot, 0775, true);
        mkdir($this->worktreesDir, 0775, true);

        $this->runGit($this->repoRoot, 'git init -q -b main');
        $this->runGit($this->repoRoot, 'git config user.email test@example.test');
        $this->runGit($this->repoRoot, 'git config user.name "Test"');
        file_put_contents($this->repoRoot . '/README.md', "inicial\n");
        $this->runGit($this->repoRoot, 'git add -A');
        $this->runGit($this->repoRoot, 'git commit -q -m inicial');
    }

    protected function tearDown(): void
    {
        $this->runGit(dirname($this->repoRoot), 'rm -rf ' . escapeshellarg($this->repoRoot) . ' ' . escapeshellarg($this->worktreesDir));
    }

    public function testSetUpCreatesWorktreeAndBranch(): void
    {
        $workspace = new GitWorktreeWorkspace($this->repoRoot, $this->worktreesDir);

        $path = $workspace->setUp('task-1', 'main');

        self::assertDirectoryExists($path);
        self::assertSame('agent/task-1', $workspace->branchName('task-1'));

        $branches = $this->runGit($this->repoRoot, 'git branch --no-color');
        self::assertStringContainsString('agent/task-1', $branches);

        $workspace->tearDown('task-1');
    }

    public function testApplyEditsWritesAndCommitsFiles(): void
    {
        $workspace = new GitWorktreeWorkspace($this->repoRoot, $this->worktreesDir);
        $path      = $workspace->setUp('task-2', 'main');

        $workspace->applyEdits('task-2', ['src/Foo.php' => '<?php // foo']);

        self::assertSame('<?php // foo', file_get_contents($path . '/src/Foo.php'));

        $log = $this->runGit($path, 'git log --oneline -1');
        self::assertStringContainsString('agent: task-2', $log);

        $workspace->tearDown('task-2');
    }

    public function testTearDownRemovesWorktreeButKeepsBranch(): void
    {
        $workspace = new GitWorktreeWorkspace($this->repoRoot, $this->worktreesDir);
        $path      = $workspace->setUp('task-3', 'main');

        $workspace->tearDown('task-3');

        self::assertDirectoryDoesNotExist($path);

        $branches = $this->runGit($this->repoRoot, 'git branch --no-color');
        self::assertStringContainsString('agent/task-3', $branches);
    }

    private function runGit(string $dir, string $cmd): string
    {
        $full = 'cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>&1';
        $out  = [];
        $code = 0;
        exec($full, $out, $code);
        self::assertSame(0, $code, "Comando falló ({$cmd}):\n" . implode("\n", $out));

        return implode("\n", $out);
    }
}
