<?php

/** Stand-in native classes for the disposable bridge process; no Incus, network, Laravel runtime or actual Git. */

namespace App\E2E\Git {
    class GitRepository
    {
        public function __construct(private string $directory) {}

        public function commit(): string
        {
            return trim(file_get_contents($this->directory.'/fixture-head'));
        }

        public function branch(): string
        {
            return 'orb-91-reacquisition';
        }
    }
}

namespace App\E2E\Value {
    class NativeFixtureValue
    {
        public function __construct(protected array $value) {}

        public static function fromArray(array $value): static
        {
            return new static($value);
        }

        public function toArray(): array
        {
            return $this->value;
        }
    }

    class TopologySnapshotGeneration extends NativeFixtureValue
    {
        public string $mainSha;

        public function __construct(array $value)
        {
            parent::__construct($value);
            $this->mainSha = $value['main_sha'];
        }
    }

    class ProofPlan
    {
        public ?string $extension = null;

        public ?string $endsWith = null;

        public function __construct(public bool $snapshotReplacement) {}

        public static function fromFile(string $path): self
        {
            return new self(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['snapshot_replacement']);
        }

        public function fingerprint(): string
        {
            return str_repeat('d', 64);
        }
    }

    class TopologyTarget
    {
        public static function issueMatchesBranch(string $issue, string $branch): bool
        {
            return str_starts_with($branch, strtolower($issue).'-');
        }
    }

    class MountPath
    {
        public static function isMountableDirectory(string $path): bool
        {
            return is_dir($path);
        }
    }

    class AttemptId
    {
        public function __construct(public string $value) {}
    }

    class TopologyRequest
    {
        public function __construct(public string $issue, public string $worktree) {}
    }

    class FeatureTopology extends NativeFixtureValue
    {
        public TopologySnapshotGeneration $generation;

        public object $construction;

        public function __construct(array $value)
        {
            parent::__construct($value);
            $this->generation = TopologySnapshotGeneration::fromArray($value['generation']);
            $this->construction = (object) ['snapshotReplacement' => $value['construction']['snapshot_replacement']];
        }
    }

    class CapturedProof extends NativeFixtureValue
    {
        public string $candidateSha;

        public AttemptId $attempt;

        public FeatureTopology $topology;

        public function __construct(array $value)
        {
            parent::__construct($value);
            $this->candidateSha = $value['candidate_sha'];
            $this->attempt = new AttemptId($value['attempt_id']);
            $this->topology = FeatureTopology::fromArray($value['topology']);
        }

        public static function fromStoredArray(array $value): self
        {
            return new self($value);
        }

        public function fingerprint(): string
        {
            return $this->value['fingerprint'];
        }
    }

    class ProofReviewRecord extends NativeFixtureValue {}
}

namespace App\E2E {
    class TopologyAcquirer
    {
        public function acquire(): void {}
    }

    class TopologyProofRunner
    {
        public function prove(): void {}
    }

    class ProofCaptureService
    {
        public function capture(): void {}
    }

    class ProofReviewService
    {
        public function execute(): void {}
    }

    class TopologyReleaser
    {
        public function releaseCapturedProof($request, $capture): array
        {
            return $this->record($request, 'releaseCapturedProof', $capture->attempt->value);
        }

        public function releaseExact($request, array $attempts): array
        {
            if (array_keys($attempts) !== ['discovery']) {
                throw new \RuntimeException('Unexpected cleanup target.');
            }

            return $this->record($request, 'releaseExact', $attempts['discovery']->value);
        }

        private function record($request, string $method, string $attempt): array
        {
            $value = ['method' => $method, 'issue' => $request->issue, 'worktree' => $request->worktree, 'attempt_id' => $attempt];
            file_put_contents($request->worktree.'/fixture-cleanup.json', json_encode($value, JSON_THROW_ON_ERROR));

            return $value;
        }
    }
}

namespace Tests\Fixtures {
    use App\E2E\TopologyReleaser;

    class NativeFixtureApplication
    {
        public function make(string $class): object
        {
            return match ($class) {
                'Illuminate\Contracts\Console\Kernel' => new class
                {
                    public function bootstrap(): void {}
                },
                TopologyReleaser::class => new TopologyReleaser,
                default => throw new \RuntimeException('Unexpected native service requested.'),
            };
        }
    }
}
