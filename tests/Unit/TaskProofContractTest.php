<?php

use App\Models\TaskWorkspace;
use App\Tasks\Orbit\Proof\TaskProofContract;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/commander-proof-contract-'.bin2hex(random_bytes(12));
    $this->files = new Filesystem;
    $this->files->ensureDirectoryExists($this->directory.'/.loop/proof');
    $this->files->ensureDirectoryExists($this->directory.'/apps/e2e/resources/guest');
    $this->workspace = new TaskWorkspace(['worktree' => $this->directory, 'source_key' => 'ORB-91']);
    $this->inputs = [
        'apps/e2e/resources/guest/converge-app-prod-internal-tls.sh',
        'apps/e2e/resources/guest/converge-sample-app.sh',
        'apps/e2e/resources/guest/verify-topology.sh',
        'apps/e2e/resources/prepared-state.json',
    ];
    foreach ($this->inputs as $path) {
        $script = str_ends_with($path, '.sh');
        file_put_contents($this->directory.'/'.$path, $script ? "#!/bin/sh\nexit 0\n" : "{}\n");
        chmod($this->directory.'/'.$path, $script ? 0775 : 0664);
    }
    $observer = '.loop/proof/snapshot-candidate-observe.php';
    $contents = "<?php\n// Inert observer regression fixture; never executed.\n";
    $action = ['id' => 'snapshot-candidate-reacquire', 'node' => 'gateway',
        'argv' => ['/usr/bin/php8.5', '/var/lib/orbit-e2e/proof/snapshot-candidate-observe.php', 'reacquire'],
        'timeout_seconds' => 60];
    $package = [
        '.loop/proof/ORB-91.json' => json_encode(['setup' => [], 'acceptance' => [$action],
            'inputs' => $this->inputs, 'snapshot_replacement' => true], JSON_THROW_ON_ERROR),
        '.loop/proof/snapshot-reacquire.json' => json_encode(['schema' => 1,
            'files' => [$observer => ['sha256' => hash('sha256', $contents), 'mode' => '644']],
            'discovery_action' => [...$action, 'argv' => ['/usr/bin/php8.5', '/home/orbit/orbit/'.$observer, 'reacquire']],
            'proof_action' => $action, 'inputs' => $this->inputs], JSON_THROW_ON_ERROR),
        $observer => $contents,
    ];
    foreach ($package as $path => $bytes) {
        file_put_contents($this->directory.'/'.$path, $bytes);
        chmod($this->directory.'/'.$path, 0644);
    }
});

afterEach(function () {
    $this->files->deleteDirectory($this->directory);
});

it('captures the native seven-file shape separately from repository inputs with Git-equivalent modes', function () {
    foreach ([['git', 'init', '--quiet'], ['git', 'add', '--', ...$this->inputs]] as $command) {
        (new Process($command, $this->directory))->mustRun();
    }
    $git = (new Process(['git', 'ls-files', '--stage'], $this->directory))->mustRun()->getOutput();
    $contract = (new TaskProofContract)->read($this->workspace);
    $files = array_column($contract, null, 'path');

    expect($contract)->toHaveCount(7)
        ->and(array_keys($files))->toBe(['.loop/proof/ORB-91.json', ...$this->inputs,
            '.loop/proof/snapshot-candidate-observe.php', '.loop/proof/snapshot-reacquire.json']);
    foreach ($files as $path => $file) {
        expect($file['contents'])->toBe(file_get_contents($this->directory.'/'.$path))
            ->and($file['sha256'])->toBe(hash('sha256', $file['contents']));
        $expectedMode = str_ends_with($path, '.sh') ? '100755' : '100644';
        expect($file['mode'])->toBe($expectedMode);
        if (in_array($path, $this->inputs, true)) {
            expect($git)->toMatch('/^'.$expectedMode.' [a-f0-9]{40} 0\t'.preg_quote($path, '/').'$/m');
        }
    }
});

it('inventories flat native files deterministically without interpreting descriptor semantics', function () {
    file_put_contents($this->directory.'/.loop/proof/snapshot-reacquire.json', '{"schema":999}');
    $reader = new TaskProofContract;
    $before = $reader->read($this->workspace);
    file_put_contents($this->directory.'/.loop/proof/extra.json', '{}');
    chmod($this->directory.'/.loop/proof/extra.json', 0644);
    $after = $reader->read($this->workspace);
    expect($after)->toHaveCount(8)->not->toBe($before)
        ->and($reader->read($this->workspace))->toBe($after);
});

it('preserves non-executable native proof modes while artifact binding owns the exact 0644 gate', function () {
    chmod($this->directory.'/.loop/proof/snapshot-candidate-observe.php', 0664);
    clearstatcache();
    $files = array_column((new TaskProofContract)->read($this->workspace), null, 'path');

    expect($files['.loop/proof/snapshot-candidate-observe.php']['mode'])->toBe('100644');
});

it('rejects unsafe or missing native package and declared source files', function (string $defect) {
    $fixture = $this->directory.'/.loop/proof/snapshot-candidate-observe.php';
    match ($defect) {
        'executable' => chmod($fixture, 0755),
        'nested' => $this->files->ensureDirectoryExists($this->directory.'/.loop/proof/nested'),
        'unsafe-name' => file_put_contents($this->directory.'/.loop/proof/bad..json', '{}'),
        'foreign-plan' => file_put_contents($this->directory.'/.loop/proof/ORB-92.json', '{}'),
        'nul' => file_put_contents($fixture, "unsafe\0bytes"),
        'oversize' => file_put_contents($fixture, str_repeat('x', 1_048_577)),
        'missing-input' => unlink($this->directory.'/'.$this->inputs[0]),
        'symlink' => (function () use ($fixture) {
            unlink($fixture);
            symlink($this->directory.'/'.$this->inputs[0], $fixture);
        })(),
    };
    clearstatcache();

    expect(fn () => (new TaskProofContract)->read($this->workspace))->toThrow(LogicException::class);
})->with(['executable', 'nested', 'unsafe-name', 'foreign-plan', 'nul', 'oversize', 'missing-input', 'symlink']);
