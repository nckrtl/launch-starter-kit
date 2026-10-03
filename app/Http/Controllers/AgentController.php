<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Operations\HermesKanbanSnapshot;
use Inertia\Inertia;
use Inertia\Response;

final class AgentController extends Controller
{
    public function index(HermesKanbanSnapshot $kanban): Response
    {
        return inertia('Agents/Kanban', [
            'kanban' => fn (): ?array => $kanban->cached(),
            'freshKanban' => Inertia::defer(fn (): array => $kanban->get()),
        ]);
    }
}
