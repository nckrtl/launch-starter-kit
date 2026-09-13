import { Head, usePoll } from "@inertiajs/react";
import { index } from "@/actions/App/Http/Controllers/AgentController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Empty, EmptyHeader, EmptyTitle, EmptyDescription } from "@/components/ui/empty";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Hermes = {
    status: string;
    node: string;
    fetched_at: string;
    profiles: {
        name: string;
        state: string;
        busy: boolean;
        version?: string | null;
        active_sessions: { session_id: string; surface: string; started_at?: string | null }[];
    }[];
};

export default function AgentsIndex({ hermes }: { hermes: Hermes }) {
    usePoll(15_000, { only: ["hermes"], preserveUrl: true }, { mode: "rest" });

    return (
        <AppSidebarLayout breadcrumbs={[{ title: "Agents", href: index.url() }]}>
            <Head title="Agents" />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight">Agents</h1>
                        <p className="text-muted-foreground">
                            Hermes agents and their active sessions.
                        </p>
                    </div>
                    <Badge variant={hermes.status === "online" ? "secondary" : "destructive"}>
                        {hermes.node}: {hermes.status}
                    </Badge>
                </div>
                {hermes.status !== "online" ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Hermes is unavailable</EmptyTitle>
                            <EmptyDescription>
                                Could not reach Hermes on {hermes.node}. This page retries every 15
                                seconds.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : hermes.profiles.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>No Hermes agents found</EmptyTitle>
                            <EmptyDescription>
                                No agent profiles were returned by Hermes.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {hermes.profiles.map((agent) => (
                            <Card key={agent.name}>
                                <CardHeader>
                                    <CardTitle>{agent.name}</CardTitle>
                                    <CardDescription>
                                        {hermes.node} · Hermes {agent.version || "version unknown"}{" "}
                                        · Gateway: {agent.state}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4">
                                    <div>
                                        <Badge variant={agent.busy ? "default" : "outline"}>
                                            {agent.busy
                                                ? "Busy"
                                                : agent.state === "running" ||
                                                    agent.state === "online"
                                                  ? "Available"
                                                  : "Not running"}
                                        </Badge>
                                    </div>
                                    {agent.active_sessions.length ? (
                                        <Table aria-label={`${agent.name} active sessions`}>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Session</TableHead>
                                                    <TableHead>Surface</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {agent.active_sessions.map((session) => (
                                                    <TableRow key={session.session_id}>
                                                        <TableCell>{session.session_id}</TableCell>
                                                        <TableCell>{session.surface}</TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No active agent sessions.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    Last checked {hermes.fetched_at}. Refreshes every 15 seconds.
                </p>
            </main>
        </AppSidebarLayout>
    );
}
