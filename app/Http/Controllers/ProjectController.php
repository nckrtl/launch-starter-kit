<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Projects\GitHubProjects;
use App\Projects\OrbitProjects;
use App\Projects\ProjectDetails;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use NckRtl\Waymaker\Get;
use NckRtl\Waymaker\Post;
use NckRtl\Waymaker\Put;

final class ProjectController extends Controller
{
    #[Get(uri: '/projects')]
    public function index(SharedKnowledgeProjectRepository $projects): Response
    {
        return inertia('Projects/Index', ['projects' => $projects->all()]);
    }

    #[Post(uri: '/projects')]
    public function store(StoreProjectRequest $request, SharedKnowledgeProjectRepository $projects): RedirectResponse
    {
        try {
            $projects->create($request->string('id')->toString(), [
                'name' => $request->string('name')->toString(),
                'status' => $request->string('status')->toString(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['id' => $exception->getMessage()]);
        }

        return to_route('ProjectController.index')->with('success', 'Project created.');
    }

    #[Get(uri: '/projects/{id}')]
    public function show(string $id, SharedKnowledgeProjectRepository $projects, ProjectDetails $details, OrbitProjects $orbit, GitHubProjects $github): Response
    {
        try {
            $project = $details->get($projects->find($id));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        /** @var list<string> $repositories */
        $repositories = $project['repositories'];

        return inertia('Projects/Show', [
            'project' => $project,
            'orbit' => Inertia::defer(fn (): array => $orbit->get($project), 'orbit'),
            'github' => Inertia::defer(fn (): array => $github->get($repositories), 'github'),
        ]);
    }

    #[Put(uri: '/projects/{id}')]
    public function update(string $id, UpdateProjectRequest $request, SharedKnowledgeProjectRepository $projects): RedirectResponse
    {
        try {
            $projects->update($id, [
                'name' => $request->string('name')->toString(),
                'status' => $request->string('status')->toString(),
            ]);
        } catch (InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        return to_route('ProjectController.index')->with('success', 'Project updated.');
    }
}
