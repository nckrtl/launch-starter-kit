<?php

use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Orbit\PrepareTaskSnapshotReacquisition;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function snapshotPreparationGit(string $repository, array $arguments): string
{
    return trim(Process::path($repository)->timeout(30)->env(array_merge(TaskProcessEnvironment::isolated(), [
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
    ]))->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output());
}

function snapshotPreparationFile(string $path, string $contents, int $mode = 0644): void
{
    File::ensureDirectoryExists(dirname($path), 0700);
    File::put($path, $contents);
    chmod($path, $mode);
}

function snapshotPreparationFreeze(): array
{
    return app(PrepareTaskSnapshotReacquisition::class)->freeze(test()->snapshotPreparationIdentity);
}

function snapshotPreparationArtifact(?Closure $amend = null, ?string $parent = null): string
{
    $test = test();
    $repository = $test->snapshotPreparationRepository;
    $fixture = "<?php\n// A descriptor-approved test fixture, not an actual Orbit verification.\n";
    $descriptor = ['schema' => 1, 'files' => ['.loop/proof/snapshot-reacquire.php' => ['sha256' => hash('sha256', $fixture), 'mode' => '755']],
        'discovery_action' => ['id' => 'snapshot-candidate-reacquire', 'node' => 'gateway',
            'argv' => ['php', '/home/orbit/orbit/.loop/proof/snapshot-reacquire.php'], 'timeout_seconds' => 60],
        'proof_action' => ['id' => 'snapshot-candidate-reacquire', 'node' => 'gateway',
            'argv' => ['php', '/var/lib/orbit-e2e/proof/snapshot-reacquire.php'], 'timeout_seconds' => 60],
        'inputs' => ['product.txt']];
    if ($amend !== null) {
        $descriptor = $amend($descriptor);
    }
    snapshotPreparationFile($repository.'/.loop/proof/snapshot-reacquire.php', $fixture, 0755);
    snapshotPreparationFile($repository.'/.loop/proof/snapshot-reacquire.json', Data::json($descriptor));
    snapshotPreparationFile($repository.'/.loop/proof/unapproved.php', "<?php // never restored\n");
    snapshotPreparationFile($repository.'/.loop/proof/ORB-91.json', Data::json(['setup' => [], 'acceptance' => [$descriptor['proof_action']], 'snapshot_replacement' => true]));
    $head = snapshotPreparationGit($repository, ['rev-parse', 'HEAD']);
    snapshotPreparationGit($repository, ['read-tree', $test->snapshotPreparationIdentity['accepted_candidate']]);
    snapshotPreparationGit($repository, ['add', '-f', '.loop']);
    $tree = snapshotPreparationGit($repository, ['write-tree']);
    $artifact = snapshotPreparationGit($repository, ['commit-tree', $tree, '-p', $parent ?? $test->snapshotPreparationIdentity['accepted_candidate'], '-m', 'Fixture native artifact']);
    snapshotPreparationGit($repository, ['read-tree', $head]);
    snapshotPreparationGit($repository, ['update-ref', 'refs/tags/loop/orb-91/'.$test->snapshotPreparationIdentity['accepted_candidate'], $artifact]);
    $identity = $test->snapshotPreparationIdentity;
    $identity['accepted_artifact'] = $artifact;
    $test->snapshotPreparationIdentity = $identity;

    return $artifact;
}

