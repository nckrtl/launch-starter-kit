<?php

use App\Models\TaskCloseoutOperation;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Orbit\ReacquireTaskSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\TaskCompletionFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->proofCompletionDirectory = storage_path('framework/testing/proof-completion-'.bin2hex(random_bytes(8)));
    $this->completion = new TaskCompletionFixture($this->proofCompletionDirectory);
    $this->landing = $this->completion->add(91);
    $this->completion->land($this->landing);
});

afterEach(fn () => File::deleteDirectory($this->proofCompletionDirectory));

// Construct synthetic retained proof history in the disposable test database.
// Native proof and reacquisition suites exercise how this evidence is produced.
function completionProofHistory(bool $replacement = false): void
{
    $test = test();
    $landing = $test->landing;
    $workspace = $landing->workspace()->firstOrFail();
    $configuration = [...$workspace->configuration,
        'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => $replacement]];
    $proof = ['attempt_id' => str_repeat('f', 32), 'capture_fingerprint' => str_repeat('9', 64)];
    DB::table('task_workspaces')->where('id', $workspace->id)->update([
        'configuration' => Data::json($configuration),
        'final_check' => Data::json([...$workspace->final_check, 'native_proof' => $proof]),
    ]);
    $inputs = [...$landing->inputs, 'schema' => 2, 'native_proof' => $proof,
        'database' => app(TaskLandingEvidence::class)->database($workspace->fresh(), $landing->request)];
    $package = [...$landing->package, 'schema' => 2, 'input_hash' => Data::hash($inputs), 'native_proof' => $proof];
    $hash = Data::hash($package);
    DB::table('task_landings')->where('id', $landing->id)->update([
        'inputs' => Data::json($inputs), 'input_hash' => Data::hash($inputs),
        'package' => Data::json($package), 'package_hash' => $hash,
        'review_result' => Data::json([...$landing->review_result, 'package_hash' => $hash]),
    ]);
    $landing->refresh();
    foreach (TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->get() as $operation) {
        $input = [...$operation->input, 'package_hash' => $hash];
        $result = $operation->result;
        $name = $operation->operation;
        if ($name === 'approval') {
            $input['marker'] = app(TaskCloseoutContext::class)->approvalMarker($landing);
            $result['review_body_hash'] = hash('sha256', $input['marker']);
            $test->completion->remote->approvals[$landing->id] = $result;
        }
        if (str_starts_with($name, 'verify:')) {
            $result['lineage']['flow'] = 'proof';
            $name = 'verify:'.Data::hash($result);
        }
        DB::table('task_closeout_operations')->where('id', $operation->id)->update([
            'package_hash' => $hash, 'input' => Data::json($input), 'input_hash' => Data::hash($input),
            'result' => Data::json($result), 'operation' => $name]);
    }
    $merge = $test->completion->remote->merges[$landing->id];
    $main = $test->completion->remote->mainSha;
    $test->proofCompletionRecord = ['schema' => 1, 'state' => 'complete', 'issue' => 'ORB-91',
        'attempt_id' => $proof['attempt_id'], 'candidate_sha' => $landing->candidate_sha,
        'artifact_sha' => $landing->artifact_sha, 'merge_sha' => $merge['merge_sha'], 'main_sha' => $main,
        'generation_id' => 'fixture-generation', 'error' => null, 'recorded_at' => '2026-09-14T01:00:00Z'];
    app(TaskCloseoutLedger::class)->record($landing, 'proof-closeout:1',
        ['package_hash' => $hash, 'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha,
            'merge_sha' => $merge['merge_sha'], 'main_sha' => $main], $test->proofCompletionRecord);
    $test->proofCompletionArchive = $workspace->repository.'/.e2e/proof-closeout/ORB-91/'.$proof['attempt_id'].'.json';
    File::ensureDirectoryExists(dirname($test->proofCompletionArchive), 0700);
    File::put($test->proofCompletionArchive, Data::json($test->proofCompletionRecord));
    if (! $replacement) {
        $release = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'release')->sole();
        $input = [...$release->input, 'proof_completion_hash' => Data::hash(app(ReacquireTaskSnapshot::class)->completionEvidence($landing))];
        DB::table('task_closeout_operations')->where('id', $release->id)->update([
            'input' => Data::json($input), 'input_hash' => Data::hash($input)]);
    }
}

