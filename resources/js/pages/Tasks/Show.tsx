import { Head, Link, router } from "@inertiajs/react";
import { ArrowDown, ArrowUp } from "lucide-react";
import { useEffect, useState } from "react";
import {
    index as projects,
    show as projectShow,
} from "@/actions/App/Http/Controllers/ProjectController";
import { index, reorder, show } from "@/actions/App/Http/Controllers/TaskController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { TaskEditor, type TaskBrief, type TaskEditorState } from "@/components/tasks/task-editor";
import { TaskTerminal, type TaskTerminalSession } from "@/components/tasks/task-terminal";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from "@/components/ui/empty";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

type TaskDetail = TaskBrief & {
    editable: boolean;
    parent: { id: number; title: string } | null;
    root: { id: number; title: string };
    children: TaskBrief[];
    ordered_ids: number[];
    terminal_sessions: TaskTerminalSession[];
};

type Lane = "todo" | "in-progress" | "done";

const lanes: { value: Lane; label: string; description: string }[] = [
    { value: "todo", label: "To do", description: "Ready for work" },
    { value: "in-progress", label: "In progress", description: "Active work" },
    { value: "done", label: "Done", description: "Completed work" },
];

function laneFor(status: string): Lane {
    if (status === "pending") return "todo";
    if (status === "completed") return "done";
    return "in-progress";
}

