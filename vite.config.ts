import { defineLaunchConfig } from "@nckrtl/launch-ui/vite";
import { defineConfig, loadEnv } from "vite-plus";

const appUrl = process.env.VITE_APP_URL ?? process.env.APP_URL;
const appHost = appUrl ? new URL(appUrl).hostname : undefined;

export default defineConfig(async (environment) => {
    const env = loadEnv(environment.mode, process.cwd(), "INERTIA_");
    const launchConfig = await defineLaunchConfig({
        // Each instance needs its own SSR port, shared with Laravel's config.
        inertia: {
            ssr: {
                port: Number(process.env.INERTIA_SSR_PORT ?? env.INERTIA_SSR_PORT ?? 13719),
            },
        },
        agentation: false,
    });

    return {
        ...(await launchConfig(environment)),
        fmt: { ignorePatterns: [".agents/**"] },
        server: appHost
            ? {
                  host: "0.0.0.0",
                  ws: { host: appHost },
              }
            : undefined,
        // Pre-commit tasks, run against staged files only by `vp staged` from
        // .vite-hooks/pre-commit. Anything they fix is re-staged automatically.
        staged: {
            "*": "vp check --fix",
            "*.php": "vendor/bin/pint",
        },
    };
});
