<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\GetDelivery;
use App\Mcp\Tools\GetDeliveryTimeline;
use App\Mcp\Tools\GetNextEligibleIssue;
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

    protected string $instructions = 'Read authoritative project and delivery state. Read a project before changing its manifest and preserve its project ID.';

    protected array $tools = [
        ListProjects::class,
        GetProject::class,
        GetProjectConfig::class,
        GetProjectStatus::class,
        GetDelivery::class,
        GetDeliveryTimeline::class,
        GetNextEligibleIssue::class,
        CreateProject::class,
        UpdateProject::class,
    ];
}