function statusLabel(status: string) {
    const label = status.replaceAll("_", " ");
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function formatStartTime(value: string) {
    return `${new Date(value).toISOString().slice(0, 16).replace("T", " ")} UTC`;
}

function formatDuration(seconds: number) {
    const wholeSeconds = Math.max(0, Math.floor(seconds));
    const days = Math.floor(wholeSeconds / 86_400);
    const hours = Math.floor((wholeSeconds % 86_400) / 3_600);
    const minutes = Math.floor((wholeSeconds % 3_600) / 60);
    const remainingSeconds = wholeSeconds % 60;

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m ${remainingSeconds}s`;
    return `${remainingSeconds}s`;
}

function Brief({ task }: { task: TaskBrief }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-medium">Objective and context</h3>
                <p className="text-sm whitespace-pre-wrap wrap-anywhere text-muted-foreground">
                    {task.description || "No objective or context provided."}
                </p>
            </div>
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-medium">Acceptance criteria</h3>
                <p className="text-sm whitespace-pre-wrap wrap-anywhere text-muted-foreground">
                    {task.acceptance_criteria || "No acceptance criteria provided."}
                </p>
            </div>
        </div>
    );
}

function Timing({ task, elapsedTick = 0 }: { task: TaskBrief; elapsedTick?: number }) {
    if (task.timing === null) {
        return <span className="text-sm text-muted-foreground">Not started</span>;
    }

    const elapsed =
        task.timing.elapsed_seconds + (task.timing.finished_at === null ? elapsedTick : 0);

    return (
        <dl className="grid gap-1 text-sm text-muted-foreground">
            <div className="flex flex-wrap gap-x-1.5">
                <dt>Started</dt>
                <dd>
                    <time dateTime={task.timing.started_at}>
                        {formatStartTime(task.timing.started_at)}
                    </time>
                </dd>
            </div>
            <div className="flex flex-wrap gap-x-1.5">
                <dt>Duration</dt>
                <dd data-task-duration>{formatDuration(elapsed)}</dd>
            </div>
        </dl>
    );
}

function TaskBriefCard({ task }: { task: TaskBrief }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Brief</CardTitle>
                <CardDescription>The objective and conditions for success.</CardDescription>
            </CardHeader>
            <CardContent>
                <Brief task={task} />
            </CardContent>
        </Card>
    );
}

function TaskSessions({ projectId, task }: { projectId: string; task: TaskDetail }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Sessions</CardTitle>
                <CardDescription>
                    Live, read-only views of the panes Herdr assigned to this task.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {task.terminal_sessions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No Herdr sessions have been recorded for this task yet.
                    </p>
                ) : task.terminal_sessions.length === 1 ? (
                    <TaskTerminal
                        projectId={projectId}
                        taskId={task.id}
                        session={task.terminal_sessions[0]}
                    />
                ) : (
                    <Tabs defaultValue={task.terminal_sessions[0].role} className="gap-3">
                        <TabsList variant="line" aria-label="Task sessions">
                            {task.terminal_sessions.map((session) => (
                                <TabsTrigger key={session.role} value={session.role}>
                                    {session.label}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        {task.terminal_sessions.map((session) => (
                            <TabsContent key={session.role} value={session.role}>
                                <TaskTerminal
                                    projectId={projectId}
                                    taskId={task.id}
                                    session={session}
                                />
                            </TabsContent>
                        ))}
                    </Tabs>
                )}
            </CardContent>
        </Card>
    );
}

export default function TaskShow({
    project,
    task,
}: {
    project: { id: string; name: string };
    task: TaskDetail;
}) {
    const [editor, setEditor] = useState<TaskEditorState | null>(null);
    const [reordering, setReordering] = useState(false);
    const [orderError, setOrderError] = useState<string | null>(null);
    const [elapsedTick, setElapsedTick] = useState(0);
    const displayedStatus =
        task.kind === "group" && task.status === "pending" && !task.editable
            ? "In progress"
            : statusLabel(task.status);
    const tracksActiveDuration =
        task.timing?.finished_at === null ||
        task.children.some((child) => child.timing?.finished_at === null);

    useEffect(() => {
        if (!tracksActiveDuration) return;

        const timer = window.setInterval(() => setElapsedTick((tick) => tick + 1), 1_000);
        return () => window.clearInterval(timer);
    }, [tracksActiveDuration]);

    function move(indexToMove: number, direction: -1 | 1) {
        if (reordering || !task.editable) return;
        const ordered = [...task.ordered_ids];
        const destination = indexToMove + direction;
        if (destination < 0 || destination >= ordered.length) return;
        [ordered[indexToMove], ordered[destination]] = [ordered[destination], ordered[indexToMove]];
        setReordering(true);
        setOrderError(null);
        router.put(
            reorder.url([project.id, task.id]),
            { ordered_ids: ordered, expected_ids: task.ordered_ids },
            {
                preserveScroll: true,
                onError: (errors) => setOrderError(Object.values(errors).join(" ")),
                onFinish: () => setReordering(false),
            },
        );
    }

    const taskBoard = (
        <section aria-label="Tasks" className="flex flex-col gap-4" aria-busy={reordering}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Open a task to see its full brief and run information.
                </p>
                {task.editable && (
                    <Button
                        disabled={reordering}
                        onClick={() =>
                            setEditor({ creationKey: crypto.randomUUID(), parentId: task.id })
                        }
                    >
                        Add task
                    </Button>
                )}
            </div>
            {orderError && (
                <Alert variant="destructive">
                    <AlertDescription>
                        {orderError}
                        <Button
                            variant="link"
                            onClick={() => {
                                setOrderError(null);
                                router.reload();
                            }}
                        >
                            Reload tasks
                        </Button>
                    </AlertDescription>
                </Alert>
            )}
            {task.children.length === 0 ? (
                <Empty className="rounded-lg border">
                    <EmptyHeader>
                        <EmptyTitle>No tasks yet</EmptyTitle>
                        <EmptyDescription>
                            Add one objective at a time. Each new task goes at the end of the chain.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-3">
                    {lanes.map((lane) => {
                        const laneTasks = task.children
                            .map((child, indexInOrder) => ({ child, indexInOrder }))
                            .filter(({ child }) => laneFor(child.status) === lane.value);

                        return (
                            <section
                                key={lane.value}
                                aria-label={`${lane.label} tasks`}
                                data-task-lane={lane.value}
                                className="flex min-w-0 flex-col gap-3 rounded-xl bg-muted/35 p-3 ring-1 ring-foreground/10"
                            >
                                <div className="flex items-start justify-between gap-3 px-1">
                                    <div>
                                        <h2 className="font-medium">{lane.label}</h2>
                                        <p className="text-xs text-muted-foreground">
                                            {lane.description}
                                        </p>
                                    </div>
                                    <Badge variant="secondary">{laneTasks.length}</Badge>
                                </div>
                                {laneTasks.length === 0 ? (
                                    <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                                        No tasks
                                    </p>
                                ) : (
                                    <ol className="flex flex-col gap-3">
                                        {laneTasks.map(({ child, indexInOrder }) => (
                                            <li key={child.id} data-task-id={child.id}>
                                                <Card
                                                    size="sm"
                                                    className="group relative transition-colors hover:bg-muted/50 focus-within:ring-2 focus-within:ring-ring"
                                                >
                                                    <CardHeader>
                                                        <CardTitle>
                                                            <Link
                                                                href={show([project.id, child.id])}
                                                                prefetch
                                                                className="wrap-anywhere after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none"
                                                            >
                                                                #{indexInOrder + 1} {child.title}
                                                            </Link>
                                                        </CardTitle>
                                                        <CardAction className="pointer-events-none relative z-10 text-xs text-muted-foreground tabular-nums">
                                                            ID {child.id}
                                                        </CardAction>
                                                    </CardHeader>
                                                    {(child.timing !== null || task.editable) && (
                                                        <CardContent className="flex flex-col gap-3">
                                                            {child.timing !== null && (
                                                                <Timing
                                                                    task={child}
                                                                    elapsedTick={elapsedTick}
                                                                />
                                                            )}
                                                            {task.editable && (
                                                                <div className="relative z-10 flex flex-wrap gap-1 border-t pt-3">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        disabled={reordering}
                                                                        aria-label={`Edit ${child.title}`}
                                                                        onClick={() =>
                                                                            setEditor({
                                                                                task: child,
                                                                            })
                                                                        }
                                                                    >
                                                                        Edit
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon-sm"
                                                                        disabled={
                                                                            reordering ||
                                                                            indexInOrder === 0
                                                                        }
                                                                        aria-label={`Move ${child.title} up`}
                                                                        onClick={() =>
                                                                            move(indexInOrder, -1)
                                                                        }
                                                                    >
                                                                        <ArrowUp data-icon="inline-start" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon-sm"
                                                                        disabled={
                                                                            reordering ||
                                                                            indexInOrder ===
                                                                                task.children
                                                                                    .length -
                                                                                    1
                                                                        }
                                                                        aria-label={`Move ${child.title} down`}
                                                                        onClick={() =>
                                                                            move(indexInOrder, 1)
                                                                        }
                                                                    >
                                                                        <ArrowDown data-icon="inline-start" />
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </CardContent>
                                                    )}
                                                </Card>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>
                        );
                    })}
                </div>
            )}
        </section>
    );

    return (
        <AppSidebarLayout
            breadcrumbs={[
                { title: "Projects", href: projects.url() },
                { title: project.name, href: projectShow.url(project.id) },
                { title: "Tasks", href: index.url(project.id) },
                { title: task.title, href: show.url([project.id, task.id]) },
            ]}
        >
            <Head title={task.title} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                {task.parent ? (
                    <Link
                        href={show([project.id, task.parent.id])}
                        className="text-sm text-muted-foreground hover:underline"
                    >
                        Back to {task.parent.title}
                    </Link>
                ) : (
                    <Link
                        href={index(project.id)}
                        className="text-sm text-muted-foreground hover:underline"
                    >
                        Back to tasks
                    </Link>
                )}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        <h1 className="text-3xl font-semibold wrap-anywhere tracking-tight">
                            {task.title}
                        </h1>
                        <div data-task-heading-meta className="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">{displayedStatus}</Badge>
                            <span className="text-sm text-muted-foreground">Task #{task.id}</span>
                        </div>
                    </div>
                    {task.editable && (
                        <Button variant="outline" onClick={() => setEditor({ task })}>
                            Edit task
                        </Button>
                    )}
                </div>
                {!task.editable && (
                    <Alert>
                        <AlertDescription>
                            Execution has started. This feature’s briefs and task order are frozen.
                        </AlertDescription>
                    </Alert>
                )}
                {task.kind === "group" ? (
                    <Tabs defaultValue="tasks" className="gap-4">
                        <TabsList variant="line" aria-label="Task group sections">
                            <TabsTrigger value="tasks">Tasks</TabsTrigger>
                            <TabsTrigger value="brief">Brief</TabsTrigger>
                        </TabsList>
                        <TabsContent value="tasks">
                            <div className="flex flex-col gap-4">
                                {taskBoard}
                                <TaskSessions projectId={project.id} task={task} />
                            </div>
                        </TabsContent>
                        <TabsContent value="brief">
                            <TaskBriefCard task={task} />
                        </TabsContent>
                    </Tabs>
                ) : (
                    <div className="flex flex-col gap-4">
                        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                            <TaskBriefCard task={task} />
                            <Card>
                                <CardHeader>
                                    <CardTitle>Run information</CardTitle>
                                    <CardDescription>
                                        Timing starts with the first attempt and stops when the task
                                        is done.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Timing task={task} elapsedTick={elapsedTick} />
                                </CardContent>
                            </Card>
                        </div>
                        <TaskSessions projectId={project.id} task={task} />
                    </div>
                )}
                <TaskEditor
                    projectId={project.id}
                    editor={editor}
                    onClose={() => setEditor(null)}
                />
            </main>
        </AppSidebarLayout>
    );
}
