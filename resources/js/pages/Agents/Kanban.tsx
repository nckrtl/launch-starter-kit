import { Head, usePoll } from "@inertiajs/react";
import { useState } from "react";
import { index } from "@/actions/App/Http/Controllers/AgentController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

type CardData = {
    id: string;
    title: string;
    status: string;
    assignee: string;
    project: string;
    project_name: string;
};
type Snapshot = {
    status: string;
    boards: { slug: string; name: string }[];
    cards: CardData[];
    unavailable: string[];
    fetched_at: string;
    refresh_failed?: boolean;
};
const statuses = [
    "triage",
    "todo",
    "ready",
    "running",
    "blocked",
    "scheduled",
    "review",
    "done",
    "archived",
];
const label = (value: string) =>
    value === "todo" ? "To do" : value.charAt(0).toUpperCase() + value.slice(1);

export default function AgentsKanban({
    kanban: savedKanban,
    freshKanban,
}: {
    kanban?: Snapshot | null;
    freshKanban?: Snapshot;
}) {
    const kanban = freshKanban ?? savedKanban;
    const [project, setProject] = useState("");
    const [agent, setAgent] = useState("");
    usePoll(15_000, { only: ["freshKanban"], preserveUrl: true }, { mode: "rest" });
    const allCards = kanban?.cards ?? [];
    const agents = [
        ...new Set(["anna", "tom", ...allCards.map((card) => card.assignee).filter(Boolean)]),
    ].sort();
    const cards = allCards.filter(
        (card) =>
            (!project || card.project === project) &&
            (!agent || (agent === "unassigned" ? !card.assignee : card.assignee === agent)),
    );
    const columns = [...new Set([...statuses, ...allCards.map((card) => card.status)])].filter(
        (status) => !kanban || cards.some((card) => card.status === status),
    );

    return (
        <AppSidebarLayout breadcrumbs={[{ title: "Agents", href: index.url() }]}>
            <Head title="Agents" />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight">Agents</h1>
                        <p className="text-muted-foreground">
                            All Hermes project cards in one Kanban board.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <label className="flex flex-col gap-1 text-sm">
                            Project
                            <select
                                aria-label="Project"
                                value={project}
                                onChange={(event) => setProject(event.target.value)}
                                className="h-9 rounded-md border bg-background px-3"
                            >
                                <option value="">All projects</option>
                                {kanban?.boards.map((board) => (
                                    <option key={board.slug} value={board.slug}>
                                        {board.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Agent
                            <select
                                aria-label="Agent"
                                value={agent}
                                onChange={(event) => setAgent(event.target.value)}
                                className="h-9 rounded-md border bg-background px-3"
                            >
                                <option value="">All agents</option>
                                {agents.map((name) => (
                                    <option key={name} value={name}>
                                        {label(name)}
                                    </option>
                                ))}
                                <option value="unassigned">Unassigned</option>
                            </select>
                        </label>
                    </div>
                </div>
                {!kanban ? (
                    <div role="status">
                        <p>Loading Hermes cards…</p>
                    </div>
                ) : kanban.status === "unavailable" ? (
                    <p role="alert">Hermes is unavailable. Retrying every 15 seconds.</p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            <span>{cards.length} cards</span>
                            <span>Updated {kanban.fetched_at}</span>
                            <span>Read-only · Refreshes every 15 seconds</span>
                            {!freshKanban ? (
                                <span role="status">Refreshing in background…</span>
                            ) : null}
                        </div>
                        {kanban.refresh_failed ? (
                            <p role="alert">
                                Could not fully refresh Hermes. Showing the last saved board.
                            </p>
                        ) : null}
                        {kanban.unavailable.length ? (
                            <p role="alert">
                                Could not load: {kanban.unavailable.join(", ")}. Other projects are
                                shown.
                            </p>
                        ) : null}
                        {cards.length === 0 ? (
                            <p role="status">No cards match these filters.</p>
                        ) : null}
                    </>
                )}
                <div
                    className="flex min-h-[65vh] gap-4 overflow-x-auto pb-4"
                    aria-label="Hermes Kanban board"
                    aria-busy={!kanban}
                >
                    {columns.map((status) => {
                        const items = cards.filter((card) => card.status === status);
                        return (
                            <section
                                key={status}
                                aria-label={label(status)}
                                className="flex w-72 shrink-0 flex-col gap-3 rounded-lg bg-kanban-lane p-3"
                            >
                                <h2 className="flex items-center justify-between font-medium">
                                    {label(status)}
                                    {kanban && kanban.status !== "unavailable" ? (
                                        <Badge variant="secondary">{items.length}</Badge>
                                    ) : null}
                                </h2>
                                <div className="flex max-h-[65vh] flex-col gap-3 overflow-y-auto">
                                    {!kanban ? (
                                        <div
                                            role="status"
                                            aria-label={`Loading ${label(status)} cards`}
                                            className="flex flex-col gap-3"
                                        >
                                            {[0, 1, 2].map((placeholder) => (
                                                <Skeleton
                                                    key={placeholder}
                                                    className="h-28 w-full shrink-0 rounded-xl bg-[var(--kanban-card)]"
                                                />
                                            ))}
                                        </div>
                                    ) : null}
                                    {kanban?.status !== "unavailable" &&
                                    kanban &&
                                    items.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">No cards</p>
                                    ) : null}
                                    {items.map((card) => (
                                        <Card
                                            key={`${card.project}:${card.id}`}
                                            size="sm"
                                            className="shrink-0 [--card:var(--kanban-card)]"
                                        >
                                            <CardHeader>
                                                <CardDescription>
                                                    {card.project_name}
                                                </CardDescription>
                                                <CardTitle>{card.title}</CardTitle>
                                            </CardHeader>
                                            <CardContent className="flex items-center justify-between gap-2">
                                                <Badge variant="outline">
                                                    {card.assignee
                                                        ? label(card.assignee)
                                                        : "Unassigned"}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    {card.id}
                                                </span>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </main>
        </AppSidebarLayout>
    );
}
