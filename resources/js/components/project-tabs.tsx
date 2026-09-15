import { Link } from "@inertiajs/react";
import type { ReactNode } from "react";
import { show as projectShow } from "@/actions/App/Http/Controllers/ProjectController";
import { index as tasksIndex } from "@/actions/App/Http/Controllers/TaskController";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";

export type ProjectSection = "overview" | "instances" | "github" | "tasks";

export function ProjectTabs({
    projectId,
    active,
    children,
}: {
    projectId: string;
    active: ProjectSection;
    children: ReactNode;
}) {
    return (
        <Tabs value={active} className="gap-6">
            <TabsList variant="line" aria-label="Project sections">
                <TabsTrigger
                    value="overview"
                    nativeButton={false}
                    render={<Link href={projectShow(projectId)} prefetch />}
                >
                    Overview
                </TabsTrigger>
                <TabsTrigger
                    value="instances"
                    nativeButton={false}
                    render={
                        <Link
                            href={projectShow(projectId, { query: { section: "instances" } })}
                            prefetch
                        />
                    }
                >
                    Instances
                </TabsTrigger>
                <TabsTrigger
                    value="github"
                    nativeButton={false}
                    render={
                        <Link
                            href={projectShow(projectId, { query: { section: "github" } })}
                            prefetch
                        />
                    }
                >
                    GitHub
                </TabsTrigger>
                <TabsTrigger
                    value="tasks"
                    nativeButton={false}
                    render={<Link href={tasksIndex(projectId)} prefetch />}
                >
                    Tasks
                </TabsTrigger>
            </TabsList>
            {children}
        </Tabs>
    );
}
