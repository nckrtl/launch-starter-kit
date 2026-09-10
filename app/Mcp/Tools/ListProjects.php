<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Projects\SharedKnowledgeProjectRepository;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class ListProjects extends Tool
{
    protected string $description = 'List projects in the canonical shared-knowledge registry.';

    public function handle(Request $request, SharedKnowledgeProjectRepository $projects): ResponseFactory
    {
        return Response::structured(['projects' => $projects->all()]);
    }
}
