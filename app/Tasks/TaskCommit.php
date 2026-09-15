<?php

declare(strict_types=1);

namespace App\Tasks;

use InvalidArgumentException;

final readonly class TaskCommit
{
    /** @param array<int, string> $parentShas */
    public function __construct(public string $sha, public string $treeSha, public array $parentShas, public ?string $integrationMainSha = null)
    {
        GitObjectId::validate($sha);
        GitObjectId::validate($treeSha);

        if (! array_is_list($parentShas) || count($parentShas) !== ($integrationMainSha === null ? 1 : 2)) {
            throw new InvalidArgumentException('An accepted task commit must have exactly one parent.');
        }

        if ($integrationMainSha !== null) {
            GitObjectId::validate($integrationMainSha);
            if ($parentShas[1] !== $integrationMainSha || $parentShas[0] === $integrationMainSha
                || mb_strlen($integrationMainSha) !== mb_strlen($sha) || $sha === $integrationMainSha) {
                throw new InvalidArgumentException('An integration commit requires its distinct pinned main as second parent.');
            }
        }

        GitObjectId::validate($parentShas[0]);

        if (mb_strlen($sha) !== mb_strlen($treeSha) || mb_strlen($sha) !== mb_strlen($parentShas[0]) || $sha === $parentShas[0]) {
            throw new InvalidArgumentException('Commit identities must use one object format and advance the base.');
        }
    }
}
