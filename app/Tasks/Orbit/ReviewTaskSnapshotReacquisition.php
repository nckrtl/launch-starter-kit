<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Closeout\TaskCloseoutLock;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Landing\TaskLandingReviewTransport;
use App\Tasks\TaskPayload;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class ReviewTaskSnapshotReacquisition
{
    public function __construct(private TaskCloseoutContext $context, private TaskCloseoutLock $lock,
        private TaskLandingReviewer $reviewer, private TaskLandingEvidence $secrets, private TaskPayload $payloads) {}

    /** Caller supplies validated, immutable postinstall observations without holding the closeout lock.
     * @param  array<string,mixed>  $bundle
     * @return array<string,mixed>
     */
    public function dispatch(TaskLanding $landing, array $bundle, bool $apply): array
    {
        $claim = $apply ? $this->lock->handle(fn (): array => $this->claim($landing, $bundle, true))
            : $this->claim($landing, $bundle, false);
        if (! isset($claim['operation'], $claim['workspace'], $claim['session'], $claim['prompt'])) {
            return $claim['result'];
        }
        $operation = $claim['operation'];
        try {
            $observed = $this->reviewer->promptOnce($claim['workspace'], $claim['session'], $claim['prompt']);
            HerdrTaskLandingReviewer::assertIdentity($claim['session'], $observed);
        } catch (Throwable) {
            $acknowledged = $this->lock->handle(function () use ($operation): ?array {
                $operation->refresh();
                if ($operation->result !== null) {
                    return $this->status($operation, true);
                }
                $operation->update(['state' => 'unknown', 'error' => 'Postinstall review prompt delivery is uncertain; do not resend.']);

                return null;
            });
            if ($acknowledged !== null) {
                return $acknowledged;
            }
            throw new LogicException('Postinstall review prompt delivery is uncertain; inspect the retained assignment without resending.');
        }

        return $this->status($operation->refresh(), true);
    }

    /** @param array<string,mixed> $bundle
     * @return array{result:array<string,mixed>,operation?:TaskCloseoutOperation,workspace?:TaskWorkspace,session?:array<string,mixed>,prompt?:string}
     */
    private function claim(TaskLanding $landing, array $bundle, bool $apply): array
    {
        $this->context->approved($landing->id, (string) $landing->package_hash);
        $workspace = $landing->workspace()->firstOrFail();
        if (($landing->package['schema'] ?? null) !== 2
            || (OrbitTaskProfile::forWorkspace($workspace)['snapshot_replacement'] ?? false) !== true) {
            throw new LogicException('Postinstall review requires the explicit replacement proof profile.');
        }
        $this->secrets->assertNoSecrets($workspace, $bundle);
        $hash = TaskLandingData::hash($bundle);
        $name = 'snapshot-review:'.$hash;
        $operation = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $name)->first();
        $input = ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
            'bundle_hash' => $hash, 'bundle' => $bundle];
        if ($operation !== null) {
            if ($operation->input !== $input || $operation->input_hash !== TaskLandingData::hash($input)
                || $operation->package_hash !== $landing->package_hash) {
                throw new LogicException('The postinstall review input changed.');
            }

            if ($operation->state !== 'prepared' || $operation->preflight !== null || $operation->result !== null) {
                return ['result' => $this->status($operation, false)];
            }
        }
        $pending = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'snapshot-review:%')->where('operation', '!=', $name)->whereNull('result')->exists();
        if ($pending) {
            throw new LogicException('An earlier postinstall review is unresolved; do not send a competing assignment.');
        }
        $session = $this->reviewer->observe($workspace, $workspace->reviewer_session ?? throw new LogicException('Missing retained reviewer.'), true);
        HerdrTaskLandingReviewer::assertIdentity($workspace->reviewer_session ?? [], $session);
        if (! $apply) {
            return ['result' => ['applied' => false, 'state' => 'eligible', 'bundle_hash' => $hash, 'reviewer' => $session]];
        }
        $path = $this->retain($landing, $hash, $bundle);
        $assignment = $operation->assignment ?? (string) Str::uuid();
        $token = Str::random(64);
        $operation ??= TaskCloseoutOperation::query()->create(['task_landing_id' => $landing->id, 'operation' => $name,
            'assignment' => $assignment, 'package_hash' => $landing->package_hash, 'input' => $input, 'input_hash' => TaskLandingData::hash($input)]);
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' tasks:snapshot-review-submit '.$operation->id.' --file=/absolute/private/handoff.json';
        $receipt = ['token' => $token, 'assignment' => $assignment, 'bundle_hash' => $hash,
            'verdict' => 'pass or revise or blocked', 'summary' => 'Postinstall review outcome', 'evidence' => 'Exact generation, both attempts, sample observations and any missing evidence.'];
        $prompt = 'Independently review ORB postinstall snapshot verification. Reuse your exact candidate review; this assignment concerns only the previously pending installation and reacquisition acceptance. '
            .'Read the complete retained bundle at '.$path.' (SHA256 '.$hash.'). It binds the accepted candidate, merged main, installed generation, ordinary discovery and nonreplacement proof, their attempts, fixtures and observed results. '
            .'Judge whether both acquired the installed generation and the actual sample checks meet the issue criterion. Installation alone or a zero-exit count is insufficient. Return revise or blocked for missing or conflicting evidence. '
            .'Do not change code, artifacts, tasks, machines, PRs or issue status; do not merge or clean up. Successful review authorizes the coordinator to perform exact auxiliary cleanup, not you. '
            .'Create a fresh mktemp -d directory outside both worktrees (0700) and an empty handoff file (0600 under umask 077); verify permissions before writing the token. Quote its absolute --file path. '
            .'From your assigned feature worktree submit with '.$command."\n".TaskLandingData::json($receipt)
            .'Never print the token or include it in evidence. Claim submission only after native confirmation; on failure keep the unchanged private file and report only its path/hash and safe error. Stop and await Commander; do not retry automatically.';
        $transport = TaskLandingReviewTransport::inspect($session, $prompt);
        $operation->update(['state' => 'intended', 'preflight' => ['session' => $session, 'token_hash' => hash('sha256', $token),
            'prompt' => Crypt::encryptString($prompt), 'bundle_path' => $path, 'transport' => $transport]]);

        return ['result' => $this->status($operation, true), 'operation' => $operation,
            'workspace' => $workspace, 'session' => $session, 'prompt' => $prompt];
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function submit(int $id, array $receipt): array
    {
        return $this->lock->handle(function () use ($id, $receipt): array {
            $operation = TaskCloseoutOperation::query()->findOrFail($id);
            $landing = TaskLanding::query()->findOrFail($operation->task_landing_id);
            $workspace = $landing->workspace()->firstOrFail();
            $this->context->approved($landing->id, (string) $landing->package_hash);
            if (($landing->package['schema'] ?? null) !== 2
                || (OrbitTaskProfile::forWorkspace($workspace)['snapshot_replacement'] ?? false) !== true
                || $operation->operation !== 'snapshot-review:'.TaskLandingData::text($operation->input, 'bundle_hash')
                || $operation->package_hash !== $landing->package_hash || ($operation->input['package_hash'] ?? null) !== $landing->package_hash
                || $operation->input_hash !== TaskLandingData::hash($operation->input)
                || ($operation->input['bundle_hash'] ?? null) !== TaskLandingData::hash($operation->input['bundle'] ?? null)
                || TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'like', 'snapshot-review:%')->orderByDesc('id')->first()?->id !== $id) {
                throw new LogicException('The postinstall review does not match the current frozen observation bundle.');
            }
            $token = TaskLandingData::text($receipt, 'token');
            $preflight = TaskLandingData::object($operation->preflight);
            if (! hash_equals(TaskLandingData::text($preflight, 'token_hash'), hash('sha256', $token))
                || ($receipt['assignment'] ?? null) !== $operation->assignment
                || ($receipt['bundle_hash'] ?? null) !== $operation->input['bundle_hash']
                || ! in_array($receipt['verdict'] ?? null, ['pass', 'revise', 'blocked'], true)
                || array_diff(array_keys($receipt), ['token', 'assignment', 'bundle_hash', 'verdict', 'summary', 'evidence']) !== []) {
                throw new LogicException('The postinstall handoff does not match its secret assignment.');
            }
            TaskLandingData::text($receipt, 'summary');
            TaskLandingData::text($receipt, 'evidence');
            unset($receipt['token']);
            if (str_contains(TaskLandingData::json($receipt), $token)) {
                throw new LogicException('Do not include the private review token in evidence.');
            }
            $this->secrets->assertNoSecrets($workspace, $receipt);
            $this->verifyRetained($operation);
            if ($operation->result !== null) {
                if ($operation->state !== 'completed' || ! $this->payloads->matches($operation->result, $receipt)) {
                    throw new LogicException('An acknowledged postinstall verdict cannot be replaced.');
                }

                return $this->status($operation, false);
            }
            if (! in_array($operation->state, ['intended', 'unknown'], true)) {
                throw new LogicException('Only a dispatched postinstall assignment can submit.');
            }
            $session = TaskLandingData::object($preflight['session'] ?? null);
            HerdrTaskLandingReviewer::assertIdentity($session, $this->reviewer->observe($workspace, $session, false));
            $operation->update(['result' => $receipt, 'state' => 'completed', 'error' => null]);

            return $this->status($operation, true);
        });
    }

    /** @param array<string,mixed> $bundle */
    private function retain(TaskLanding $landing, string $hash, array $bundle): string
    {
        $directory = storage_path('app/private/task-snapshot-reviews/'.$landing->id);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LogicException('Cannot retain the postinstall review bundle.');
        }
        if (realpath($directory) !== $directory || (fileperms($directory) & 0077) !== 0) {
            throw new LogicException('The review evidence directory is redirected.');
        }
        $path = $directory.'/'.$hash.'.json';
        $contents = TaskLandingData::json($bundle);
        if (! file_exists($path) && ! is_link($path)) {
            $mask = umask(0077);
            try {
                $file = fopen($path, 'x');
            } finally {
                umask($mask);
            }
            if ($file === false) {
                throw new LogicException('Cannot exclusively create the review bundle.');
            }
            try {
                if (fwrite($file, $contents) !== strlen($contents)) {
                    throw new LogicException('The retained review bundle is incomplete.');
                }
            } finally {
                fclose($file);
            }
        }
        clearstatcache(true, $path);
        if ((fileperms($path) & 0077) !== 0 || TaskLandingData::file($path, 16_777_216) !== $contents) {
            throw new LogicException('The retained review bundle changed; do not overwrite it.');
        }

        return $path;
    }

    /** @return array<string,mixed> */
    private function status(TaskCloseoutOperation $operation, bool $applied): array
    {
        if (TaskCloseoutOperation::query()->where('task_landing_id', $operation->task_landing_id)
            ->where('operation', 'like', 'snapshot-review:%')->orderByDesc('id')->first()?->id !== $operation->id) {
            throw new LogicException('Only the latest postinstall review assignment can establish current acceptance.');
        }
        if ($operation->preflight !== null) {
            $this->verifyRetained($operation);
        }

        return ['applied' => $applied, 'operation_id' => $operation->id, 'assignment' => $operation->assignment,
            'bundle_hash' => $operation->input['bundle_hash'], 'state' => $operation->state, 'review' => $operation->result];
    }

    private function verifyRetained(TaskCloseoutOperation $operation): void
    {
        $hash = TaskLandingData::text($operation->input, 'bundle_hash');
        $bundle = TaskLandingData::object($operation->input['bundle'] ?? null);
        $path = storage_path('app/private/task-snapshot-reviews/'.$operation->task_landing_id.'/'.$hash.'.json');
        clearstatcache(true, $path);
        if (TaskLandingData::hash($bundle) !== $hash || ($operation->preflight['bundle_path'] ?? null) !== $path
            || ! is_file($path) || (fileperms($path) & 0077) !== 0
            || (fileperms(dirname($path)) & 0077) !== 0
            || TaskLandingData::file($path, 16_777_216) !== TaskLandingData::json($bundle)) {
            throw new LogicException('The assigned private review bundle is missing, redirected, public or changed.');
        }
    }
}
