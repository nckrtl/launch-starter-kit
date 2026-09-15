import { Head, Link, usePage } from "@inertiajs/react";
import { Globe2Icon } from "lucide-react";
import type { ComponentType, SVGProps } from "react";
import { index, show } from "@/actions/App/Http/Controllers/ProjectController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { ProjectTabs, type ProjectSection } from "@/components/project-tabs";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardAction, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Empty, EmptyHeader, EmptyTitle, EmptyDescription } from "@/components/ui/empty";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
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
};
type Orbit = {
    status: string;
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
type ActivityState = "all" | "open" | "closed";
type ActivityFilter = Exclude<ActivityState, "all">;
type Item = {
    number: number;
    title: string;
    url: string;
    draft: boolean;
    state: "open" | "closed" | "merged" | null;
};
type ActivityBucket = { items: Item[]; count: number };
type ActivityGroup = Record<ActivityState, ActivityBucket>;
type Repository = {
    name: string;
    url: string;
    status: string;
    pull_requests: ActivityGroup;
    issues: ActivityGroup;
};

const activityFilters: ActivityFilter[] = ["open", "closed"];
type Icon = ComponentType<SVGProps<SVGSVGElement>>;

function GithubIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...props}>
            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
        </svg>
    );
}

function SlackIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...props}>
            <path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52ZM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313ZM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834ZM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312ZM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834ZM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312ZM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52ZM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313Z" />
        </svg>
    );
}

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

function ProjectLink({
    href,
    icon: Icon,
    accessibleLabel,
    opensNewTab = false,
}: {
    href: string;
    icon: Icon;
    accessibleLabel: string;
    opensNewTab?: boolean;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                nativeButton={false}
                render={
                    <a
                        href={href}
                        aria-label={accessibleLabel}
                        target={opensNewTab ? "_blank" : undefined}
                        rel={opensNewTab ? "noopener noreferrer" : undefined}
                        className={buttonVariants({ variant: "ghost", size: "icon" })}
                    />
                }
            >
                <Icon />
            </TooltipTrigger>
            <TooltipContent>
                <p>{accessibleLabel}</p>
            </TooltipContent>
        </Tooltip>
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
    const hasProjectLinks =
        project.repositories.length > 0 ||
        project.channels.length > 0 ||
        project.applications.some((app) => app.url);
    const requestedSection = new URLSearchParams(usePage().url.split("?")[1] ?? "").get("section");
    const activeSection: ProjectSection =
        requestedSection === "instances" || requestedSection === "github"
            ? requestedSection
            : "overview";

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
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-semibold tracking-tight">
                                {project.name}
                            </h1>
                            <Badge variant="secondary">{project.status}</Badge>
                        </div>
                        {hasProjectLinks ? (
                            <nav aria-label="Project links" className="flex items-center gap-1">
                                {project.repositories.map((repo) => (
                                    <ProjectLink
                                        key={repo}
                                        href={`https://github.com/${repo}`}
                                        icon={GithubIcon}
                                        accessibleLabel={`Open ${repo} on GitHub`}
                                        opensNewTab
                                    />
                                ))}
                                {project.channels.map((channel) => (
                                    <ProjectLink
                                        key={channel.url}
                                        href={channel.url}
                                        icon={SlackIcon}
                                        accessibleLabel={`Open #${channel.name} in the Slack desktop app`}
                                    />
                                ))}
                                {project.applications
                                    .filter((app) => app.url)
                                    .map((app) => (
                                        <ProjectLink
                                            key={app.name}
                                            href={app.url!}
                                            icon={Globe2Icon}
                                            accessibleLabel={`Open ${app.name} website`}
                                            opensNewTab
                                        />
                                    ))}
                            </nav>
                        ) : null}
                    </div>
                </div>
                <ProjectTabs projectId={project.id} active={activeSection}>
                    <TabsContent value="overview" />
                    <TabsContent value="instances">
                        <OrbitPanel orbit={orbit} />
                    </TabsContent>
                    <TabsContent value="github">
                        <GitHubPanel repositories={github} />
                    </TabsContent>
                    <TabsContent value="tasks" />
                </ProjectTabs>
            </main>
        </AppSidebarLayout>
    );
}

