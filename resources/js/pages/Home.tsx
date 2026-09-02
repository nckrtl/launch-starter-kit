import { Head } from "@inertiajs/react";

export default function Home() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-background text-foreground">
            <Head title="Home" />
            <div className="text-center">
                <h1 className="text-4xl font-bold tracking-tight">Launch Starter Kit</h1>
                <p className="mt-3 text-muted-foreground">
                    Laravel 13 + React 19 + Inertia v3 + Tailwind CSS v4
                </p>
            </div>
        </main>
    );
}
