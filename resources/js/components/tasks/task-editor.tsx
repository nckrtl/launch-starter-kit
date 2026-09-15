import { Form } from "@inertiajs/react";
import { store, update } from "@/actions/App/Http/Controllers/TaskController";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";

export type TaskBrief = {
    id: number;
    project_id: string;
    parent_id: number | null;
    kind: "group" | "executable";
    title: string;
    description: string;
    acceptance_criteria: string;
    status: string;
    content_version: string;
    dependency_ids: number[];
    children_count: number;
    timing: {
        started_at: string;
        finished_at: string | null;
        elapsed_seconds: number;
    } | null;
};

export type TaskEditorState = { task: TaskBrief } | { creationKey: string; parentId?: number };

export function TaskEditor({
    projectId,
    editor,
    onClose,
}: {
    projectId: string;
    editor: TaskEditorState | null;
    onClose: () => void;
}) {
    const task = editor && "task" in editor ? editor.task : null;
    const creation = editor && "creationKey" in editor ? editor : null;

    return (
        <Sheet
            open={editor !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <SheetContent className="overflow-y-auto sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>
                        {task ? "Edit task" : creation?.parentId ? "Add subtask" : "Create task"}
                    </SheetTitle>
                    <SheetDescription>
                        {task
                            ? "Edit the objective, context, and conditions for success."
                            : "Describe prepared work. Creating a task does not start execution."}
                    </SheetDescription>
                </SheetHeader>
                {editor && (
                    <Form
                        key={task ? task.id + ":" + task.content_version : creation?.creationKey}
                        {...(task ? update.form([projectId, task.id]) : store.form(projectId))}
                        onSuccess={onClose}
                        className="flex flex-col gap-5 px-4 pb-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                {task ? (
                                    <input
                                        type="hidden"
                                        name="expected_version"
                                        value={task.content_version}
                                    />
                                ) : (
                                    <>
                                        <input
                                            type="hidden"
                                            name="creation_key"
                                            value={creation?.creationKey}
                                        />
                                        {creation?.parentId && (
                                            <input
                                                type="hidden"
                                                name="parent_id"
                                                value={creation.parentId}
                                            />
                                        )}
                                    </>
                                )}
                                {errors.task && (
                                    <Alert variant="destructive">
                                        <AlertDescription>{errors.task}</AlertDescription>
                                    </Alert>
                                )}
                                <FieldGroup>
                                    <Field data-invalid={!!errors.title}>
                                        <FieldLabel htmlFor="task-title">Title</FieldLabel>
                                        <Input
                                            id="task-title"
                                            name="title"
                                            defaultValue={task?.title}
                                            required
                                            maxLength={255}
                                            aria-invalid={!!errors.title}
                                        />
                                        <FieldError>{errors.title}</FieldError>
                                    </Field>
                                    {!task && (
                                        <Field data-invalid={!!errors.kind}>
                                            <FieldLabel htmlFor="task-kind">Kind</FieldLabel>
                                            <NativeSelect
                                                id="task-kind"
                                                name="kind"
                                                defaultValue={
                                                    creation?.parentId ? "executable" : "group"
                                                }
                                                aria-invalid={!!errors.kind}
                                            >
                                                <NativeSelectOption value="group">
                                                    Group — contains subtasks
                                                </NativeSelectOption>
                                                <NativeSelectOption value="executable">
                                                    Executable — one objective
                                                </NativeSelectOption>
                                            </NativeSelect>
                                            <FieldError>{errors.kind}</FieldError>
                                        </Field>
                                    )}
                                    <Field data-invalid={!!errors.description}>
                                        <FieldLabel htmlFor="task-description">
                                            Objective and context
                                        </FieldLabel>
                                        <Textarea
                                            id="task-description"
                                            name="description"
                                            defaultValue={task?.description}
                                            rows={7}
                                            maxLength={50000}
                                            aria-invalid={!!errors.description}
                                        />
                                        <FieldError>{errors.description}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!errors.acceptance_criteria}>
                                        <FieldLabel htmlFor="task-criteria">
                                            Acceptance criteria
                                        </FieldLabel>
                                        <Textarea
                                            id="task-criteria"
                                            name="acceptance_criteria"
                                            defaultValue={task?.acceptance_criteria}
                                            rows={5}
                                            maxLength={50000}
                                            aria-invalid={!!errors.acceptance_criteria}
                                        />
                                        <FieldError>{errors.acceptance_criteria}</FieldError>
                                    </Field>
                                    <FieldError>
                                        {errors.parent_id ??
                                            errors.creation_key ??
                                            errors.expected_version}
                                    </FieldError>
                                </FieldGroup>
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={onClose}
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? "Saving…" : task ? "Save task" : "Create"}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </SheetContent>
        </Sheet>
    );
}