beforeEach(function () {
    $this->snapshotPreparationDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/snapshot-preparation-test-XXXXXXXX'])->throw()->output());
    $repository = $this->snapshotPreparationDirectory.'/repository';
    $root = $this->snapshotPreparationDirectory.'/worktrees';
    $this->snapshotPreparationRepository = $repository;
    File::makeDirectory($repository, 0700);
    File::makeDirectory($root, 0700);
    snapshotPreparationGit($repository, ['init', '--initial-branch=main']);
    snapshotPreparationGit($repository, ['config', 'user.name', 'Fixture']);
    snapshotPreparationGit($repository, ['config', 'user.email', 'fixture@example.test']);
    snapshotPreparationGit($repository, ['config', 'orbit.worktreeRoot', $root]);
    snapshotPreparationFile($repository.'/.gitignore', "/.loop/\n/.e2e/\nvendor\n.env\n**/bootstrap/cache/\n");
    snapshotPreparationFile($repository.'/product.txt', "C product\n");
    foreach (['bin/worktree-cache', 'bin/tia-cache', 'bin/pest-setup', 'bin/e2e-topology', 'bin/loop-artifacts'] as $path) {
        snapshotPreparationFile($repository.'/'.$path, "#!/usr/bin/env php\n<?php exit(0);\n", 0755);
    }
    snapshotPreparationFile($repository.'/bin/pest-support/manifest.json', '{}');
    snapshotPreparationFile($repository.'/bin/pest-support/monorepo.patch', 'fixture patch');
    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        snapshotPreparationFile($repository.'/'.$project.'/composer.json', Data::json(['scripts' => ['guidance:check' => 'ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact']]));
        snapshotPreparationFile($repository.'/'.$project.'/composer.lock', '{}');
        snapshotPreparationFile($repository.'/'.$project.'/.env.example', "APP_ENV=local\n");
        $xml = '<phpunit bootstrap="vendor/autoload.php"><php><env name="APP_ENV" value="testing"/></php></phpunit>';
        snapshotPreparationFile($repository.'/'.$project.'/phpunit.guidance.xml', $xml);
        snapshotPreparationFile($repository.'/'.$project.'/'.(in_array($project, ['apps/cli', 'packages/php-sdk'], true) ? 'phpunit.xml.dist' : 'phpunit.xml'), $xml);
    }
    snapshotPreparationFile($repository.'/bin/bootstrap', <<<'PHP'
        #!/usr/bin/env php
        <?php
        $root = dirname(__DIR__);
        $runtime = $root.'/.e2e/commander-snapshot-bootstrap';
        echo json_encode(array_map(fn ($key) => getenv($key), array_combine(
            ['HOME', 'ORBIT_HOME', 'COMPOSER_HOME', 'TMPDIR', 'XDG_CONFIG_HOME', 'APP_KEY', 'DB_DATABASE', 'DB_URL', 'APP_BASE_PATH', 'COMPOSER_VENDOR_DIR', 'COMMANDER_PREPARATION_SENTINEL', 'BASH_ENV'],
            ['HOME', 'ORBIT_HOME', 'COMPOSER_HOME', 'TMPDIR', 'XDG_CONFIG_HOME', 'APP_KEY', 'DB_DATABASE', 'DB_URL', 'APP_BASE_PATH', 'COMPOSER_VENDOR_DIR', 'COMMANDER_PREPARATION_SENTINEL', 'BASH_ENV']
        )), JSON_THROW_ON_ERROR)."\n";
        fwrite(STDERR, "fixture bootstrap stderr\n");
        foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
            mkdir($root.'/'.$project.'/vendor/composer', 0700, true);
            file_put_contents($root.'/'.$project.'/vendor/autoload.php', '<?php // independently installed fixture');
            file_put_contents($root.'/'.$project.'/vendor/composer/installed.json', '{"packages":[]}');
            copy($root.'/'.$project.'/.env.example', $root.'/'.$project.'/.env');
        }
        PHP, 0755);
    snapshotPreparationGit($repository, ['add', '.']);
    snapshotPreparationGit($repository, ['commit', '-m', 'C fixture']);
    $candidate = snapshotPreparationGit($repository, ['rev-parse', 'HEAD']);
    $accepted = $root.'/orb-91';
    snapshotPreparationGit($repository, ['worktree', 'add', '-b', 'orb-91', $accepted, $candidate]);
    $this->snapshotPreparationIdentity = ['schema' => 1, 'issue' => 'ORB-91', 'repository' => $repository, 'worktree_root' => $root,
        'accepted_worktree' => $accepted, 'accepted_candidate' => $candidate, 'accepted_artifact' => '', 'merged_main' => ''];
    snapshotPreparationArtifact();
    snapshotPreparationFile($repository.'/product.txt', "M product\n");
    snapshotPreparationGit($repository, ['add', 'product.txt']);
    snapshotPreparationGit($repository, ['commit', '-m', 'M fixture']);
    $identity = $this->snapshotPreparationIdentity;
    $identity['merged_main'] = snapshotPreparationGit($repository, ['rev-parse', 'HEAD']);
    $this->snapshotPreparationIdentity = $identity;
});