it('completes nonreplacement proof only with exact archived native closeout', function () {
    completionProofHistory();
    $result = $this->completion->finish($this->landing);
    $proof = $result['current']['native_proof_closeout'];
    expect($result['state'])->toBe('delivered')
        ->and($proof['native_proof_closeout'])->toBe($this->proofCompletionRecord)
        ->and($proof['reacquisition'])->toBeNull()
        ->and($this->completion->writes)->toHaveCount(1)
        ->and(TaskCloseoutOperation::query()->where('operation', 'completion-admission')->sole()->result['binding']['proof_closeout_hash'])
        ->toBe(Data::hash($proof))
        ->and($this->completion->finish($this->landing)['delivery'])->toBe($result['delivery']);
});

it('does not complete replacement proof from installation alone', function () {
    completionProofHistory(true);
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toBe([])
        ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->count())->toBe(0);
});

it('refuses missing uncertain or conflicting native closeout before Linear completion', function (string $change) {
    completionProofHistory();
    $operation = TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole();
    match ($change) {
        'missing operation' => DB::table('task_closeout_operations')->where('id', $operation->id)->delete(),
        'unknown operation' => DB::table('task_closeout_operations')->where('id', $operation->id)->update(['state' => 'unknown', 'result' => null]),
        'missing archive' => File::delete($this->proofCompletionArchive),
        'changed archive' => File::put($this->proofCompletionArchive,
            Data::json([...$this->proofCompletionRecord, 'generation_id' => 'different-generation'])),
        'changed intent' => DB::table('task_closeout_operations')->where('id', $operation->id)->update(['input_hash' => str_repeat('0', 64)]),
    };
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toBe([])
        ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->count())->toBe(0);
})->with(['missing operation', 'unknown operation', 'missing archive', 'changed archive', 'changed intent']);

it('rejects discovery verification for an admitted proof delivery', function () {
    completionProofHistory();
    $operation = TaskCloseoutOperation::query()->where('operation', 'like', 'verify:%')->sole();
    $result = $operation->result;
    $result['lineage']['flow'] = 'discovery';
    DB::table('task_closeout_operations')->where('id', $operation->id)->update([
        'result' => Data::json($result), 'operation' => 'verify:'.Data::hash($result)]);
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toBe([]);
});

it('keeps proof completion stable after accepted worktree removal and later main movement', function () {
    completionProofHistory();
    $first = $this->completion->finish($this->landing);
    File::deleteDirectory($this->landing->workspace()->firstOrFail()->worktree);
    $this->completion->remote->mainSha = sha1('later main');
    expect($this->completion->finish($this->landing)['delivery'])->toBe($first['delivery'])
        ->and($this->completion->writes)->toHaveCount(1);
});

it('requires reservation release to bind the exact completed proof evidence', function (bool $missing) {
    completionProofHistory();
    $release = TaskCloseoutOperation::query()->where('operation', 'release')->sole();
    $input = $release->input;
    if ($missing) {
        unset($input['proof_completion_hash']);
    } else {
        $input['proof_completion_hash'] = str_repeat('0', 64);
    }
    DB::table('task_closeout_operations')->where('id', $release->id)->update([
        'input' => Data::json($input), 'input_hash' => Data::hash($input)]);
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'reservation release')
        ->and($this->completion->writes)->toBe([]);
})->with([true, false]);

it('refuses changed proof evidence after completion admission without replaying Linear', function () {
    completionProofHistory();
    $this->completion->finish($this->landing);
    File::put($this->proofCompletionArchive, Data::json([...$this->proofCompletionRecord, 'generation_id' => 'changed']));
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toHaveCount(1);
});
