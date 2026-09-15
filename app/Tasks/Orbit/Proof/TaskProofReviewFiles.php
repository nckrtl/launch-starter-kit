<?php

declare(strict_types=1);

namespace App\Tasks\Orbit\Proof;

use App\Models\TaskWorkspace;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use LogicException;

final readonly class TaskProofReviewFiles
{
    public function __construct(private TaskLandingEvidence $secrets) {}

    /** @param array<string, mixed> $value
     * @return array{path:string,raw_sha256:string,raw_bytes:int}
     */
    public function reference(int $workspaceId, array $value): array
    {
        $contents = TaskLandingData::json($value);
        if ($workspaceId < 1 || strlen($contents) > 8_388_608) {
            throw new LogicException('The private proof review evidence is invalid or too large.');
        }
        $hash = hash('sha256', $contents);

        return ['path' => storage_path('app/private/task-proof-reviews/'.$workspaceId.'/'.$hash.'.json'),
            'raw_sha256' => $hash, 'raw_bytes' => strlen($contents)];
    }

    /** @param array<string, mixed> $value */
    public function retain(TaskWorkspace $workspace, array $value): void
    {
        $this->secrets->assertNoSecrets($workspace, $value);
        $reference = $this->reference($workspace->id, $value);
        $directory = dirname($reference['path']);
        $this->createDirectory($directory);
        $this->assertDirectory($directory);
        $path = $reference['path'];
        if (! file_exists($path) && ! is_link($path)) {
            $mask = umask(0077);
            try {
                $file = fopen($path, 'x');
            } finally {
                umask($mask);
            }
            if ($file === false) {
                throw new LogicException('Cannot exclusively create the private proof review evidence.');
            }
            try {
                $contents = TaskLandingData::json($value);
                if (fwrite($file, $contents) !== strlen($contents)) {
                    throw new LogicException('The private proof review evidence is incomplete.');
                }
            } finally {
                fclose($file);
            }
        }
        $this->verify($workspace->id, $value);
    }

    /** @param array<string, mixed> $value */
    public function verify(int $workspaceId, array $value): void
    {
        $reference = $this->reference($workspaceId, $value);
        $path = $reference['path'];
        $this->assertDirectory(dirname($path));
        clearstatcache(true, $path);
        if (TaskLandingData::file($path, 8_388_608) !== TaskLandingData::json($value)
            || (fileperms($path) & 0777) !== 0600) {
            throw new LogicException('The private proof review evidence changed; do not overwrite it.');
        }
    }

    private function assertDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        if (realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700) {
            throw new LogicException('The private proof review evidence directory is redirected or not private.');
        }
    }

    private function createDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                throw new LogicException('Cannot retain the private proof review evidence.');
            }
            $this->createDirectory($parent);
            if (! mkdir($directory, 0700) && ! is_dir($directory)) {
                throw new LogicException('Cannot retain the private proof review evidence.');
            }
        }
        if (realpath($directory) !== $directory) {
            throw new LogicException('The private proof review evidence directory is redirected.');
        }
    }
}
