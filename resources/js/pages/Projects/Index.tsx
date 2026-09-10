import { Form, Head, Link } from "@inertiajs/react";
import { useState } from "react";
import { index, show, store, update } from "@/actions/App/Http/Controllers/ProjectController";
import AppSidebarLayout from "@/components/app-sidebar-layout";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from "@/components/ui/sheet";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Project = { id: string; name: string; status: string; locations: unknown[] };

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    const [editor, setEditor] = useState<Project | "create" | null>(null);
    const project = editor !== "create" ? editor : null;

    return (
        <AppSidebarLayout breadcrumbs={[{ title: "Projects", href: index.url() }]}>
            <Head title="Projects" />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-3xl font-semibold tracking-tight">Projects</h1>
                    <Button onClick={() => setEditor("create")}>Create</Button>
                </div>
                <div className="rounded-lg border">
                    <Table aria-label="Projects">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Project</TableHead>
                                <TableHead>ID</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Locations</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {projects.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium">
                                        <Link className="hover:underline" href={show(item.id)}>
                                            {item.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {item.id}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary">{item.status}</Badge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {item.locations.length}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={"Edit " + item.name}
                                            onClick={() => setEditor(item)}
                                        >
                                            Edit
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {projects.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No projects yet.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
                <Sheet
                    open={editor !== null}
                    onOpenChange={(open) => {
                        if (!open) setEditor(null);
                    }}
                >
                    <SheetContent>
                        <SheetHeader>
                            <SheetTitle>{project ? "Edit project" : "Create project"}</SheetTitle>
                            <SheetDescription>
                                {project
                                    ? "Update the project name and status."
                                    : "Add a project to your workspace."}
                            </SheetDescription>
                        </SheetHeader>
                        {editor !== null && (
                            <Form
                                key={project?.id ?? "create"}
                                {...(project ? update.form(project.id) : store.form())}
                                onSuccess={() => setEditor(null)}
                                className="flex flex-col gap-4 px-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        {!project && (
                                            <Field
                                                label="Project ID"
                                                name="id"
                                                placeholder="new-project"
                                                error={errors.id}
                                            />
                                        )}
                                        <Field
                                            label="Name"
                                            name="name"
                                            defaultValue={project?.name}
                                            error={errors.name}
                                        />
                                        <div className="flex flex-col gap-2">
                                            <Label htmlFor="project-status">Status</Label>
                                            <select
                                                id="project-status"
                                                name="status"
                                                defaultValue={project?.status ?? "active"}
                                                aria-invalid={!!errors.status}
                                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                            >
                                                <option value="active">Active</option>
                                                <option value="paused">Paused</option>
                                                <option value="archived">Archived</option>
                                            </select>
                                            {errors.status && (
                                                <p
                                                    role="alert"
                                                    className="text-xs text-destructive"
                                                >
                                                    {errors.status}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setEditor(null)}
                                            >
                                                Cancel
                                            </Button>
                                            <Button type="submit" disabled={processing}>
                                                {project ? "Save" : "Create"}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </SheetContent>
                </Sheet>
            </main>
        </AppSidebarLayout>
    );
}

function Field({
    label,
    error,
    ...props
}: React.ComponentProps<typeof Input> & { label: string; error?: string }) {
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={props.name}>{label}</Label>
            <Input id={props.name} aria-invalid={!!error} {...props} />
            {error && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
