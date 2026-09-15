<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Models\TaskWorkspace;
use JsonException;
use LogicException;

/** @phpstan-type OrbitProfile array{schema: 1, flow: 'discovery'|'proof', snapshot_replacement: bool} */
final class OrbitTaskProfile
{
    /** @return OrbitProfile|null */
    public static function select(string $project, ?string $flow = null, bool $snapshotReplacement = false): ?array
    {
        if ($project !== 'orbit') {
            if ($flow !== null || $snapshotReplacement) {
                throw new LogicException('Orbit flow and snapshot replacement options are only supported for Orbit.');
            }

            return null;
        }

        return self::validate(['schema' => 1, 'flow' => $flow ?? 'discovery', 'snapshot_replacement' => $snapshotReplacement]);
    }

    /** @return OrbitProfile|null */
    public static function forWorkspace(TaskWorkspace $workspace): ?array
    {
        if (! array_key_exists('orbit_profile', $workspace->configuration)) {
            return self::select($workspace->project_id);
        }
        if ($workspace->project_id !== 'orbit') {
            throw new LogicException('An Orbit execution profile cannot belong to another project.');
        }

        return self::validate($workspace->configuration['orbit_profile']);
    }

    /** @param OrbitProfile $profile */
    public static function assertNativeFlow(string $worktree, array $profile): void
    {
        $profile = self::validate($profile);
        $directory = $worktree.'/.loop';
        $path = $directory.'/flow.json';
        if (is_link($directory) || (file_exists($directory) && (! is_dir($directory) || realpath($directory) !== $directory))) {
            throw new LogicException('Orbit flow selection must use the native, unredirected .loop directory.');
        }
        $flow = 'discovery';
        if (file_exists($path) || is_link($path)) {
            if (! is_file($path) || realpath($path) !== $path) {
                throw new LogicException('Orbit flow selection must be a regular .loop/flow.json file.');
            }
            $contents = file_get_contents($path);
            try {
                $native = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new LogicException('Invalid native Orbit flow selection.', previous: $exception);
            }
            if (! is_array($native) || ($native['schema'] ?? null) !== 1 || ! in_array($native['flow'] ?? null, ['discovery', 'proof'], true)) {
                throw new LogicException('Invalid native Orbit flow selection.');
            }
            $flow = $native['flow'];
        }
        if ($flow !== $profile['flow']) {
            throw new LogicException('The requested Orbit flow differs from the existing worktree selection. Admission does not switch flows.');
        }
    }

    public static function instructions(TaskWorkspace $workspace): string
    {
        $profile = self::forWorkspace($workspace);
        if ($profile === null) {
            return '';
        }

        return 'Orbit execution profile: '.$profile['flow'].'; snapshot replacement: '.($profile['snapshot_replacement'] ? 'yes' : 'no').'. '
            .'Keep this stored profile and required Incus verification. Do not substitute discovery for proof or change the selected flow. '
            .'Profile selection alone does not authorize operations; follow the approved task and Commander project handler. '
            .($profile['snapshot_replacement']
                ? 'Keep required postmerge installation and verification gates pending (C12 when named by the task); premerge assignments cannot sign them off or claim postmerge acceptance.'
                : '');
    }

    /** @return OrbitProfile */
    private static function validate(mixed $profile): array
    {
        if (! is_array($profile) || count($profile) !== 3 || ($profile['schema'] ?? null) !== 1
            || ! in_array($profile['flow'] ?? null, ['discovery', 'proof'], true)
            || ! is_bool($profile['snapshot_replacement'] ?? null)
            || ($profile['snapshot_replacement'] && $profile['flow'] !== 'proof')) {
            throw new LogicException('Invalid Orbit execution profile: select discovery or proof; snapshot replacement requires proof.');
        }

        return ['schema' => 1, 'flow' => $profile['flow'], 'snapshot_replacement' => $profile['snapshot_replacement']];
    }
}
