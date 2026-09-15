<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReorderTaskChildrenRequest;
use App\Http\Requests\StoreTaskObservationGrantRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\ReorderTaskChildren;
use App\Tasks\Actions\UpdateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Runtime\OrbitHerdrObservationGrants;
use App\Tasks\Runtime\OrbitHerdrObservationUnavailable;
use App\Tasks\Runtime\TaskTerminalSessions;
use App\Tasks\TaskCatalog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use InvalidArgumentException;
use LogicException;
use NckRtl\Waymaker\Get;
use NckRtl\Waymaker\Post;
use NckRtl\Waymaker\Put;

final class TaskController extends Controller
{
    #[Get(uri: '/projects/{projectId}/tasks')]
    public function index(string $projectId, SharedKnowledgeProjectRepository $projects, TaskCatalog $catalog): Response
    {
        return inertia('Tasks/Index', ['project' => $this->project($projectId, $projects), 'tasks' => $catalog->listing($projectId)]);
    }

    #[Get(uri: '/projects/{projectId}/tasks/{taskId}')]
    public function show(string $projectId, string $taskId, SharedKnowledgeProjectRepository $projects, TaskCatalog $catalog, TaskTerminalSessions $sessions): Response
    {
        $task = $catalog->find($projectId, $taskId);

        return inertia('Tasks/Show', [
            'project' => $this->project($projectId, $projects),
            'task' => [...$catalog->detail($projectId, $taskId), 'terminal_sessions' => $sessions->overview($task)],
        ]);
    }

    #[Post(uri: '/projects/{projectId}/tasks/{taskId}/terminal/{role}/observation-grant', middleware: 'throttle:120,1')]
    public function observationGrant(string $projectId, string $taskId, string $role, StoreTaskObservationGrantRequest $request, TaskCatalog $catalog, TaskTerminalSessions $sessions, OrbitHerdrObservationGrants $grants): JsonResponse
    {
        $task = $catalog->find($projectId, $taskId);
        $target = $sessions->resolve($task, $role);
        try {
            $observerUrl = $grants->issue(
                $target,
                $request->columns(),
                $request->rows(),
            );
        } catch (OrbitHerdrObservationUnavailable) {
            abort(503, 'This Herdr session is not available.');
        }

        return response()->json(['observer_url' => $observerUrl])->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    #[Post(uri: '/projects/{projectId}/tasks')]
    public function store(string $projectId, StoreTaskRequest $request, SharedKnowledgeProjectRepository $projects, TaskCatalog $catalog, CreateTask $create): RedirectResponse
    {
        $this->project($projectId, $projects);
        $parent = $request->input('parent_id') === null ? null : $catalog->find($projectId, $request->integer('parent_id'));

        return $this->mutate(function () use ($projectId, $request, $parent, $create): RedirectResponse {
            $task = $create->handle($projectId, $request->string('title')->toString(), $request->string('description')->toString(),
                TaskKind::from($request->string('kind')->toString()), $parent, $request->string('acceptance_criteria')->toString(), $request->string('creation_key')->toString());

            return to_route('TaskController.show', ['projectId' => $projectId, 'taskId' => $parent->id ?? $task->id])->with('success', 'Task created.');
        });
    }

    #[Put(uri: '/projects/{projectId}/tasks/{taskId}')]
    public function update(string $projectId, string $taskId, UpdateTaskRequest $request, SharedKnowledgeProjectRepository $projects, TaskCatalog $catalog, UpdateTask $update): RedirectResponse
    {
        $this->project($projectId, $projects);
        $task = $catalog->find($projectId, $taskId);

        return $this->mutate(function () use ($projectId, $taskId, $request, $task, $update): RedirectResponse {
            $update->handle($task, $request->string('expected_version')->toString(), $request->string('title')->toString(),
                $request->string('description')->toString(), $request->string('acceptance_criteria')->toString());

            return to_route('TaskController.show', ['projectId' => $projectId, 'taskId' => $taskId])->with('success', 'Task saved.');
        });
    }

    #[Put(uri: '/projects/{projectId}/tasks/{taskId}/order')]
    public function reorder(string $projectId, string $taskId, ReorderTaskChildrenRequest $request, SharedKnowledgeProjectRepository $projects, TaskCatalog $catalog, ReorderTaskChildren $reorder): RedirectResponse
    {
        $this->project($projectId, $projects);
        $task = $catalog->find($projectId, $taskId);
        /** @var list<int|string> $ordered */
        $ordered = $request->validated('ordered_ids');
        /** @var list<int|string> $expected */
        $expected = $request->validated('expected_ids');

        return $this->mutate(function () use ($projectId, $taskId, $task, $ordered, $expected, $reorder): RedirectResponse {
            $reorder->handle($task, array_map(intval(...), $ordered), array_map(intval(...), $expected));

            return to_route('TaskController.show', ['projectId' => $projectId, 'taskId' => $taskId])->with('success', 'Task order saved.');
        });
    }

    /** @return array{id: string, name: string} */
    private function project(string $projectId, SharedKnowledgeProjectRepository $projects): array
    {
        try {
            $project = $projects->find($projectId);

            assert(is_string($project['id']) && is_string($project['name']));

            return ['id' => $project['id'], 'name' => $project['name']];
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    /** @param Closure(): RedirectResponse $callback */
    private function mutate(Closure $callback): RedirectResponse
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|LogicException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }
    }
}
