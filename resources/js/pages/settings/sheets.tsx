import { Badge } from '@/components/glint/badge';
import { Button } from '@/components/glint/button';
import { Input } from '@/components/glint/input';
import HeadingSmall from '@/components/heading-small';
import { ResponsiveTable } from '@/components/responsive-table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Google Sheets', href: '/settings/sheets' }];

interface Log {
    id: number;
    added: number;
    updated: number;
    deleted: number;
    pushed: number;
    error: string | null;
    created_at: string;
}

interface Props {
    ready: boolean;
    account: string | null;
    sheetId: string | null;
    title: string | null;
    redirectUri: string;
    problem: string | null;
    last: { at: string; error: string | null } | null;
    logs: Log[];
}

const when = (iso: string) =>
    new Date(iso).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' });
const sheetUrl = (id: string) => `https://docs.google.com/spreadsheets/d/${id}/edit`;

function Code({ children }: { children: React.ReactNode }) {
    return <code className="bg-muted rounded-md px-1.5 py-0.5 text-[13px] break-all">{children}</code>;
}

function Step({ n, done, title, children }: { n: number; done: boolean; title: string; children: React.ReactNode }) {
    return (
        <div className="flex gap-4 rounded-2xl border p-4 sm:p-5">
            <span
                className={
                    done
                        ? 'grid size-7 shrink-0 place-items-center rounded-full bg-emerald-500 text-sm font-semibold text-white'
                        : 'bg-primary text-primary-foreground grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold'
                }
            >
                {done ? '✓' : n}
            </span>
            <div className="min-w-0 flex-1 space-y-3">
                <p className="font-semibold">{title}</p>
                {children}
            </div>
        </div>
    );
}

export default function Sheets({ ready, account, sheetId, title, redirectUri, problem, last, logs }: Props) {
    const [syncing, setSyncing] = useState(false);
    const { errors } = usePage().props as { errors: Record<string, string> };
    const link = useForm({ link: sheetId ? sheetUrl(sheetId) : '' });

    const syncNow = () =>
        router.post(route('sheets.sync'), {}, { preserveScroll: true, onStart: () => setSyncing(true), onFinish: () => setSyncing(false) });

    return (
        <>
            <Head title="Google Sheets" />
            <div className="space-y-6">
                <HeadingSmall
                    title="Google Sheets"
                    description="Two-way sync with the master Google Sheet, one tab per group: edits there show up here, and records saved here are written there."
                />

                {!ready && (
                    <div className="space-y-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5 text-sm leading-relaxed">
                        <p className="font-semibold">One-time setup on the server (developer)</p>
                        <ol className="list-decimal space-y-2 pl-5">
                            <li>
                                In{' '}
                                <a className="text-primary underline" href="https://console.cloud.google.com/" target="_blank" rel="noreferrer">
                                    Google Cloud Console
                                </a>
                                , enable the <b>Google Sheets API</b>.
                            </li>
                            <li>
                                <b>OAuth consent screen</b>: External, then <b>Publish app</b> (in Testing, Google signs the app out every 7 days).
                            </li>
                            <li>
                                <b>Credentials → Create OAuth client ID → Web application</b>, with this redirect URI: <Code>{redirectUri}</Code>
                            </li>
                            <li>
                                Put the client ID and secret in <Code>.env</Code> as <Code>GOOGLE_CLIENT_ID</Code> and{' '}
                                <Code>GOOGLE_CLIENT_SECRET</Code>.
                            </li>
                        </ol>
                    </div>
                )}

                {ready && (
                    <>
                        <Step n={1} done={!!account} title="Connect Google">
                            {account ? (
                                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                                    <span>
                                        Connected as <b>{account}</b>
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.delete(route('sheets.disconnect'), { preserveScroll: true })}
                                    >
                                        Disconnect
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <p className="text-muted-foreground text-sm">
                                        Sign in with the Google account that can edit the master sheet. You only do this once.
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        On Google's page, <b className="text-foreground">tick the Google Sheets box</b> (or Select all) before
                                        pressing Continue, or the sync cannot open the sheet.
                                    </p>
                                    <a href={route('sheets.connect')}>
                                        <Button>Connect Google</Button>
                                    </a>
                                </>
                            )}
                            {errors.google && <p className="text-destructive text-sm">{errors.google}</p>}
                        </Step>

                        <Step n={2} done={!!sheetId} title="Paste the sheet link">
                            <form
                                className="flex flex-wrap gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    link.post(route('sheets.link'), { preserveScroll: true });
                                }}
                            >
                                <div className="min-w-64 flex-1">
                                    <Input
                                        aria-label="Google Sheet link"
                                        placeholder="https://docs.google.com/spreadsheets/d/…"
                                        value={link.data.link}
                                        onChange={(e) => link.setData('link', e.target.value)}
                                        error={errors.link}
                                        disabled={!account}
                                    />
                                </div>
                                <Button type="submit" loading={link.processing} disabled={!account}>
                                    Save
                                </Button>
                            </form>
                        </Step>

                        <Step n={3} done={!!last && !last.error} title="Syncing">
                            {account && sheetId ? (
                                <div className="space-y-3 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <a
                                            href={sheetUrl(sheetId)}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="hover:text-primary truncate font-medium"
                                        >
                                            {title ?? 'Google Sheet'} ↗
                                        </a>
                                        <Button size="sm" onClick={syncNow} loading={syncing}>
                                            Sync now
                                        </Button>
                                    </div>
                                    <p>
                                        Last sync:{' '}
                                        {last ? (
                                            <>
                                                <b>{when(last.at)}</b>{' '}
                                                <Badge size="sm" variant={last.error ? 'destructive' : 'success'} dot>
                                                    {last.error ? 'Failed' : 'OK'}
                                                </Badge>
                                            </>
                                        ) : (
                                            'not yet'
                                        )}
                                        <span className="text-muted-foreground"> · runs every 15 seconds</span>
                                    </p>
                                    {(problem || errors.sync || last?.error) && (
                                        <p className="border-destructive/30 bg-destructive/10 text-destructive rounded-xl border px-3 py-2">
                                            {problem ?? errors.sync ?? last?.error}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">Starts on its own once steps 1 and 2 are done.</p>
                            )}
                        </Step>
                    </>
                )}

                <div className="space-y-3">
                    <HeadingSmall title="Sync log" description="Syncs that changed something, newest first." />
                    <ResponsiveTable
                        selectable={false}
                        rows={logs}
                        empty="No syncs yet."
                        columns={[
                            { key: 'created_at', header: 'When', cell: (l) => when(l.created_at) },
                            { key: 'added', header: 'New from sheet', align: 'right' },
                            { key: 'updated', header: 'Changed', align: 'right' },
                            { key: 'deleted', header: 'Removed', align: 'right' },
                            { key: 'pushed', header: 'Written to sheet', align: 'right' },
                            {
                                key: 'error',
                                header: 'Status',
                                cell: (l) =>
                                    l.error ? (
                                        <span className="text-destructive" title={l.error}>
                                            Failed
                                        </span>
                                    ) : (
                                        'OK'
                                    ),
                            },
                        ]}
                    />
                </div>
            </div>
        </>
    );
}

// Persistent layout: the sidebar and header stay mounted while pages change.
Sheets.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={breadcrumbs}>
        <SettingsLayout>{page}</SettingsLayout>
    </AppLayout>
);
