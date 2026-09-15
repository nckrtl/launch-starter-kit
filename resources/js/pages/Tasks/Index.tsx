import { Head, Link } from "@inertiajs/react";
import { Check, ChevronRight, LoaderCircle } from "lucide-react";
import { useState } from "react";
import {
    index as projects,
    show as projectShow,
} from "@/actions/App/Http/Controllers/ProjectController";
import { index, show } from "@/actions/App/Http/Controllers/TaskController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { ProjectTabs } from "@/components/project-tabs";
import { TaskEditor, type TaskBrief, type TaskEditorState } from "@/components/tasks/task-editor";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from "@/components/ui/empty";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

type TaskFilter = "all" | "in-progress" | "completed";

const taskFilters: { value: TaskFilter; label: string; emptyLabel: string }[] = [
    { value: "all", label: "All", emptyLabel: "No tasks yet" },
    { value: "in-progress", label: "In progress", emptyLabel: "No tasks in progress" },
    { value: "completed", label: "Completed", emptyLabel: "No completed tasks" },
];

function filterTasks(tasks: TaskBrief[], filter: TaskFilter) {
    if (filter === "all") {
        return [
            ...tasks.filter((task) => task.status !== "completed"),
            ...tasks.filter((task) => task.status === "completed"),
        ];
    }

    return tasks.filter((task) =>
        filter === "completed" ? task.status === "completed" : task.status !== "completed",
    );
}

function TaskStatusIcon({ status }: { status: string }) {
    return status === "completed" ? (
        <Check
            aria-hidden="true"
            data-task-status-icon="completed"
            className="size-4 shrink-0 text-muted-foreground"
        />
    ) : (
        <LoaderCircle
            aria-hidden="true"
            data-task-status-icon="in-progress"
            className="size-4 shrink-0 animate-spin text-muted-foreground motion-reduce:animate-none"
        />
    );
}

function TaskTable({
    projectId,
    tasks,
    label,
    emptyLabel,
}: {
    projectId: string;
    tasks: TaskBrief[];
    label: string;
    emptyLabel: string;
}) {
    if (tasks.length === 0) {
        return (
            <Empty className="rounded-lg border">
                <EmptyHeader>
                    <EmptyTitle>{emptyLabel}</EmptyTitle>
                    <EmptyDescription>Choose another tab to see other tasks.</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <div className="rounded-lg border">
            <Table aria-label={label}>
                <TableHeader>
                    <TableRow>
                        <TableHead>Task</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Subtasks</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {tasks.map((task) => (
                        <TableRow
                            key={task.id}
                            className="group relative cursor-pointer focus-within:bg-muted/50 focus-within:ring-2 focus-within:ring-ring"
                        >
                            <TableCell>
                                <Link
                                    href={show([projectId, task.id])}
                                    prefetch
                                    className="flex items-center gap-2 font-medium after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none"
                                >
                                    <TaskStatusIcon status={task.status} />
                                    {task.title}
                                </Link>
                            </TableCell>
                            <TableCell>
                                <Badge variant="secondary">
                                    {task.status.replaceAll("_", " ")}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <span className="flex items-center justify-end gap-2">
                                    {task.children_count}
                                    <ChevronRight
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                    />
                                </span>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

export default function TasksIndex({
    project,
    tasks,
}: {
    project: { id: string; name: string };
    tasks: TaskBrief[];
}) {
    const [editor, setEditor] = useState<TaskEditorState | null>(null);

    return (
        <AppSidebarLayout
            breadcrumbs={[
                { title: "Projects", href: projects.url() },
                { title: project.name, href: projectShow.url(project.id) },
                { title: "Tasks", href: index.url(project.id) },
            ]}
        >
            <Head title={project.name + " tasks"} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-3xl font-semibold tracking-tight">Tasks</h1>
                        <p className="text-sm text-muted-foreground">
                            {project.name} · Top-level task groups. Open one to see its subtask
                            breakdown.
                        </p>
                    </div>
                    <Button onClick={() => setEditor({ creationKey: crypto.randomUUID() })}>
                        Create task
                    </Button>
                </div>
                <ProjectTabs projectId={project.id} active="tasks">
                    <TabsContent value="overview" />
                    <TabsContent value="instances" />
                    <TabsContent value="github" />
                    <TabsContent value="tasks" className="flex flex-col gap-6">
                        {tasks.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>No tasks yet</EmptyTitle>
                                    <EmptyDescription>
                                        Create a group for a feature, then add one subtask per
                                        objective. Planning can happen outside Commander.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Tabs defaultValue="all" className="gap-4">
                                <TabsList variant="line" aria-label="Task status">
                                    <TabsTrigger value="all">All</TabsTrigger>
                                    <TabsTrigger value="in-progress">In progress</TabsTrigger>
                                    <TabsTrigger value="completed">Completed</TabsTrigger>
                                </TabsList>
                                {taskFilters.map((filter) => (
                                    <TabsContent key={filter.value} value={filter.value}>
                                        <TaskTable
                                            projectId={project.id}
                                            tasks={filterTasks(tasks, filter.value)}
                                            label={`${filter.label} tasks`}
                                            emptyLabel={filter.emptyLabel}
                                        />
                                    </TabsContent>
                                ))}
                            </Tabs>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Top-level tasks are listed newest first. Their order does not control
                            execution.
                        </p>
                        <TaskEditor
                            projectId={project.id}
                            editor={editor}
                            onClose={() => setEditor(null)}
                        />
                    </TabsContent>
                </ProjectTabs>
            </main>
        </AppSidebarLayout>
    );
}
