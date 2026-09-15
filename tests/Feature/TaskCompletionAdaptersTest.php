<?php

use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Tasks\Completion\NativeTaskCompletionIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\TaskCompletionFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->completionDirectory = storage_path('framework/testing/completion-rpc-'.bin2hex(random_bytes(8)));
    $this->completion = new TaskCompletionFixture($this->completionDirectory);
    $this->landing = $this->completion->add();
    $this->nativeIssue = app(NativeTaskCompletionIssue::class);
    $this->rpcRequests = [];
    $this->rpcExit = 0;
    $this->rpcResponse = ['data' => ['viewer' => ['id' => config('commander.hermes.tom_linear_viewer_id')],
        'issue' => $this->completion->base->issues[$this->landing->issue_id]]];
    $_ENV['TASK_COMPLETION_PRIVATE_FIXTURE'] = 'must-not-reach-child';
    $_ENV['GIT_CONFIG_COUNT'] = '1';
    Process::fake(function ($process) {
        expect($process->command)->toBe(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'tom@mini',
            "'/Users/tom/.hermes/profiles/tom/scripts/orbit_delivery_loop.py' --rpc"])
            ->and($process->timeout)->toBe(30)
            ->and($process->environment['TASK_COMPLETION_PRIVATE_FIXTURE'] ?? null)->toBeFalse()
            ->and($process->environment['GIT_CONFIG_COUNT'] ?? null)->toBeFalse();
        $this->rpcRequests[] = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

        return Process::result(output: is_string($this->rpcResponse) ? $this->rpcResponse : json_encode($this->rpcResponse, JSON_THROW_ON_ERROR),
            exitCode: $this->rpcExit);
    })->preventStrayProcesses();
});

afterEach(function () {
    unset($_ENV['TASK_COMPLETION_PRIVATE_FIXTURE'], $_ENV['GIT_CONFIG_COUNT']);
    File::deleteDirectory($this->completionDirectory);
});

it('reads a complete exact issue without active-state or legacy delegation requirements', function (bool $done) {
    if ($done) {
        $this->rpcResponse['data']['issue']['state'] = ['id' => $this->completion->done, 'name' => 'Done', 'type' => 'completed'];
    }
    $issue = $this->nativeIssue->read($this->landing);
    expect($issue->issueId)->toBe($this->landing->issue_id)
        ->and($issue->payload['delegate'])->toBeNull()
        ->and($issue->payload['state']['name'])->toBe($done ? 'Done' : 'In Review')
        ->and($this->rpcRequests)->toHaveCount(1)
        ->and($this->rpcRequests[0]['service'])->toBe('linear')
        ->and($this->rpcRequests[0]['variables'])->toBe(['id' => $this->landing->issue_id])
        ->and($this->rpcRequests[0]['document'])->toStartWith('query TasksCompletion(')
        ->toContain('viewer { id }', 'assignee { id } delegate { id }', 'team { id states { nodes { id name } } }');
})->with([false, true]);

it('sends exactly one state-only completion mutation and never changes ownership', function () {
    $this->rpcResponse = ['data' => ['issueUpdate' => ['success' => true]]];
    $this->nativeIssue->complete($this->landing, $this->completion->done);
    expect($this->rpcRequests)->toHaveCount(1)
        ->and($this->rpcRequests[0]['document'])->toStartWith('mutation TasksDone(')
        ->and($this->rpcRequests[0]['variables'])->toBe(['id' => $this->landing->issue_id,
            'input' => ['stateId' => $this->completion->done]])
        ->and(array_keys($this->rpcRequests[0]))->toBe(['service', 'document', 'variables']);
});

it('does not retry uncertain or malformed mutation responses', function (string $failure) {
    $this->rpcResponse = match ($failure) {
        'false' => ['data' => ['issueUpdate' => ['success' => false]]],
        'wrong type' => ['data' => ['issueUpdate' => ['success' => 1]]],
        'errors' => ['data' => ['issueUpdate' => ['success' => true]], 'errors' => [['message' => 'Uncertain mutation']]],
        'missing' => ['data' => []],
        'json' => '{unfinished',
        'rpc' => ['data' => ['issueUpdate' => ['success' => true]]],
    };
    $this->rpcExit = $failure === 'rpc' ? 255 : 0;
    expect(fn () => $this->nativeIssue->complete($this->landing, $this->completion->done))->toThrow($failure === 'json' ? JsonException::class : LogicException::class)
        ->and($this->rpcRequests)->toHaveCount(1);
})->with(['false', 'wrong type', 'errors', 'missing', 'json', 'rpc']);

it('refuses malformed raw issue collections before normalization can hide omissions', function (string $field, string $failure) {
    $collection = &$this->rpcResponse['data']['issue'][$field];
    match ($failure) {
        'missing nodes' => $collection = ['pageInfo' => ['hasNextPage' => false]],
        'missing page' => $collection = ['nodes' => []],
        'truncated' => $collection['pageInfo']['hasNextPage'] = true,
        'object nodes' => $collection['nodes'] = ['not' => 'a list'],
        'excessive nodes' => $collection['nodes'] = array_fill(0, 101, []),
    };
    expect(fn () => $this->nativeIssue->read($this->landing))->toThrow(LogicException::class,
        $failure === 'missing page' ? 'named landing object' : 'incomplete collection')
        ->and($this->rpcRequests)->toHaveCount(1);
})->with(['labels', 'attachments', 'children', 'inverseRelations'])
    ->with(['missing nodes', 'missing page', 'truncated', 'object nodes', 'excessive nodes']);

