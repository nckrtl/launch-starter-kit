<?php

use App\Models\TaskWorkspace;
use App\Tasks\Runtime\HerdrTaskSessionObserver;
use App\Tasks\Runtime\LinuxTaskAgentProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeHerdrServer;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->procDirectory = sys_get_temp_dir().'/task-proc-'.bin2hex(random_bytes(8));
    File::makeDirectory($this->procDirectory, 0700);
    $this->procServer = null;
});

afterEach(function () {
    $this->procServer?->stop();
    File::deleteDirectory($this->procDirectory);
});

function reconnectProc(int $pid, string $exe, array $argv, array $changes = []): void
{
    $root = test()->procDirectory;
    File::ensureDirectoryExists($root.'/'.$pid.'/fd', 0700);
    File::ensureDirectoryExists($root.'/sys/kernel/random', 0700);
    $fields = array_fill(0, 30, '0');
    $fields[0] = 'S';
    $fields[2] = '30';
    $fields[3] = '20';
    $fields[4] = '34817';
    $fields[5] = '30';
    $fields[19] = '123456';
    foreach ($changes as $index => $value) {
        $fields[$index] = $value;
    }
    File::put($root.'/'.$pid.'/stat', $pid.' (codex) '.implode(' ', $fields));
    $uid = posix_geteuid();
    File::put($root.'/'.$pid.'/status', "Uid:\t$uid\t$uid\t$uid\t$uid\n");
    File::put($root.'/'.$pid.'/cmdline', implode("\0", $argv)."\0");
    File::put($root.'/sys/kernel/random/boot_id', 'fixture-boot');
    symlink('/disposable/worktree', $root.'/'.$pid.'/cwd');
    symlink($exe, $root.'/'.$pid.'/exe');
    symlink('/dev/pts/1', $root.'/'.$pid.'/fd/0');
}

it('verifies the actual Codex foreground process and null native ID without inventing one', function (string $case) {
    $uuid = '11111111-1111-4111-8111-111111111111';
    $arguments = ['-c', 'mcp_servers.orbit-cli-boost.cwd={worktree}/apps/cli'];
    $argv = ['/opt/codex', '-c', 'mcp_servers.orbit-cli-boost.cwd=/disposable/worktree/apps/cli', 'resume', $uuid];
    if ($case === 'fork') {
        $argv[] = '--fork';
    }
    if ($case === 'last') {
        $argv[4] = '--last';
    }
    if ($case === 'uuid') {
        $argv[4] = 'wrong';
    }
    if ($case === 'configuration') {
        $argv[] = '-c';
        $argv[] = 'model=other';
    }
    reconnectProc(30, '/opt/codex', $argv, $case === 'tty' ? [4 => '34818'] : []);
    reconnectProc(20, '/bin/zsh', ['/bin/zsh']);
    if ($case === 'uid') {
        File::put($this->procDirectory.'/30/status', "Uid:\t99999\t99999\t99999\t99999\n");
    }
    if ($case === 'missing') {
        File::delete($this->procDirectory.'/30/stat');
    }
    $session = ['workspaceId' => 'w1', 'tabId' => 't1', 'paneId' => 'p1', 'terminalId' => 'new-term',
        'agentName' => 'worker', 'workingDirectory' => '/disposable/worktree', 'agentId' => null];
    $agent = ['workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1', 'terminal_id' => 'new-term',
        'agent' => 'codex', 'name' => 'worker', 'cwd' => '/disposable/worktree', 'agent_status' => 'idle',
        'agent_session' => $case === 'native' ? ['value' => 'other'] : null];
    $process = ['pid' => 30, 'name' => 'codex', 'argv' => $argv, 'cwd' => '/disposable/worktree'];
    $entries = $case === 'ambiguous' ? [$process, $process] : [$process];
    if ($case === 'wrapper') {
        $entries[0]['name'] = 'node';
    }
    $snapshot = ['version' => '0.8.2', 'protocol' => 20, 'tabs' => [], 'layouts' => [],
        'workspaces' => [['workspace_id' => 'w1', 'worktree' => ['repo_root' => '/disposable/repository', 'checkout_path' => '/disposable/worktree', 'is_linked_worktree' => true]]],
        'panes' => [$agent], 'agents' => [$agent]];
    $this->procServer = FakeHerdrServer::start(['agents' => [], 'workspaces' => [], 'events' => [], 'rpc' => [
        'agent.get' => ['type' => 'agent_info', 'agent' => $agent],
        'session.snapshot' => ['type' => 'session_snapshot', 'snapshot' => $snapshot],
        'pane.process_info' => ['type' => 'pane_process_info', 'process_info' => ['pane_id' => 'p1',
            'shell_pid' => 20, 'foreground_process_group_id' => 30, 'foreground_processes' => $entries]],
    ]]);
    $workspace = new TaskWorkspace(['repository' => '/disposable/repository', 'worktree' => '/disposable/worktree',
        'configuration' => ['socket' => $this->procServer->socketPath, 'agent_arguments' => $arguments, 'agent_kind' => 'codex']]);
    $observer = new HerdrTaskSessionObserver(new LinuxTaskAgentProcess($this->procDirectory));
    $observe = fn () => $observer->observe($workspace, $session, $uuid, true);
    if ($case === 'valid') {
        $result = $observe();
        expect($result['session']['agentId'])->toBeNull()->and($result['process']['pid'])->toBe(30)
            ->and($result['process']['argv'])->toBe($argv)->and($result['process']['start_time'])->toBe('123456');
        $rpc = array_values(array_filter($this->procServer->requests(), fn ($item) => $item['method'] === 'pane.process_info'));
        expect($rpc[0]['params'])->toBe(['pane_id' => 'p1']);
    } else {
        expect($observe)->toThrow(LogicException::class);
    }
    expect(array_column($this->procServer->requests(), 'method'))->not->toContain('agent.start', 'agent.prompt');
})->with(['valid', 'fork', 'last', 'uuid', 'configuration', 'tty', 'uid', 'missing', 'native', 'ambiguous', 'wrapper']);
