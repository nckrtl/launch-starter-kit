import "@xterm/xterm/css/xterm.css";
import { useEffect, useRef, useState } from "react";
import { observationGrant } from "@/actions/App/Http/Controllers/TaskController";
import { Badge } from "@/components/ui/badge";

type Session = {
    role: string;
    label: string;
    status: string;
};

type ObservationGrant = { observer_url: string };

type TerminalMessage =
    | {
          type: "terminal.frame";
          encoding: "ansi";
          full: boolean;
          seq: number;
          bytes: string;
      }
    | { type: "terminal.closed" };

function bytesFromBase64(value: string) {
    const binary = window.atob(value);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

function displayStatus(status: string) {
    const label = status.replaceAll("_", " ");
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
}

function LiveTaskTerminal({
    projectId,
    taskId,
    session,
    setConnection,
}: {
    projectId: string;
    taskId: number;
    session: Session;
    setConnection: (value: string) => void;
}) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let disposed = false;
        let activeRequest: AbortController | null = null;
        let resizeObserver: ResizeObserver | null = null;
        let resizeTimer: number | null = null;
        let reconnectTimer: number | null = null;
        let socket: WebSocket | null = null;
        let connectionGeneration = 0;
        let terminalClosed = false;
        let terminalInstance: import("@xterm/xterm").Terminal | null = null;

        async function initialize() {
            const [{ Terminal }, { FitAddon }] = await Promise.all([
                import("@xterm/xterm"),
                import("@xterm/addon-fit"),
            ]);

            if (disposed || container.current === null) return;

            const fit = new FitAddon();
            const terminal = new Terminal({
                allowProposedApi: false,
                convertEol: false,
                cursorBlink: false,
                cursorStyle: "block",
                disableStdin: true,
                fontFamily: '"JetBrains Mono", "SFMono-Regular", Consolas, monospace',
                fontSize: 12,
                lineHeight: 1.15,
                scrollback: 0,
                theme: {
                    background: "#09090b",
                    foreground: "#fafafa",
                    cursor: "#a1a1aa",
                    selectionBackground: "#3f3f46",
                },
            });
            terminalInstance = terminal;
            terminal.loadAddon(fit);
            terminal.open(container.current);
            fit.fit();

            function renderMessage(message: TerminalMessage) {
                if (message.type === "terminal.closed") {
                    terminalClosed = true;
                    setConnection("Closed");
                    return;
                }

                if (message.encoding !== "ansi") return;

                if (message.full) terminal.reset();
                terminal.write(bytesFromBase64(message.bytes));
                setConnection("Live");
            }

            function disposeSocket() {
                if (socket === null) return;

                socket.onopen = null;
                socket.onmessage = null;
                socket.onerror = null;
                socket.onclose = null;
                socket.close();
                socket = null;
            }

            function scheduleReconnect() {
                if (disposed || terminalClosed || reconnectTimer !== null) return;

                reconnectTimer = window.setTimeout(() => {
                    reconnectTimer = null;
                    void connectOrbit();
                }, 2_000);
            }

            async function connectOrbit() {
                if (reconnectTimer !== null) {
                    window.clearTimeout(reconnectTimer);
                    reconnectTimer = null;
                }
                const generation = ++connectionGeneration;
                activeRequest?.abort();
                disposeSocket();
                terminalClosed = false;
                setConnection("Connecting");
                const request = new AbortController();
                activeRequest = request;

                try {
                    const response = await fetch(
                        observationGrant.url([projectId, taskId, session.role]),
                        {
                            method: "POST",
                            signal: request.signal,
                            headers: {
                                Accept: "application/json",
                                "Content-Type": "application/json",
                                "X-CSRF-TOKEN": csrfToken(),
                            },
                            body: JSON.stringify({ cols: terminal.cols, rows: terminal.rows }),
                        },
                    );

                    if (!response.ok) throw new Error("Session unavailable");

                    const grant = (await response.json()) as ObservationGrant;
                    if (
                        disposed ||
                        generation !== connectionGeneration ||
                        typeof grant.observer_url !== "string" ||
                        !grant.observer_url.startsWith("wss://")
                    ) {
                        return;
                    }

                    const nextSocket = new WebSocket(grant.observer_url);
                    socket = nextSocket;
                    nextSocket.onopen = () => {
                        if (!disposed && socket === nextSocket) setConnection("Live");
                    };
                    nextSocket.onmessage = (event) => {
                        if (disposed || socket !== nextSocket || typeof event.data !== "string") {
                            return;
                        }

                        try {
                            renderMessage(JSON.parse(event.data) as TerminalMessage);
                        } catch {
                            setConnection("Unavailable");
                            nextSocket.close();
                        }
                    };
                    nextSocket.onerror = () => {
                        if (!disposed && socket === nextSocket) setConnection("Unavailable");
                    };
                    nextSocket.onclose = () => {
                        if (socket !== nextSocket) return;

                        socket = null;
                        if (!disposed && !terminalClosed) {
                            setConnection("Unavailable");
                            scheduleReconnect();
                        }
                    };
                } catch (error) {
                    if (
                        !disposed &&
                        generation === connectionGeneration &&
                        !(error instanceof DOMException && error.name === "AbortError")
                    ) {
                        setConnection("Unavailable");
                        scheduleReconnect();
                    }
                }
            }

            void connectOrbit();
            let dimensions = `${terminal.cols}x${terminal.rows}`;
            resizeObserver = new ResizeObserver(() => {
                fit.fit();
                const nextDimensions = `${terminal.cols}x${terminal.rows}`;

                if (nextDimensions === dimensions) return;

                dimensions = nextDimensions;
                if (resizeTimer !== null) window.clearTimeout(resizeTimer);
                resizeTimer = window.setTimeout(() => {
                    void connectOrbit();
                }, 250);
            });
            resizeObserver.observe(container.current);
        }

        void initialize();

        return () => {
            disposed = true;
            activeRequest?.abort();
            connectionGeneration += 1;
            if (socket !== null) {
                socket.onopen = null;
                socket.onmessage = null;
                socket.onerror = null;
                socket.onclose = null;
                socket.close();
                socket = null;
            }
            resizeObserver?.disconnect();
            if (resizeTimer !== null) window.clearTimeout(resizeTimer);
            if (reconnectTimer !== null) window.clearTimeout(reconnectTimer);
            terminalInstance?.dispose();
        };
    }, [projectId, session.role, setConnection, taskId]);

    return (
        <div
            ref={container}
            className="h-[28rem] min-w-0 p-2"
            aria-label={`${session.label} terminal, read only`}
        />
    );
}

export function TaskTerminal({
    projectId,
    taskId,
    session,
}: {
    projectId: string;
    taskId: number;
    session: Session;
}) {
    const [connection, setConnection] = useState("Connecting");

    return (
        <div
            className="overflow-hidden rounded-lg border bg-zinc-950"
            data-task-terminal={session.role}
        >
            <div className="flex items-center justify-between border-b border-white/10 px-3 py-2 text-xs text-zinc-300">
                <span>{session.label} pane</span>
                <div className="flex items-center gap-2">
                    <span>{displayStatus(session.status)}</span>
                    <Badge variant="outline" className="border-white/15 bg-white/5 text-zinc-200">
                        {connection}
                    </Badge>
                </div>
            </div>
            <LiveTaskTerminal
                projectId={projectId}
                taskId={taskId}
                session={session}
                setConnection={setConnection}
            />
        </div>
    );
}

export type { Session as TaskTerminalSession };
