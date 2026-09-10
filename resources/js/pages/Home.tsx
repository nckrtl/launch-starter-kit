import { Head, usePoll } from "@inertiajs/react";
import { Activity, Bot, CircleAlert, FolderKanban, MonitorCog, Radio } from "lucide-react";
import { show } from "@/actions/App/Http/Controllers/HomeController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from "@/components/ui/empty";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Project = { id: string; name: string; status: string };
type Profile = {
    name: string;
    state: string;
    busy: boolean;
    active_sessions: Array<{ session_id: string; surface: string }>;
    version?: string | null;
};
type Board = {
    slug: string;
    name: string;
    description: string;
    counts: Record<string, number>;
    total: number;
};
type Signal = { kind: string; title: string; severity: string };
type HerdrSession = {
    node: string;
    name: string;
    status: string;
    agents: Array<{ name: string; status: string; workspace_id: string }>;
    workspaces: Array<{ id: string; label: string }>;
};
type Props = {
    projects: Project[];
    hermes: {
        status: string;
        node: string;
        profiles: Profile[];
        boards: Board[];
        signals: Signal[];
        error?: string;
    };
    herdr: { sessions: HerdrSession[] };
};

const statusVariant = (status: string): "default" | "destructive" | "secondary" =>
    status === "online" || status === "running" || status === "active"
        ? "default"
        : status === "unavailable"
          ? "destructive"
          : "secondary";

export default function Home({ projects, hermes, herdr }: Props) {
    usePoll(15_000, { only: ["hermes", "herdr"] }, { mode: "rest" });

    const activeAgents = herdr.sessions
        .flatMap((session) => session.agents)
        .filter((agent) => agent.status !== "idle").length;
    const runningCards = hermes.boards.reduce(
        (count, board) => count + (board.counts.running ?? 0),
        0,
    );

    return (
        <AppSidebarLayout breadcrumbs={[{ title: "Dashboard", href: show.url() }]}>
            <Head title="Operations" />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Orbit operations
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight">Commander</h1>
                        <p className="mt-1 text-muted-foreground">
                            Hermes, Herdr, and shared projects in one place.
                        </p>
                    </div>
                    <Badge variant={statusVariant(hermes.status)} className="w-fit gap-1.5">
                        <Radio className="size-3" /> Hermes {hermes.node}: {hermes.status}
                    </Badge>
                </div>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        title="Projects"
                        value={projects.length}
                        detail="shared-knowledge registry"
                        icon={FolderKanban}
                    />
                    <Metric
                        title="Running cards"
                        value={runningCards}
                        detail="across Hermes boards"
                        icon={Activity}
                    />
                    <Metric
                        title="Active agents"
                        value={activeAgents}
                        detail="across Herdr sessions"
                        icon={Bot}
                    />
                    <Metric
                        title="Sessions"
                        value={herdr.sessions.length}
                        detail="configured on Orbit nodes"
                        icon={MonitorCog}
                    />
                </section>

                <section className="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Hermes operators</CardTitle>
                            <CardDescription>
                                Live gateway and workload state from Mini.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                            {hermes.profiles.map((profile) => (
                                <div key={profile.name} className="rounded-lg border bg-card p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="flex items-center gap-3">
                                            <span className="size-2.5 rounded-full bg-foreground" />
                                            <div>
                                                <p className="font-semibold">{profile.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    Hermes {profile.version ?? ""}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge variant={profile.busy ? "secondary" : "outline"}>
                                            {profile.busy ? "Busy" : "Available"}
                                        </Badge>
                                    </div>
                                    <p className="mt-4 text-sm text-muted-foreground">
                                        {profile.active_sessions.length
                                            ? `${profile.active_sessions.length} active ${profile.active_sessions[0]?.surface ?? "agent"} session(s)`
                                            : "No active agent sessions"}
                                    </p>
                                </div>
                            ))}
                            {hermes.status !== "online" && (
                                <p className="text-sm text-destructive">{hermes.error}</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Kanban boards</CardTitle>
                            <CardDescription>
                                Current card totals and work in progress.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="px-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">Board</TableHead>
                                        <TableHead>Running</TableHead>
                                        <TableHead>Blocked</TableHead>
                                        <TableHead className="pr-6 text-right">Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {hermes.boards.map((board) => (
                                        <TableRow key={board.slug}>
                                            <TableCell className="pl-6">
                                                <div className="font-medium">{board.name}</div>
                                                <div className="max-w-80 truncate text-xs text-muted-foreground">
                                                    {board.description || board.slug}
                                                </div>
                                            </TableCell>
                                            <TableCell>{board.counts.running ?? 0}</TableCell>
                                            <TableCell>
                                                <span
                                                    className={
                                                        board.counts.blocked
                                                            ? "text-destructive"
                                                            : ""
                                                    }
                                                >
                                                    {board.counts.blocked ?? 0}
                                                </span>
                                            </TableCell>
                                            <TableCell className="pr-6 text-right tabular-nums">
                                                {board.total}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </section>

                <section className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Signal cards</CardTitle>
                            <CardDescription>
                                Diagnostics reported by the Orbit board.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {hermes.signals.length === 0 ? (
                                <Empty className="min-h-44 border border-dashed">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <CircleAlert />
                                        </EmptyMedia>
                                        <EmptyTitle>No active signals</EmptyTitle>
                                        <EmptyDescription>
                                            Hermes did not report board diagnostics.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                hermes.signals.map((signal, index) => (
                                    <div
                                        key={`${signal.kind}-${index}`}
                                        className="mb-2 rounded-lg border p-3"
                                    >
                                        <Badge variant="outline">{signal.severity}</Badge>
                                        <p className="mt-2 text-sm font-medium">{signal.title}</p>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Herdr fleet</CardTitle>
                            <CardDescription>
                                Agent sessions grouped by machine and Herdr session.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {herdr.sessions.map((session) => (
                                <div
                                    key={`${session.node}-${session.name}`}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="font-semibold">
                                                {session.node} / {session.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {session.workspaces.length} workspaces ·{" "}
                                                {session.agents.length} agents
                                            </p>
                                        </div>
                                        <Badge variant={statusVariant(session.status)}>
                                            {session.status}
                                        </Badge>
                                    </div>
                                    {session.agents.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {session.agents.map((agent, index) => (
                                                <Badge
                                                    key={`${agent.workspace_id}-${index}`}
                                                    variant="outline"
                                                >
                                                    {agent.name} · {agent.status}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </section>
            </main>
        </AppSidebarLayout>
    );
}

function Metric({
    title,
    value,
    detail,
    icon: Icon,
}: {
    title: string;
    value: number;
    detail: string;
    icon: typeof Activity;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <Icon className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div className="text-3xl font-semibold tabular-nums">{value}</div>
                <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
            </CardContent>
        </Card>
    );
}
