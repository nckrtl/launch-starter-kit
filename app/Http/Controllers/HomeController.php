<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Operations\HerdrFleetSnapshot;
use App\Operations\HermesSnapshot;
use App\Projects\SharedKnowledgeProjectRepository;
use Inertia\Response;
use Inertia\ResponseFactory;
use NckRtl\Waymaker\Get;

class HomeController extends Controller
{
    #[Get(uri: '/')]
    public function show(
        SharedKnowledgeProjectRepository $projects,
        HermesSnapshot $hermes,
        HerdrFleetSnapshot $herdr,
    ): ResponseFactory|Response {
        return inertia('Home', [
            'projects' => $projects->all(),
            'hermes' => $hermes->get(),
            'herdr' => $herdr->get(),
        ]);
    }
}
