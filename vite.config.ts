import { defineLaunchConfig } from "@nckrtl/launch-ui/vite";
import { defineConfig } from "vite-plus";

const launchConfig = await defineLaunchConfig({
    // The SSR port is baked into bootstrap/ssr/app.js at build time and has no env
    // override, so it has to be pinned here. 13714-13718 are taken on the main1
    // production node (13717 is toolbar), hence 13719.
    inertia: { ssr: { port: 13719 } },
});

export default defineConfig(async (environment) => ({
    ...(await launchConfig(environment)),
    fmt: { ignorePatterns: [".agents/**"] },
    // Pre-commit tasks, run against staged files only by `vp staged` from
    // .vite-hooks/pre-commit. Anything they fix is re-staged automatically.
    staged: {
        "*": "vp check --fix",
        "*.php": "vendor/bin/pint",
    },
}));
