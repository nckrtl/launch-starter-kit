<?php

declare(strict_types=1);

namespace App\Tasks\Enums;

enum TaskKind: string
{
    case Executable = 'executable';
    case Group = 'group';
}
