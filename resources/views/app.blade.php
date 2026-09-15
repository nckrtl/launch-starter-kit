<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" type="image/svg+xml" href="/commander-icon.svg">

        {{-- Points AI agents at the setup guide instead of making them parse this page. --}}
        <link rel="alternate" type="text/markdown" href="/create.md" title="Create a new project with Launch">

        <link rel="preload" href="{{ Vite::asset('resources/fonts/instrument-sans-variable-latin.woff2') }}" as="font" type="font/woff2" crossorigin>

        @php($cspNonce = Vite::cspNonce())
        <script @if($cspNonce) nonce="{{ $cspNonce }}" @endif>
            (function() {
                var appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>
        <style @if($cspNonce) nonce="{{ $cspNonce }}" @endif>
            html, body {
                background-color: var(--background);
                color: var(--foreground);
            }
        </style>
        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
