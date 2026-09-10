import { Head, Link } from "@inertiajs/react";
import { index, show } from "@/actions/App/Http/Controllers/ProjectController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { Badge } from "@/components/ui/badge";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from "@/components/ui/card";
import { Empty, EmptyHeader, EmptyTitle, EmptyDescription } from "@/components/ui/empty";
import { Skeleton } from "@/components/ui/skeleton";
import {
    Table,
    TableHeader,
    TableHead,
    TableBody,
    TableRow,
    TableCell,
} from "@/components/ui/table";

type Project = {
    id: string;
    name: string;
    status: string;
    repositories: string[];
    channels: { name: string; url: string }[];
    applications: { name: string; url: string | null }[];
    locations: { machine: string; path: string }[];
};
type Orbit = {
    status: string;
    checked_at: string | null;
    unmatched: string[];
    apps: {
        name: string;
        instances: {
            name: string;
            node: string;
            environment: string;
            branch: string;
            status: string;
            url: string | null;
        }[];
    }[];
};
type Item = { number: number; title: string; url: string; draft: boolean };
type Repository = {
    name: string;
    url: string;
    status: string;
    pull_requests: Item[];
    issues: Item[];
    pull_request_count: number;
    issue_count: number;
    checked_at: string;
};

function ExternalLink({ href, children }: { href: string; children: React.ReactNode }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="break-all text-primary underline-offset-4 hover:underline"
        >
            {children}
        </a>
    );
}

function NoData({ title, description }: { title: string; description?: string }) {
    return (
        <Empty>
            <EmptyHeader>
                <EmptyTitle>{title}</EmptyTitle>
                {description ? <EmptyDescription>{description}</EmptyDescription> : null}
            </EmptyHeader>
        </Empty>
    );
}

function Loading({ source }: { source: string }) {
    return (
        <div role="status" className="flex flex-col gap-3">
            <p className="text-sm text-muted-foreground">Loading {source}…</p>
            <Skeleton className="h-20 w-full" />
        </div>
    );
}