afterEach(function () {
    $directory = $this->snapshotPreparationDirectory;
    if (is_string($directory) && str_starts_with($directory, sys_get_temp_dir().'/snapshot-preparation-test-') && realpath($directory) === $directory) {
        File::deleteDirectory($directory);
    }
});

test('freezes only artifact-approved fixtures and creates a distinct deterministic M checkout while C remains untouched', function () {
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $pins = snapshotPreparationFreeze();
    $candidate = $pins['accepted_candidate'];
    $accepted = $pins['accepted_worktree'];
    snapshotPreparationFile($accepted.'/.loop/proof/snapshot-reacquire.php', 'mutable ignored C file, not artifact A');
    expect($pins['validation_branch'])->toBe('orb-91-postinstall-'.substr($pins['merged_main'], 0, 12))
        ->and($helper->inspect($pins)['state'])->toBe('absent')
        ->and($helper->inspectReadiness($pins)['state'])->toBe('not_started');
    $beforeTags = snapshotPreparationGit($pins['repository'], ['show-ref', '--tags']);
    expect($helper->executeOnce($pins)['state'])->toBe('prepared')
        ->and(snapshotPreparationGit($accepted, ['rev-parse', 'HEAD']))->toBe($candidate)
        ->and(File::get($accepted.'/product.txt'))->toBe("C product\n")
        ->and(File::get($accepted.'/.loop/proof/snapshot-reacquire.php'))->toBe('mutable ignored C file, not artifact A')
        ->and(File::get($pins['validation_worktree'].'/product.txt'))->toBe("M product\n")
        ->and(snapshotPreparationGit($pins['repository'], ['show-ref', '--tags']))->toBe($beforeTags)
        ->and(file_exists($pins['validation_worktree'].'/.loop/proof/unapproved.php'))->toBeFalse();
    $plan = json_decode(File::get($pins['validation_worktree'].'/.loop/proof/ORB-91.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($plan)->toBe(['setup' => [], 'acceptance' => [$pins['proof_action']], 'snapshot_replacement' => false, 'inputs' => ['product.txt']])
        ->and($helper->inspect($pins)['state'])->toBe('prepared');
    expect(fn () => $helper->executeOnce($pins))->toThrow(LogicException::class, 'create-only');
});

test('refuses invalid artifact-approved descriptors without creating a target', function (Closure $amend) {
    snapshotPreparationArtifact($amend);
    expect(fn () => snapshotPreparationFreeze())->toThrow(Exception::class);
    expect(count(File::directories($this->snapshotPreparationIdentity['worktree_root'])))->toBe(1);
})->with([
    'wrong hash' => [fn ($d) => array_replace($d, ['files' => ['.loop/proof/snapshot-reacquire.php' => ['sha256' => str_repeat('0', 64), 'mode' => '755']]])],
    'wrong mode' => [fn ($d) => array_replace($d, ['files' => ['.loop/proof/snapshot-reacquire.php' => ['sha256' => $d['files']['.loop/proof/snapshot-reacquire.php']['sha256'], 'mode' => '644']]])],
    'nested fixture' => [fn ($d) => array_replace($d, ['files' => ['.loop/proof/fixtures/a.php' => $d['files']['.loop/proof/snapshot-reacquire.php']]])],
    'self hash' => [fn ($d) => array_replace($d, ['files' => ['.loop/proof/snapshot-reacquire.json' => $d['files']['.loop/proof/snapshot-reacquire.php']]])],
    'unapproved key' => [fn ($d) => array_replace($d, ['setup' => []])],
    'bad action' => [fn ($d) => array_replace($d, ['proof_action' => array_replace($d['proof_action'], ['timeout_seconds' => 901])])],
    'injected argv line' => [fn ($d) => array_replace($d, ['discovery_action' => array_replace($d['discovery_action'], ['argv' => ['php', "first\nsecond"]])])],
    'escaping inputs' => [fn ($d) => array_replace($d, ['inputs' => ['../outside']])],
]);

test('refuses wrong artifact parent and changed native tag binding', function () {
    $pins = snapshotPreparationFreeze();
    snapshotPreparationArtifact(parent: $pins['merged_main']);
    expect(fn () => snapshotPreparationFreeze())->toThrow(LogicException::class, 'sole-parent');
    snapshotPreparationGit($pins['repository'], ['update-ref', 'refs/tags/loop/orb-91/'.$pins['accepted_candidate'], $pins['merged_main']]);
    expect(fn () => app(PrepareTaskSnapshotReacquisition::class)->inspect($pins))->toThrow(LogicException::class, 'sole-parent');
});

test('refuses a product-modifying artifact', function () {
    $pins = snapshotPreparationFreeze();
    snapshotPreparationGit($pins['repository'], ['update-ref', 'refs/tags/loop/orb-91/'.$pins['accepted_candidate'], $pins['merged_main']]);
    $identity = $this->snapshotPreparationIdentity;
    $identity['accepted_artifact'] = $pins['merged_main'];
    expect(fn () => app(PrepareTaskSnapshotReacquisition::class)->freeze($identity))->toThrow(LogicException::class, 'only add');
});

test('refuses amended frozen requests and changed accepted checkout', function () {
    $pins = snapshotPreparationFreeze();
    $amended = array_replace($pins, ['validation_worktree' => $pins['worktree_root'].'/foreign']);
    expect(fn () => app(PrepareTaskSnapshotReacquisition::class)->executeOnce($amended))->toThrow(LogicException::class, 'frozen');
    snapshotPreparationFile($pins['accepted_worktree'].'/product.txt', 'dirty C');
    expect(fn () => app(PrepareTaskSnapshotReacquisition::class)->executeOnce($pins))->toThrow(RuntimeException::class, 'clean');
});

test('preserves partial and foreign creation targets without retry', function (string $kind) {
    $pins = snapshotPreparationFreeze();
    if ($kind === 'directory') {
        snapshotPreparationFile($pins['validation_worktree'].'/foreign.txt', 'preserve');
    } elseif ($kind === 'branch') {
        snapshotPreparationGit($pins['repository'], ['branch', $pins['validation_branch'], $pins['merged_main']]);
    } elseif ($kind === 'other registration') {
        snapshotPreparationGit($pins['repository'], ['worktree', 'add', '-b', $pins['validation_branch'], $pins['worktree_root'].'/foreign', $pins['merged_main']]);
    } else {
        snapshotPreparationGit($pins['repository'], ['worktree', 'add', '-b', $pins['validation_branch'], $pins['validation_worktree'], $pins['merged_main']]);
    }
    $before = snapshotPreparationGit($pins['repository'], ['worktree', 'list', '--porcelain']);
    expect(fn () => app(PrepareTaskSnapshotReacquisition::class)->executeOnce($pins))->toThrow(Exception::class);
    expect(snapshotPreparationGit($pins['repository'], ['worktree', 'list', '--porcelain']))->toBe($before);
    if ($kind === 'directory') {
        expect(File::get($pins['validation_worktree'].'/foreign.txt'))->toBe('preserve');
    }
})->with(['directory', 'branch', 'other registration', 'partial checkout']);

test('refuses external smudge filters before checkout', function () {
    snapshotPreparationGit($this->snapshotPreparationRepository, ['config', 'filter.fixture.smudge', 'touch /not-authorized']);
    expect(fn () => snapshotPreparationFreeze())->toThrow(LogicException::class, 'filters');
});

test('rejects changed prepared artifact inventory and modes', function (string $kind) {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    $path = $pins['validation_worktree'].'/.loop/proof/snapshot-reacquire.php';
    match ($kind) {
        'contents' => File::put($path, 'changed'),
        'mode' => chmod($path, 0644),
        'extra file' => File::put(dirname($path).'/extra.php', 'extra'),
        'extra directory' => mkdir(dirname($path).'/extra'),
        'hardlink' => link($path, dirname($path).'/ignored-hardlink.php'),
    };
    expect(fn () => $helper->inspect($pins))->toThrow(Exception::class);
})->with(['contents', 'mode', 'extra file', 'extra directory', 'hardlink']);

test('bootstraps once with isolated writable state and checks current dependencies plus observed exit and logs', function () {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    putenv('COMMANDER_PREPARATION_SENTINEL=private-inherited-value');
    putenv('COMPOSER_VENDOR_DIR=/not-authorized');
    try {
        $ready = $helper->bootstrapOnce($pins);
    } finally {
        putenv('COMMANDER_PREPARATION_SENTINEL');
        putenv('COMPOSER_VENDOR_DIR');
    }
    $environment = json_decode(trim(File::get($pins['bootstrap_directory'].'/stdout.log')), true, flags: JSON_THROW_ON_ERROR);
    expect($ready['state'])->toBe('ready')
        ->and($environment['HOME'])->toBe(getenv('HOME'))
        ->and($environment['ORBIT_HOME'])->toBe($pins['bootstrap_directory'].'/orbit')
        ->and($environment['COMPOSER_HOME'])->toBe($pins['bootstrap_directory'].'/composer')
        ->and($environment['TMPDIR'])->toBe($pins['bootstrap_directory'].'/temporary')
        ->and($environment['XDG_CONFIG_HOME'])->toBe($pins['bootstrap_directory'].'/config')
        ->and($environment['DB_DATABASE'])->toBe(':memory:')->and($environment['DB_URL'])->toBe('')
        ->and($environment['APP_BASE_PATH'])->toBeFalse()->and($environment['COMPOSER_VENDOR_DIR'])->toBeFalse()
        ->and($environment['COMMANDER_PREPARATION_SENTINEL'])->toBeFalse()->and($environment['BASH_ENV'])->toBeFalse()
        ->and($environment['APP_KEY'])->toStartWith('base64:')
        ->and($helper->inspectReadiness($pins)['state'])->toBe('ready');
    expect(fn () => $helper->bootstrapOnce($pins))->toThrow(LogicException::class, 'already attempted');
    File::put($pins['validation_worktree'].'/apps/e2e/vendor/autoload.php', '<?php // changed after ready');
    expect(fn () => $helper->inspectReadiness($pins))->toThrow(LogicException::class, 'dependency state changed');
});

test('retains nonzero bootstrap output and does not replay failed bootstrap', function () {
    snapshotPreparationFile($this->snapshotPreparationRepository.'/bin/bootstrap', "#!/usr/bin/env php\n<?php echo 'failed output'; fwrite(STDERR, 'failure reason'); exit(17);\n", 0755);
    snapshotPreparationGit($this->snapshotPreparationRepository, ['add', 'bin/bootstrap']);
    snapshotPreparationGit($this->snapshotPreparationRepository, ['commit', '-m', 'Failing M bootstrap fixture']);
    $identity = $this->snapshotPreparationIdentity;
    $identity['merged_main'] = snapshotPreparationGit($this->snapshotPreparationRepository, ['rev-parse', 'HEAD']);
    $pins = app(PrepareTaskSnapshotReacquisition::class)->freeze($identity);
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    expect($helper->bootstrapOnce($pins)['state'])->toBe('failed')
        ->and($helper->inspectReadiness($pins)['exit']['exit_code'])->toBe(17)
        ->and(File::get($pins['bootstrap_directory'].'/stderr.log'))->toBe('failure reason');
    expect(fn () => $helper->bootstrapOnce($pins))->toThrow(LogicException::class, 'already attempted');
});

test('does not equate a success marker or dependency marker with known readiness', function () {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    $helper->bootstrapOnce($pins);
    File::delete($pins['bootstrap_directory'].'/exit.json');
    expect($helper->inspectReadiness($pins)['state'])->toBe('unknown');
    expect(fn () => $helper->bootstrapOnce($pins))->toThrow(LogicException::class, 'already attempted');
});

test('refuses pre-existing shared or overridden bootstrap state', function (string $kind) {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    $base = $pins['validation_worktree'].'/apps/e2e';
    if ($kind === 'vendor link') {
        symlink($pins['accepted_worktree'], $base.'/vendor');
    } elseif ($kind === 'env') {
        File::put($base.'/.env', 'APP_ENV=production');
    } else {
        snapshotPreparationFile($base.'/bootstrap/cache/config.php', '<?php return [];');
    }
    expect(fn () => $helper->bootstrapOnce($pins))->toThrow(LogicException::class, 'absent local dependencies')
        ->and(file_exists($pins['bootstrap_directory']))->toBeFalse();
})->with(['vendor link', 'env', 'cached config']);

test('removes only the exact auxiliary worktree then compare-deletes its branch with read-only reconciliation', function () {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    $helper->bootstrapOnce($pins);
    expect(fn () => $helper->removeBranchOnce($pins))->toThrow(LogicException::class, 'before its branch');
    expect($helper->inspectRemoval($pins)['state'])->toBe('prepared')
        ->and($helper->removeWorktreeOnce($pins)['state'])->toBe('worktree_removed')
        ->and(file_exists($pins['validation_worktree']))->toBeFalse()
        ->and($helper->inspectRemoval($pins)['state'])->toBe('worktree_removed');
    expect(fn () => $helper->removeWorktreeOnce($pins))->toThrow(LogicException::class, 'single-attempt');
    expect($helper->removeBranchOnce($pins)['state'])->toBe('removed')
        ->and(snapshotPreparationGit($pins['accepted_worktree'], ['rev-parse', 'HEAD']))->toBe($pins['accepted_candidate'])
        ->and($helper->inspectRemoval($pins)['state'])->toBe('removed');
    expect(fn () => $helper->removeBranchOnce($pins))->toThrow(LogicException::class, 'never replay');
});

test('refuses cleanup of dirty moved or foreign auxiliary state', function (string $kind) {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    if ($kind === 'tracked') {
        File::put($pins['validation_worktree'].'/product.txt', 'dirty');
    } elseif ($kind === 'untracked') {
        File::put($pins['validation_worktree'].'/user.txt', 'retain');
    } elseif ($kind === 'moved head') {
        snapshotPreparationGit($pins['validation_worktree'], ['commit', '--allow-empty', '-m', 'Moved auxiliary']);
    } else {
        snapshotPreparationGit($pins['repository'], ['worktree', 'move', $pins['validation_worktree'], $pins['worktree_root'].'/foreign']);
    }
    expect(fn () => $helper->removeWorktreeOnce($pins))->toThrow(Exception::class);
    expect(is_dir($pins['accepted_worktree']))->toBeTrue();
})->with(['tracked', 'untracked', 'moved head', 'foreign path']);

test('refuses branch deletion if it changes after auxiliary removal', function () {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    $helper->removeWorktreeOnce($pins);
    snapshotPreparationGit($pins['repository'], ['update-ref', 'refs/heads/'.$pins['validation_branch'], $pins['accepted_candidate']]);
    expect(fn () => $helper->removeBranchOnce($pins))->toThrow(LogicException::class, 'branch moved');
    expect(snapshotPreparationGit($pins['repository'], ['rev-parse', 'refs/heads/'.$pins['validation_branch']]))->toBe($pins['accepted_candidate']);
});

test('refuses shared dependency hardlinks and escaped dependency links after bootstrap', function (string $kind) {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    $helper->bootstrapOnce($pins);
    if ($kind === 'vendor hardlink') {
        link($pins['validation_worktree'].'/apps/e2e/vendor/autoload.php', $this->snapshotPreparationDirectory.'/shared-autoload.php');
    } elseif ($kind === 'env hardlink') {
        link($pins['validation_worktree'].'/apps/e2e/.env', $this->snapshotPreparationDirectory.'/shared-env');
    } else {
        symlink($pins['accepted_worktree'], $pins['validation_worktree'].'/apps/e2e/vendor/escaped');
    }
    expect(fn () => $helper->inspectReadiness($pins))->toThrow(LogicException::class);
})->with(['vendor hardlink', 'env hardlink', 'escaped link']);

test('preserves unknown incomplete removal and a foreign recreation of the exact target path', function (string $kind) {
    $pins = snapshotPreparationFreeze();
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($pins);
    if ($kind === 'stale registration') {
        rename($pins['validation_worktree'], $pins['validation_worktree'].'-retained');
    } else {
        $helper->removeWorktreeOnce($pins);
        snapshotPreparationFile($pins['validation_worktree'].'/user.txt', 'foreign replacement must remain');
    }
    expect(fn () => $helper->inspectRemoval($pins))->toThrow(LogicException::class);
    expect(fn () => $helper->removeBranchOnce($pins))->toThrow(LogicException::class);
    if ($kind === 'foreign recreation') {
        expect(File::get($pins['validation_worktree'].'/user.txt'))->toBe('foreign replacement must remain');
    }
})->with(['stale registration', 'foreign recreation']);
