<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Operations\HerdrFleetSnapshot;
use Inertia\Inertia;
use Inertia\Response;
use NckRtl\Waymaker\Get;

final class HerdrController extends Controller
{
    #[Get(uri: '/herdr')]
    public function index(HerdrFleetSnapshot $fleet): Response
    {
        return inertia('Herdr/Index', ['fleet' => Inertia::defer(fn (): array => $fleet->get())]);
    }
}
