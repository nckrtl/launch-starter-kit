import { defineLaunchConfig } from "@nckrtl/launch-ui/vite";
import { defineConfig } from "vite-plus";

const launchConfig = await defineLaunchConfig({
    // The SSR port is baked into bootstrap/ssr/app.js at build time and has no env
    // override, so it has to be pinned here. This isolated Tasks instance uses
    // 13729 to stay separate from the main Commander instance on 13719.
    inertia: { ssr: { port: 13729 } },
    agentation: false,
});

const appUrl = process.env.VITE_APP_URL ?? process.env.APP_URL;
const appHost = appUrl ? new URL(appUrl).hostname : undefined;

export default defineConfig(async (environment) => ({
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
}));
