<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Operations\HermesKanbanSnapshot;
use Inertia\Inertia;
use Inertia\Response;
use NckRtl\Waymaker\Get;

final class AgentController extends Controller
{
    #[Get(uri: '/agents')]
    public function index(HermesKanbanSnapshot $kanban): Response
    {
        return inertia('Agents/Kanban', [
            'kanban' => fn (): ?array => $kanban->cached(),
            'freshKanban' => Inertia::defer(fn (): array => $kanban->get()),
        ]);
    }
}
