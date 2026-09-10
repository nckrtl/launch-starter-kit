import { Head, usePoll } from "@inertiajs/react";
import { useState } from "react";
import { index } from "@/actions/App/Http/Controllers/HerdrController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { Badge } from "@/components/ui/badge";
import { Empty, EmptyHeader, EmptyTitle, EmptyDescription } from "@/components/ui/empty";
import { Skeleton } from "@/components/ui/skeleton";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Fleet = {
    fetched_at: string;
    sessions: {
        node: string;
        name: string;
        status: string;
        workspaces: { id: string; label: string }[];
        agents: { name: string; kind: string; status: string; workspace_id: string }[];
    }[];
};

export default function HerdrIndex({ fleet }: { fleet?: Fleet }) {
    const [machine, setMachine] = useState("");
    const [workspace, setWorkspace] = useState("");
    usePoll(15_000, { only: ["fleet"], preserveUrl: true }, { mode: "rest" });

    const sessions = fleet?.sessions ?? [];
    const agents = sessions.flatMap((session) => {
        const labels = new Map(session.workspaces.map((item) => [item.id, item.label]));
        return session.agents.map((agent, position) => ({
            ...agent,
            key: JSON.stringify([
                session.node,
                session.name,
                agent.workspace_id,
                agent.name,
                position,
            ]),
            machine: session.node,
            workspace: labels.get(agent.workspace_id) || agent.workspace_id || "No workspace",
        }));
    });
    const machines = [...new Set(sessions.map((session) => session.node))].sort();
    const workspaces = [...new Set(agents.map((agent) => agent.workspace))].sort();
    const visibleAgents = agents.filter(
        (agent) =>
            (!machine || agent.machine === machine) &&
            (!workspace || agent.workspace === workspace),
    );
    const unavailable = sessions.filter(
        (session) => session.status !== "online" && (!machine || session.node === machine),
    );

    return (
        <AppSidebarLayout breadcrumbs={[{ title: "Herdr", href: index.url() }]}>
            <Head title="Herdr" />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight">Herdr</h1>
                        <p className="text-muted-foreground">
                            Coding agents across all configured sessions.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-4" aria-label="Agent filters">
                        <label className="flex flex-col gap-1 text-sm">
                            Machine
                            <NativeSelect
                                aria-label="Machine"
                                value={machine}
                                onChange={(event) => setMachine(event.target.value)}
                            >
                                <NativeSelectOption value="">All machines</NativeSelectOption>
                                {machines.map((name) => (
                                    <NativeSelectOption key={name} value={name}>
                                        {name}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Project / workspace
                            <NativeSelect
                                aria-label="Project / workspace"
                                value={workspace}
                                onChange={(event) => setWorkspace(event.target.value)}
                            >
                                <NativeSelectOption value="">All workspaces</NativeSelectOption>
                                {workspaces.map((name) => (
                                    <NativeSelectOption key={name} value={name}>
                                        {name}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </label>
                    </div>
                </div>
                {unavailable.length > 0 ? (
                    <p role="alert">
                        Sessions unavailable:{" "}
                        {unavailable
                            .map((session) => session.node + " / " + session.name)
                            .join(", ")}
                        . Retrying every 15 seconds.
                    </p>
                ) : null}
                <div className="rounded-lg border">
                    <Table aria-label="Herdr agents" aria-busy={!fleet}>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Agent</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Workspace</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Machine</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {!fleet ? (
                                <TableRow>
                                    <TableCell colSpan={5}>
                                        <div role="status" className="flex flex-col gap-3 py-4">
                                            <p>Loading Herdr agents…</p>
                                            <Skeleton className="h-20 w-full" />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : visibleAgents.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={5}>
                                        <Empty>
                                            <EmptyHeader>
                                                <EmptyTitle>
                                                    {sessions.length === 0
                                                        ? "No Herdr sessions configured"
                                                        : unavailable.length > 0
                                                          ? "No agents available for these filters"
                                                          : machine || workspace
                                                            ? "No agents match these filters"
                                                            : "No agents in configured sessions"}
                                                </EmptyTitle>
                                                <EmptyDescription>
                                                    {unavailable.length > 0
                                                        ? "Some session data could not be loaded."
                                                        : "Agents from matching sessions will appear here."}
                                                </EmptyDescription>
                                            </EmptyHeader>
                                        </Empty>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                visibleAgents.map((agent) => (
                                    <TableRow key={agent.key}>
                                        <TableCell>{agent.name}</TableCell>
                                        <TableCell>{agent.kind}</TableCell>
                                        <TableCell>{agent.workspace}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{agent.status}</Badge>
                                        </TableCell>
                                        <TableCell>{agent.machine}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
                {fleet ? (
                    <p role="status" className="text-sm text-muted-foreground">
                        {visibleAgents.length} of {agents.length} agents
                    </p>
                ) : null}
                {fleet ? (
                    <p className="text-xs text-muted-foreground">
                        Last checked {fleet.fetched_at}. Refreshes every 15 seconds.
                    </p>
                ) : null}
            </main>
        </AppSidebarLayout>
    );
}
