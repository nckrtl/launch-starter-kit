<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use LogicException;

abstract class TaskTool extends Tool
{
    /** @param Closure(): array<string, mixed> $callback */
    protected function respond(Closure $callback): Response|ResponseFactory
    {
        try {
            return Response::structured($callback());
        } catch (ModelNotFoundException) {
            return Response::error('Task not found in this project.');
        } catch (InvalidArgumentException|LogicException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
