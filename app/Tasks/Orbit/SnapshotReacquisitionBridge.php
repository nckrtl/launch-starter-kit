<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

/** Native Orbit classes are loaded only in a separate process, never in Commander. */
final class SnapshotReacquisitionBridge
{
    public static function script(): string
    {
        return <<<'PHP'
declare(strict_types=1);
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$request = $input['request'];
$worktree = $request['validation_worktree'];
$read = static function (string $path): ?array {
    if (! file_exists($path) && ! is_link($path)) {
        return null;
    }
    if (realpath($path) !== $path || is_link($path) || ! is_file($path) || filesize($path) > 4194304) {
        throw new RuntimeException('Native reacquisition state must be a bounded canonical regular file.');
    }
    $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('Native reacquisition state is not an object.');
    }
    return $value;
};
require $worktree.'/apps/e2e/vendor/autoload.php';
foreach ([App\E2E\TopologyAcquirer::class => 'acquire', App\E2E\TopologyProofRunner::class => 'prove',
    App\E2E\ProofCaptureService::class => 'capture', App\E2E\ProofReviewService::class => 'execute',
    App\E2E\TopologyReleaser::class => 'releaseCapturedProof'] as $class => $method) {
    if (! method_exists($class, $method)) {
        throw new RuntimeException('The merged native reacquisition service is unavailable.');
    }
}
if (! method_exists(App\E2E\TopologyReleaser::class, 'releaseExact')) {
    throw new RuntimeException('Exact auxiliary discovery cleanup is unavailable.');
}
$repository = new App\E2E\Git\GitRepository($worktree);
if ($repository->commit() !== $request['merged_main']
    || ! App\E2E\Value\TopologyTarget::issueMatchesBranch($request['issue'], $repository->branch())
    || ! App\E2E\Value\MountPath::isMountableDirectory($worktree)
    || (function_exists('posix_geteuid') && fileowner($worktree) !== posix_geteuid())) {
    throw new RuntimeException('Native bootstrap is not running at exact merged main.');
}
foreach (['apps/gateway/vendor/autoload.php', 'apps/cli/vendor/autoload.php', 'packages/php-sdk/vendor/autoload.php'] as $autoload) {
    if (! is_file($worktree.'/'.$autoload)) {
        throw new RuntimeException('The merged validation checkout requires bounded native bootstrap before acquisition.');
    }
}
$generation = App\E2E\Value\TopologySnapshotGeneration::fromArray(
    $read($request['repository'].'/.e2e/topology-snapshot/promoted.json') ?? []
);
if ($generation->toArray() !== $request['generation'] || $generation->mainSha !== $request['merged_main']) {
    throw new RuntimeException('The installed generation changed before native reacquisition.');
}
$plan = App\E2E\Value\ProofPlan::fromFile($worktree.'/.loop/proof/'.$request['issue'].'.json');
if ($plan->snapshotReplacement || $plan->extension !== null || $plan->endsWith !== null) {
    throw new RuntimeException('Reacquisition requires an ordinary complete nonreplacement topology.');
}
$state = [];
foreach (['discovery_attempt' => 'attempt.json', 'discovery' => 'topology.json',
    'proof_attempt' => 'proof-attempt.json', 'proof' => 'proof-topology.json', 'proof_result' => 'proof.json',
    'candidate_attempt' => 'candidate-attempt.json'] as $key => $file) {
    $state[$key] = $read($worktree.'/.e2e/'.$file);
}
foreach (['discovery', 'proof'] as $purpose) {
    if ($state[$purpose] !== null) {
        $state[$purpose] = App\E2E\Value\FeatureTopology::fromArray($state[$purpose])->toArray();
    }
}
$attempt = $state['proof_attempt']['attempt_id'] ?? $state['proof_result']['attempt_id'] ?? null;
if ($attempt !== null && (! is_string($attempt) || preg_match('/\A[0-9a-f]{32}\z/D', $attempt) !== 1)) {
    throw new RuntimeException('The native proof attempt identity is invalid.');
}
$state['capture'] = $attempt === null ? null : $read($worktree.'/.e2e/captured-proof/'.$attempt.'.json');
$state['review_record'] = $attempt === null ? null : $read($worktree.'/.e2e/proof-review/'.$attempt.'.json');
if ($state['capture'] !== null) {
    $state['capture'] = App\E2E\Value\CapturedProof::fromStoredArray($state['capture'])->toArray();
}
if ($state['review_record'] !== null) {
    $state['review_record'] = App\E2E\Value\ProofReviewRecord::fromArray($state['review_record'])->toArray();
}
if ($input['operation'] === 'inspect') {
    echo json_encode(['plan_sha256' => $plan->fingerprint(), 'status' => $state,
        'capabilities' => ['acquire', 'prove', 'capture', 'review', 'releaseCapturedProof', 'releaseExact']], JSON_THROW_ON_ERROR);
    exit(0);
}
if (! in_array($input['operation'], ['release-proof', 'release-discovery'], true)) {
    throw new RuntimeException('Unsupported native reacquisition bridge operation.');
}
$evidence = $input['evidence'];
if (! is_string($evidence['acceptance_hash'] ?? null)
    || preg_match('/\A[0-9a-f]{64}\z/D', $evidence['acceptance_hash']) !== 1) {
    throw new RuntimeException('Auxiliary cleanup requires the caller-approved exact acceptance hash.');
}
$app = require $worktree.'/apps/e2e/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$nativeRequest = new App\E2E\Value\TopologyRequest($request['issue'], $worktree);
$releaser = $app->make(App\E2E\TopologyReleaser::class);
if ($input['operation'] === 'release-proof') {
    $capture = App\E2E\Value\CapturedProof::fromArray($state['capture'] ?? []);
    if ($capture->candidateSha !== $request['merged_main']
        || $capture->attempt->value !== $evidence['proof_attempt']
        || $capture->fingerprint() !== $evidence['capture_fingerprint']
        || $capture->topology->generation->toArray() !== $request['generation']
        || $capture->topology->construction->snapshotReplacement) {
        throw new RuntimeException('Auxiliary cleanup capture identity changed.');
    }
    $result = $releaser->releaseCapturedProof($nativeRequest, $capture);
} else {
    $result = $releaser->releaseExact($nativeRequest, [
        'discovery' => new App\E2E\Value\AttemptId($evidence['discovery_attempt']),
    ]);
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
PHP;
    }
}
