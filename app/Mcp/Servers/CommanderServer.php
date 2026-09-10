<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetProjectConfig;
use App\Mcp\Tools\GetProjectStatus;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\UpdateProject;
use Laravel\Mcp\Server;

final class CommanderServer extends Server
{
    protected string $name = 'Commander';

    protected string $version = '1.0.0';

    protected string $instructions = 'Manage the canonical shared-knowledge project registry. Read a project before changing it and preserve its project ID.';

    protected array $tools = [
        ListProjects::class,
        GetProject::class,
        GetProjectConfig::class,
        GetProjectStatus::class,
        CreateProject::class,
        UpdateProject::class,
    ];
}