it('requires exact requested UUID key authenticated viewer and explicit owners', function (string $failure) {
    $issue = &$this->rpcResponse['data']['issue'];
    match ($failure) {
        'UUID' => $issue['id'] = '99999999-9999-4999-8999-999999999999',
        'key' => $issue['identifier'] = 'ORB-9999',
        'viewer' => $this->rpcResponse['data']['viewer']['id'] = '99999999-9999-4999-8999-999999999999',
        'delegate' => $issue = array_diff_key($issue, ['delegate' => null]),
        'assignee' => $issue = array_diff_key($issue, ['assignee' => null]),
        'errors' => $this->rpcResponse['errors'] = [['message' => 'Partial data']],
    };
    expect(fn () => $this->nativeIssue->read($this->landing))->toThrow(OrbitIssueProviderFailed::class)
        ->and($this->rpcRequests)->toHaveCount(1);
})->with(['UUID', 'key', 'viewer', 'delegate', 'assignee', 'errors']);

it('distinguishes exact idle owned and foreign reservations without writing any of them', function (string $status) {
    $pr = ['url' => 'https://github.com/nckrtl/orbit/pull/303'];
    $this->rpcResponse = ['version' => 1, 'status' => $status === 'idle' ? 'idle' : 'active',
        'issue_id' => match ($status) {
            'idle' => null, 'owned' => $this->landing->issue_id, default => '99999999-9999-4999-8999-999999999999'
        },
        'pr_url' => match ($status) {
            'idle' => null, 'owned' => $pr['url'], default => 'https://github.com/nckrtl/orbit/pull/304'
        },
        'reserved_at' => $status === 'idle' ? null : '2026-09-13T00:25:00Z'];
    $observed = $this->nativeIssue->reservation($this->landing, $pr);
    expect($observed)->toBe(['status' => $status, 'issue_id' => $this->rpcResponse['issue_id'],
        'url' => $this->rpcResponse['pr_url'], 'reserved_at' => $this->rpcResponse['reserved_at']])
        ->and($this->rpcRequests)->toBe([['service' => 'reservation', 'action' => 'status']]);
})->with(['idle', 'owned', 'foreign']);

it('does not treat incomplete malformed or mismatched reservation data as absence', function (string $failure) {
    $pr = ['url' => 'https://github.com/nckrtl/orbit/pull/303'];
    $this->rpcResponse = ['version' => 1, 'status' => 'active', 'issue_id' => $this->landing->issue_id,
        'pr_url' => $pr['url'], 'reserved_at' => '2026-09-13T00:25:00Z'];
    match ($failure) {
        'version' => $this->rpcResponse['version'] = 2,
        'missing' => $this->rpcResponse = ['version' => 1, 'status' => 'idle'],
        'partial idle' => $this->rpcResponse['status'] = 'idle',
        'UUID' => $this->rpcResponse['issue_id'] = 'not-a-uuid',
        'repository' => $this->rpcResponse['pr_url'] = 'https://github.com/other/orbit/pull/303',
        'query' => $this->rpcResponse['pr_url'] .= '?owner=other',
        'own issue foreign PR' => $this->rpcResponse['pr_url'] = 'https://github.com/nckrtl/orbit/pull/304',
        'own PR foreign issue' => $this->rpcResponse['issue_id'] = '99999999-9999-4999-8999-999999999999',
        'missing timestamp' => $this->rpcResponse['reserved_at'] = null,
        'RPC' => $this->rpcExit = 255,
        'JSON' => $this->rpcResponse = '{unfinished',
    };
    expect(fn () => $this->nativeIssue->reservation($this->landing, $pr))->toThrow($failure === 'JSON' ? JsonException::class : LogicException::class)
        ->and($this->rpcRequests)->toHaveCount(1);
})->with(['version', 'missing', 'partial idle', 'UUID', 'repository', 'query', 'own issue foreign PR',
    'own PR foreign issue', 'missing timestamp', 'RPC', 'JSON']);

it('pins authentication endpoint and all principals without starting a process', function (string $option) {
    $identity = $this->nativeIssue->identityHash();
    config(['commander.hermes.'.$option => match ($option) {
        'ssh_target' => 'other@mini', 'profiles.tom' => '/other/profile', default => '99999999-9999-4999-8999-999999999999',
    }]);
    expect($this->nativeIssue->identityHash())->not->toBe($identity)->and($this->rpcRequests)->toBe([]);
})->with(['ssh_target', 'profiles.tom', 'tom_linear_viewer_id', 'orbit_linear_team_id', 'nick_linear_user_id']);

it('rejects invalid endpoints and UUIDs before a mutation', function (string $failure) {
    match ($failure) {
        'target' => config(['commander.hermes.ssh_target' => 'tom@mini; unsafe']),
        'profile' => config(['commander.hermes.profiles.tom' => 'relative/profile']),
        'viewer' => config(['commander.hermes.tom_linear_viewer_id' => 'not-a-uuid']),
        'issue' => $this->landing->issue_id = 'ORB-249',
        'state' => $this->completion->done = 'Done',
    };
    expect(fn () => $this->nativeIssue->complete($this->landing, $this->completion->done))->toThrow(LogicException::class)
        ->and($this->rpcRequests)->toBe([]);
})->with(['target', 'profile', 'viewer', 'issue', 'state']);

it('propagates issue reader failures instead of manufacturing an empty issue', function () {
    $this->rpcExit = 255;
    expect(fn () => $this->nativeIssue->read($this->landing))->toThrow(LogicException::class, 'RPC failed')
        ->and($this->rpcRequests)->toHaveCount(1);
});
