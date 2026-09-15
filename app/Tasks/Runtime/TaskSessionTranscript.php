<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskAgentDispatch;
use App\Models\TaskWorkspace;
use App\Tasks\Landing\TaskLandingData as Data;
use LogicException;

final readonly class TaskSessionTranscript
{
    /** @param array<string, mixed> $file */
    public function verify(TaskWorkspace $workspace, TaskAgentDispatch $dispatch, string $conversation, array $file): void
    {
        $path = Data::text($file, 'path');
        if (array_diff(array_keys($file), ['path', 'sha256']) !== [] || str_starts_with($path, $workspace->worktree.'/') || ! is_file($path)
            || (fileperms($path) & 0077) !== 0 || fileowner($path) !== posix_geteuid()) {
            throw new LogicException('Retain native transcripts in private owned files outside the task worktree.');
        }
        $contents = Data::file($path, 33_554_432);
        if (! hash_equals(Data::text($file, 'sha256'), hash('sha256', $contents))) {
            throw new LogicException('The preserved native transcript changed.');
        }
        $metadata = false;
        $assignment = false;
        foreach (explode("\n", $contents) as $line) {
            if ($line === '') {
                continue;
            }
            $event = Data::object(json_decode($line, true, 64, JSON_THROW_ON_ERROR));
            $payload = Data::object($event['payload'] ?? null);
            if (($event['type'] ?? null) === 'session_meta') {
                if ($metadata || ($payload['id'] ?? null) !== $conversation || ($payload['cwd'] ?? null) !== $workspace->worktree) {
                    throw new LogicException('The native transcript metadata does not match the exact conversation and checkout.');
                }
                $metadata = true;
            }
            $text = '';
            if (($event['type'] ?? null) === 'event_msg' && ($payload['type'] ?? null) === 'user_message') {
                $text = is_string($payload['message'] ?? null) ? $payload['message'] : '';
            } elseif (($event['type'] ?? null) === 'response_item' && ($payload['role'] ?? null) === 'user') {
                $content = $payload['content'] ?? [];
                if (is_array($content)) {
                    foreach ($content as $part) {
                        if (is_array($part) && is_string($part['text'] ?? null)) {
                            $text .= $part['text']."\n";
                        }
                    }
                }
            }
            if (str_contains($text, $dispatch->handoff_token)
                && preg_match('/\btasks:submit '.preg_quote((string) $dispatch->id, '/').'\s/', $text) === 1) {
                $assignment = true;
            }
        }
        if (! $metadata || ! $assignment) {
            throw new LogicException('The preserved conversation lacks its exact original token-bound task assignment.');
        }
    }
}
