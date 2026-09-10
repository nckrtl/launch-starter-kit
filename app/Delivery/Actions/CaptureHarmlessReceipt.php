<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Jobs\AdvanceDelivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

final readonly class CaptureHarmlessReceipt
{
    /** @param array<string, mixed> $payload */
    public function handle(PhaseRun $phaseRun, array $payload): Receipt
    {
        $phaseRun->loadMissing(['delivery', 'agentDispatches']);
        $delivery = $phaseRun->delivery;
        $dispatch = $phaseRun->agentDispatches->first();
        $errors = $this->validate($phaseRun, $payload, $dispatch?->id);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $receipt = DB::transaction(function () use ($phaseRun, $delivery, $payload, $errors, $hash): Receipt {
            $existing = Receipt::query()
                ->where('phase_run_id', $phaseRun->id)
                ->where('kind', 'herdr_test')
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new LogicException('A different receipt was already captured for this phase.');
                }

                return $existing;
            }

            $receipt = Receipt::query()->create([
                'phase_run_id' => $phaseRun->id,
                'kind' => 'herdr_test',
                'schema_version' => is_int($payload['schema_version'] ?? null) ? $payload['schema_version'] : 0,
                'payload' => $payload,
                'payload_hash' => $hash,
                'candidate_sha' => is_string($payload['head_sha'] ?? null) ? $payload['head_sha'] : null,
                'validation_status' => $errors === [] ? ReceiptValidationStatus::Valid : ReceiptValidationStatus::Invalid,
                'validation_errors' => $errors === [] ? null : $errors,
                'captured_at' => now(),
                'validated_at' => now(),
            ]);

            if ($errors !== []) {
                $delivery->status = DeliveryStatus::Blocked;
                $delivery->failure_details = ['code' => $errors[0], 'receipt_id' => $receipt->id];
                $delivery->save();
            } elseif ($delivery->status === DeliveryStatus::Blocked
                && ($delivery->failure_details['code'] ?? null) === 'receipt_missing') {
                $delivery->status = DeliveryStatus::ValidatingReceipt;
                $delivery->failure_details = null;
                $delivery->save();
            }

            return $receipt;
        });

        if ($errors === []) {
            AdvanceDelivery::dispatch($delivery->id)->afterCommit();
        }

        return $receipt;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function validate(PhaseRun $phaseRun, array $payload, int|string|null $dispatchId): array
    {
        $allowed = ['kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt', 'outcome', 'worktree', 'head_sha', 'commands', 'artifacts', 'summary', 'created_at'];
        $validator = Validator::make($payload, [
            'kind' => ['required', 'in:herdr_test'],
            'schema_version' => ['required', 'integer', 'in:1'],
            'delivery_id' => ['required', 'integer'],
            'dispatch_id' => ['required', 'integer'],
            'issue_key' => ['required', 'string'],
            'phase' => ['required', 'string'],
            'attempt' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', 'string', 'in:success,failed'],
            'worktree' => ['required', 'string'],
            'head_sha' => ['required', 'string', 'regex:/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i'],
            'commands' => ['required', 'array', 'min:1'],
            'commands.*.command' => ['required', 'string'],
            'commands.*.exit_code' => ['required', 'integer'],
            'artifacts' => ['present', 'array'],
            'artifacts.*' => ['string'],
            'summary' => ['required', 'string', 'max:2000'],
            'created_at' => ['required', 'date'],
        ]);
        $errors = [];

        if ($validator->fails() || array_diff(array_keys($payload), $allowed) !== []) {
            $errors[] = 'receipt_malformed';
        }

        $delivery = $phaseRun->delivery;

        if (($payload['delivery_id'] ?? null) !== $delivery->id
            || ($payload['dispatch_id'] ?? null) !== $dispatchId
            || ($payload['issue_key'] ?? null) !== $delivery->external_issue_key
            || ($payload['phase'] ?? null) !== $phaseRun->phase_name
            || ($payload['attempt'] ?? null) !== $phaseRun->attempt
            || ($payload['worktree'] ?? null) !== $delivery->worktree_path) {
            $errors[] = 'receipt_stale';
        }

        if (($payload['head_sha'] ?? null) !== $delivery->candidate_sha) {
            $errors[] = 'receipt_sha_mismatch';
        }

        if (is_array($payload['commands'] ?? null)
            && collect($payload['commands'])->contains(fn (mixed $command): bool => ! is_array($command) || ($command['exit_code'] ?? null) !== 0)) {
            $errors[] = 'receipt_check_failed';
        }

        if (($payload['outcome'] ?? null) === 'failed') {
            $errors[] = 'receipt_agent_failed';
        }

        return array_values(array_unique($errors));
    }
}