export default function ProjectShow({
    project,
    orbit,
    github,
}: {
    project: Project;
    orbit?: Orbit;
    github?: Repository[];
}) {
    return (
        <AppSidebarLayout
            breadcrumbs={[
                { title: "Projects", href: index.url() },
                { title: project.name, href: show.url(project.id) },
            ]}
        >
            <Head title={project.name} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-2">
                    <Link href={index()} className="text-sm text-muted-foreground hover:underline">
                        Back to projects
                    </Link>
                    <div className="flex items-center gap-3">
                        <h1 className="text-3xl font-semibold tracking-tight">{project.name}</h1>
                        <Badge variant="secondary">{project.status}</Badge>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Project links</CardTitle>
                        <CardDescription>
                            Repositories, Slack channels, and configured development URLs.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {project.repositories.map((repo) => (
                            <div key={repo}>
                                <ExternalLink href={`https://github.com/${repo}`}>
                                    {repo}
                                </ExternalLink>
                            </div>
                        ))}
                        {project.channels.map((channel) => (
                            <div key={channel.url}>
                                <ExternalLink href={channel.url}>
                                    Slack · #{channel.name}
                                </ExternalLink>
                            </div>
                        ))}
                        {project.applications
                            .filter((app) => app.url)
                            .map((app) => (
                                <div key={app.name}>
                                    {app.name}:{" "}
                                    <ExternalLink href={app.url!}>{app.url}</ExternalLink>
                                    <span className="text-sm text-muted-foreground">
                                        {" "}
                                        · Manifest URL
                                    </span>
                                </div>
                            ))}
                        {!project.repositories.length &&
                        !project.channels.length &&
                        !project.applications.some((app) => app.url) ? (
                            <NoData title="No project links configured" />
                        ) : null}
                        {project.locations.map((location) => (
                            <p
                                key={location.machine + location.path}
                                className="break-all text-sm text-muted-foreground"
                            >
                                {location.machine} · {location.path}
                            </p>
                        ))}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Orbit instances</CardTitle>
                        <CardDescription>
                            Registered instances visible to this machine. Cached for 30 seconds.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-5">
                        {!orbit ? (
                            <Loading source="Orbit" />
                        ) : orbit.status !== "available" ? (
                            <NoData
                                title="Orbit is unavailable"
                                description="The gateway could not be read. Project links are still available."
                            />
                        ) : (
                            <>
                                {orbit.apps.map((app) => (
                                    <section key={app.name} className="flex flex-col gap-3">
                                        <h2 className="font-medium">{app.name}</h2>
                                        {app.instances.length ? (
                                            <Table aria-label={`${app.name} instances`}>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>URL</TableHead>
                                                        <TableHead>Node</TableHead>
                                                        <TableHead>
                                                            <span className="sr-only">Actions</span>
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {app.instances.map((instance) => (
                                                        <TableRow
                                                            key={instance.node + instance.name}
                                                        >
                                                            <TableCell>
                                                                {instance.url ? (
                                                                    <ExternalLink
                                                                        href={instance.url}
                                                                    >
                                                                        {instance.url}
                                                                    </ExternalLink>
                                                                ) : (
                                                                    "No route"
                                                                )}
                                                            </TableCell>
                                                            <TableCell>{instance.node}</TableCell>
                                                            <TableCell className="text-right">
                                                                {instance.url ? (
                                                                    <ExternalLink
                                                                        href={instance.url}
                                                                    >
                                                                        Visit
                                                                        <span className="sr-only">
                                                                            {" "}
                                                                            {instance.url} (opens in
                                                                            a new tab)
                                                                        </span>
                                                                    </ExternalLink>
                                                                ) : null}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        ) : (
                                            <NoData title="No registered instances" />
                                        )}
                                    </section>
                                ))}
                                {orbit.unmatched.length ? (
                                    <NoData
                                        title="No unique Orbit app match"
                                        description={`${orbit.unmatched.join(", ")}. Add or check orbit_app_id in the project manifest to connect an app.`}
                                    />
                                ) : null}
                                {orbit.checked_at ? (
                                    <p className="text-xs text-muted-foreground">
                                        Checked {orbit.checked_at}
                                    </p>
                                ) : null}
                            </>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>GitHub activity</CardTitle>
                        <CardDescription>
                            Open pull requests and issues, most recently updated first. Up to 20 of
                            each per repository; cached for two minutes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        {!github ? (
                            <Loading source="GitHub" />
                        ) : !github.length ? (
                            <NoData title="No GitHub repositories configured" />
                        ) : (
                            github.map((repo) => (
                                <section key={repo.name} className="flex flex-col gap-4">
                                    <h2 className="font-medium">
                                        <ExternalLink href={repo.url}>{repo.name}</ExternalLink>
                                    </h2>
                                    {repo.status !== "available" ? (
                                        <NoData
                                            title="GitHub activity unavailable"
                                            description="Check this machine’s GitHub CLI access to the repository."
                                        />
                                    ) : (
                                        <div className="grid gap-6 lg:grid-cols-2">
                                            <Activity
                                                title="Open pull requests"
                                                items={repo.pull_requests}
                                                count={repo.pull_request_count}
                                                url={`${repo.url}/pulls`}
                                            />
                                            <Activity
                                                title="Open issues"
                                                items={repo.issues}
                                                count={repo.issue_count}
                                                url={`${repo.url}/issues`}
                                            />
                                        </div>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        Checked {repo.checked_at}
                                    </p>
                                </section>
                            ))
                        )}
                    </CardContent>
                </Card>
            </main>
        </AppSidebarLayout>
    );
}

function Activity({
    title,
    items,
    count,
    url,
}: {
    title: string;
    items: Item[];
    count: number;
    url: string;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-3">
            <h3 className="text-sm font-medium">
                {title} <Badge variant="secondary">{count}</Badge>
            </h3>
            {items.length ? (
                <ul className="flex flex-col gap-3">
                    {items.map((item) => (
                        <li key={item.number} className="text-sm">
                            <ExternalLink href={item.url}>
                                #{item.number} {item.title}
                            </ExternalLink>
                            {item.draft ? (
                                <Badge variant="outline" className="ml-2">
                                    Draft
                                </Badge>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-muted-foreground">None open.</p>
            )}
            <div className="text-sm">
                <ExternalLink href={url}>
                    {count > items.length ? `View all ${count} on GitHub` : "View on GitHub"}
                </ExternalLink>
            </div>
        </div>
    );
}
