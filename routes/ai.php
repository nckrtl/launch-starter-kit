<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateCommanderMcp;
use App\Mcp\Servers\CommanderServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/commander', CommanderServer::class)
    ->middleware(AuthenticateCommanderMcp::class);