function OrbitPanel({ orbit }: { orbit?: Orbit }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Orbit instances</CardTitle>
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
                            <div key={app.name}>
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
                                                <TableRow key={instance.node + instance.name}>
                                                    <TableCell>
                                                        {instance.url ? (
                                                            <ExternalLink href={instance.url}>
                                                                {instance.url}
                                                            </ExternalLink>
                                                        ) : (
                                                            "No route"
                                                        )}
                                                    </TableCell>
                                                    <TableCell>{instance.node}</TableCell>
                                                    <TableCell className="text-right">
                                                        {instance.url ? (
                                                            <ExternalLink href={instance.url}>
                                                                Visit
                                                                <span className="sr-only">
                                                                    {" "}
                                                                    {instance.url} (opens in a new
                                                                    tab)
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
                            </div>
                        ))}
                        {orbit.unmatched.length ? (
                            <NoData
                                title="No unique Orbit app match"
                                description={`${orbit.unmatched.join(", ")}. Add or check orbit_app_id in the project manifest to connect an app.`}
                            />
                        ) : null}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function GitHubPanel({ repositories }: { repositories?: Repository[] }) {
    if (!repositories) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>GitHub</CardTitle>
                </CardHeader>
                <CardContent>
                    <Loading source="GitHub" />
                </CardContent>
            </Card>
        );
    }

    if (!repositories.length) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>GitHub</CardTitle>
                </CardHeader>
                <CardContent>
                    <NoData title="No GitHub repositories configured" />
                </CardContent>
            </Card>
        );
    }

    return repositories.map((repository) => (
        <GitHubRepositoryPanel key={repository.name} repository={repository} />
    ));
}

function GitHubRepositoryPanel({ repository }: { repository: Repository }) {
    if (repository.status !== "available") {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>GitHub</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <NoData
                        title="GitHub unavailable"
                        description="Check this machine’s GitHub CLI access to the repository."
                    />
                </CardContent>
            </Card>
        );
    }

    return (
        <Tabs defaultValue="pull-requests">
            <Card>
                <CardHeader>
                    <CardTitle>GitHub</CardTitle>
                    <CardAction>
                        <TabsList aria-label={`${repository.name} GitHub content`}>
                            <TabsTrigger value="pull-requests" aria-label="Show pull requests">
                                Pull requests
                                <Badge variant="secondary">
                                    {repository.pull_requests.all.count}
                                </Badge>
                            </TabsTrigger>
                            <TabsTrigger value="issues" aria-label="Show issues">
                                Issues
                                <Badge variant="secondary">{repository.issues.all.count}</Badge>
                            </TabsTrigger>
                        </TabsList>
                    </CardAction>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <TabsContent value="pull-requests">
                        <ActivityTabs label="pull requests" activity={repository.pull_requests} />
                    </TabsContent>
                    <TabsContent value="issues">
                        <ActivityTabs label="issues" activity={repository.issues} />
                    </TabsContent>
                </CardContent>
            </Card>
        </Tabs>
    );
}

function ActivityTabs({ label, activity }: { label: string; activity: ActivityGroup }) {
    return (
        <Tabs defaultValue="open">
            <TabsList variant="line" aria-label={`${label} state`}>
                {activityFilters.map((state) => (
                    <TabsTrigger key={state} value={state} aria-label={`Show ${state} ${label}`}>
                        {state[0].toUpperCase() + state.slice(1)}
                        <Badge variant="secondary">{activity[state].count}</Badge>
                    </TabsTrigger>
                ))}
            </TabsList>
            {activityFilters.map((state) => (
                <TabsContent key={state} value={state} className="pt-2">
                    <ActivityItems label={label} state={state} bucket={activity[state]} />
                </TabsContent>
            ))}
        </Tabs>
    );
}

function ActivityItems({
    label,
    state,
    bucket,
}: {
    label: string;
    state: ActivityFilter;
    bucket: ActivityBucket;
}) {
    if (!bucket.items.length) {
        return (
            <p className="text-sm text-muted-foreground">
                No {state} {label}.
            </p>
        );
    }

    const stateLabel = state[0].toUpperCase() + state.slice(1);
    const itemLabel = label === "pull requests" ? "Pull request" : "Issue";

    return (
        <Table aria-label={`${stateLabel} ${label}`}>
            <TableHeader>
                <TableRow>
                    <TableHead>{itemLabel}</TableHead>
                    <TableHead className="w-36">Status</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {bucket.items.map((item) => (
                    <TableRow
                        key={item.number}
                        className="relative cursor-pointer focus-within:bg-muted/50 focus-within:ring-2 focus-within:ring-ring"
                    >
                        <TableCell>
                            <a
                                href={item.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={`Open #${item.number} ${item.title} on GitHub`}
                                className="font-medium after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none"
                            >
                                #{item.number} {item.title}
                            </a>
                        </TableCell>
                        <TableCell>
                            <div className="flex flex-wrap items-center gap-2">
                                {item.draft ? <Badge variant="outline">Draft</Badge> : null}
                                {item.state ? (
                                    <Badge variant="secondary">
                                        {item.state[0].toUpperCase() + item.state.slice(1)}
                                    </Badge>
                                ) : null}
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
