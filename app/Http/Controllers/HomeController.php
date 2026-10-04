<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Response;
use Inertia\ResponseFactory;

class HomeController extends Controller
{
    public function show(): ResponseFactory|Response
    {
        return inertia('Home');
    }
}
