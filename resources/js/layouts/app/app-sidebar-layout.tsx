import { Avatar } from '@/components/glint/avatar';
import { Button } from '@/components/glint/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/glint/dropdown-menu';
import { Input } from '@/components/glint/input';
import { StretchRail } from '@/components/glint/stretch-rail';
import { ToastProvider, useToast } from '@/components/glint/toast';
import { InstallPrompt } from '@/components/install-prompt';
import { RecordDialog } from '@/components/record-dialog';
import { useCan } from '@/hooks/use-can';
import { Download, Grid, History, LogOut, Menu, PlusCircle, Receipt, Search, Settings } from '@/icons';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Permission, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

// Each shows only with its box ticked on Settings → Users.
const NAV: { label: string; url: string; icon: React.ReactNode; can: Permission }[] = [
    { label: 'Dashboard', url: '/dashboard', icon: <Grid />, can: 'dashboard' },
    { label: 'Records', url: '/records', icon: <Receipt />, can: 'records' },
];
const TOOLS: { label: string; url: string; icon: React.ReactNode; can: Permission; download?: boolean }[] = [
    { label: 'Activity Log', url: '/history', icon: <History />, can: 'history' },
    { label: 'Export Excel', url: '/records/export', icon: <Download />, can: 'export', download: true },
];

const logout = () => router.post(route('logout'));

// Main pages are fetched ahead of time, so clicking them is instant. Within 30s the copy is used
// as is; after that it is still shown at once and refreshed in the background (gone after 10 min).
let pages: string[] = [];
const warm = () => pages.forEach((url) => router.prefetch(url, { method: 'get' }, { cacheFor: ['30s', '10m'] }));

function usePrefetchedPages(urls: string[]) {
    pages = urls; // only the pages this account may open
    useEffect(() => {
        router.flushAll(); // fresh session (just logged in): nothing cached from before may be shown
        warm();
        // The page you are on is not prefetched, so re-warm after each page change (cached ones are skipped).
        const offNavigate = router.on('navigate', warm);
        // Any save (a record added, edited or deleted) changes the numbers: drop cached pages and fetch fresh ones.
        const offFinish = router.on('finish', (event) => {
            const { method, url } = event.detail.visit;
            if (method === 'get') return;
            router.flushAll();
            if (!url.pathname.endsWith('/logout')) warm();
        });
        return () => {
            offNavigate();
            offFinish();
        };
    }, []);
}

function FlashToasts() {
    const { flash } = usePage<SharedData & { flash: { success: string | null; warning: string | null } }>().props;
    const { toast } = useToast();

    useEffect(() => {
        if (flash.success) toast({ title: 'Saved', description: flash.success, variant: 'success' });
        if (flash.warning) toast({ title: 'Check this', description: flash.warning, variant: 'warning', duration: 10_000 });
    }, [flash, toast]);

    return null;
}

type SheetsStatus = { configured: boolean; at?: string; error?: string | null };

/** Records changed by others (in the app or the Google Sheet) show up without reloading. */
function LiveUpdates() {
    // Every second a tiny request asks whether records changed anywhere (other staff, the Google Sheet sync);
    // only then is the page's data reloaded. Paused while the tab is hidden.
    useEffect(() => {
        let last: string | null = null;
        let busy = false;
        const tick = async () => {
            if (busy || document.visibilityState !== 'visible') return;
            busy = true;
            try {
                const { v } = await fetch(route('live'), { headers: { Accept: 'application/json' } }).then((r) => r.json());
                if (last !== null && v !== last) {
                    router.reload();
                }
                last = v;
            } catch {
                // Offline or signed out: try again next second.
            } finally {
                busy = false;
            }
        };
        tick();
        const timer = setInterval(tick, 1000);
        return () => clearInterval(timer);
    }, []);
    return null;
}

/** "Synced 12s ago" pill while the Google Sheet is connected. */
function SheetsPill() {
    const { sheets } = usePage<SharedData & { sheets: SheetsStatus }>().props;
    const [, tick] = useState(0);

    useEffect(() => {
        if (!sheets.configured) return;
        const timer = setInterval(() => tick((n) => n + 1), 5_000);
        return () => clearInterval(timer);
    }, [sheets.configured]);

    if (!sheets.configured) return null;
    const ago = sheets.at ? Math.max(0, Math.round((Date.now() - Date.parse(sheets.at)) / 1000)) : null;
    const fresh = ago !== null && ago < 120;
    const label = sheets.error
        ? 'Sync failed'
        : ago === null
          ? 'Not synced yet'
          : ago < 60
            ? `Synced ${ago}s ago`
            : `Synced ${Math.round(ago / 60)}m ago`;

    return (
        <Link
            href="/settings/sheets"
            title={sheets.error ?? 'Google Sheets sync'}
            className="hover:bg-muted hidden items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium whitespace-nowrap sm:flex"
        >
            <span className={cn('size-2 rounded-full', sheets.error ? 'bg-destructive' : fresh ? 'bg-emerald-500' : 'bg-amber-500')} />
            {label}
        </Link>
    );
}

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    const page = usePage<SharedData>();
    const { auth } = page.props;
    const nav = NAV.filter((n) => auth.can.includes(n.can));
    const tools = TOOLS.filter((t) => auth.can.includes(t.can));
    usePrefetchedPages(nav.map((n) => n.url));
    const canAdd = useCan('add');
    const path = page.url.split('?')[0];
    const active = nav.findIndex((n) => path.startsWith(n.url));
    const [adding, setAdding] = useState(false);
    const [q, setQ] = useState('');
    const title = breadcrumbs.at(-1)?.title ?? 'Sahara Accounting';

    return (
        <ToastProvider placement="top-right">
            <FlashToasts />
            <LiveUpdates />
            <InstallPrompt />
            <div className="min-h-screen overflow-x-clip bg-[#f4f4f2] lg:flex lg:gap-4 lg:p-4 dark:bg-[#111113]">
                {/* Sidebar: always dark, like the reference design. */}
                <aside className="dark bg-background text-foreground sticky top-4 hidden h-[calc(100vh-2rem)] w-64 shrink-0 flex-col overflow-y-auto rounded-3xl p-5 [scrollbar-width:none] lg:flex">
                    {/* White-lettered logo: the navy one would vanish on the dark sidebar. */}
                    <Link href="/dashboard" className="mb-7 block shrink-0 px-3 pt-2">
                        <img src="/images/sahara-logo-white.svg" alt="Sahara Construction Supply" className="w-full" />
                    </Link>

                    <p className="text-muted-foreground mb-2 px-1 text-xs font-medium tracking-wider uppercase">Menu</p>
                    <StretchRail
                        items={nav.map(({ label, icon }) => ({ label, icon }))}
                        active={active}
                        color="var(--primary)"
                        onSelect={(i) => router.visit(nav[i].url)}
                    />

                    <p className="text-muted-foreground mt-8 mb-2 px-1 text-xs font-medium tracking-wider uppercase">Tools</p>
                    <nav className="mb-6 grid gap-1 pl-4 text-sm font-medium">
                        {tools.map((t) =>
                            t.download ? (
                                <a
                                    key={t.url}
                                    href={t.url}
                                    className="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-3 rounded-lg px-3 py-2.5 [&>svg]:size-4"
                                >
                                    {t.icon} {t.label}
                                </a>
                            ) : (
                                <Link
                                    key={t.url}
                                    prefetch
                                    href={t.url}
                                    aria-current={path.startsWith(t.url) ? 'page' : undefined}
                                    className="text-muted-foreground hover:bg-muted hover:text-foreground aria-[current=page]:bg-muted aria-[current=page]:text-foreground aria-[current=page]:[&>svg]:text-primary flex items-center gap-3 rounded-lg px-3 py-2.5 aria-[current=page]:font-semibold [&>svg]:size-4"
                                >
                                    {t.icon} {t.label}
                                </Link>
                            ),
                        )}
                        <Link
                            href="/settings/profile"
                            aria-current={path.startsWith('/settings') ? 'page' : undefined}
                            className="text-muted-foreground hover:bg-muted hover:text-foreground aria-[current=page]:bg-muted aria-[current=page]:text-foreground aria-[current=page]:[&>svg]:text-primary flex items-center gap-3 rounded-lg px-3 py-2.5 aria-[current=page]:font-semibold"
                        >
                            <Settings className="size-4" /> Settings
                        </Link>
                        <button
                            onClick={logout}
                            className="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-3 rounded-lg px-3 py-2.5 text-left"
                        >
                            <LogOut className="size-4" /> Log out
                        </button>
                    </nav>

                    {canAdd && (
                        <div className="mt-auto shrink-0 rounded-2xl bg-gradient-to-br from-[#1a45dc] to-[#102c8c] p-4 text-white">
                            <PlusCircle className="mb-3 size-8 opacity-90" />
                            <p className="leading-snug font-semibold">New invoice or DR?</p>
                            <p className="mt-1 text-xs text-blue-100">Add it now so the totals stay right.</p>
                            <button
                                onClick={() => setAdding(true)}
                                className="mt-3 w-full rounded-xl bg-white/20 py-2 text-sm font-medium backdrop-blur transition hover:bg-white/30"
                            >
                                Add record
                            </button>
                        </div>
                    )}
                </aside>

                <main className="min-w-0 flex-1 space-y-4 p-3 lg:p-0">
                    <header className="bg-card flex items-center gap-3 rounded-3xl px-4 py-3 shadow-sm sm:px-6">
                        <DropdownMenu>
                            <DropdownMenuTrigger aria-label="Menu" className="hover:bg-muted rounded-lg p-2 lg:hidden">
                                <Menu className="size-5" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent>
                                {nav.map((n) => (
                                    <DropdownMenuItem key={n.url} icon={n.icon} onSelect={() => router.visit(n.url)}>
                                        {n.label}
                                    </DropdownMenuItem>
                                ))}
                                {canAdd && (
                                    <DropdownMenuItem icon={<PlusCircle />} onSelect={() => setAdding(true)}>
                                        Add record
                                    </DropdownMenuItem>
                                )}
                                {/* Phones and tablets have no sidebar, so the tools are here too. */}
                                {tools.length > 0 && <DropdownMenuSeparator />}
                                {tools.map((t) => (
                                    <DropdownMenuItem
                                        key={t.url}
                                        icon={t.icon}
                                        onSelect={() => (t.download ? (window.location.href = t.url) : router.visit(t.url))}
                                    >
                                        {t.label}
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem icon={<Settings />} onSelect={() => router.visit('/settings/profile')}>
                                    Settings
                                </DropdownMenuItem>
                                <DropdownMenuItem icon={<LogOut />} variant="destructive" onSelect={logout}>
                                    Log out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <h1 className="max-w-48 shrink-0 truncate text-lg font-black sm:text-xl xl:max-w-64">{title}</h1>

                        {/* The most used thing on every page: a long box in the middle of the header. */}
                        {auth.can.includes('records') && (
                            <form
                                className="mx-2 hidden min-w-0 flex-1 md:block lg:mx-4"
                                autoComplete="off"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(route('records.index'), q ? { q } : {});
                                }}
                            >
                                <Input
                                    placeholder="Search group, company, project, PO, DR, SI, OR or check no.…"
                                    aria-label="Search records"
                                    startIcon={<Search className="size-4" />}
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                />
                            </form>
                        )}

                        {auth.can.includes('settings') && <SheetsPill />}

                        {canAdd && (
                            <Button
                                size="sm"
                                className="ml-auto hidden rounded-full sm:inline-flex md:ml-0"
                                startIcon={<PlusCircle className="size-4" />}
                                onClick={() => setAdding(true)}
                            >
                                Add record
                            </Button>
                        )}

                        <DropdownMenu>
                            <DropdownMenuTrigger className="hover:bg-muted ml-auto flex items-center gap-3 rounded-full p-1 pr-2 sm:ml-0">
                                <Avatar name={auth.user.name} size="sm" status="online" />
                                <span className="hidden text-left leading-tight xl:block">
                                    <span className="block text-sm font-medium">Hey, {auth.user.name.split(' ')[0]}</span>
                                    <span className="text-muted-foreground block text-xs">{auth.user.email}</span>
                                </span>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>{auth.user.name}</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem icon={<Settings />} onSelect={() => router.visit('/settings/profile')}>
                                    Settings
                                </DropdownMenuItem>
                                <DropdownMenuItem icon={<LogOut />} variant="destructive" onSelect={logout}>
                                    Log out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </header>

                    <Head title={title} />
                    {children}
                </main>
            </div>
            <RecordDialog open={adding} onOpenChange={setAdding} />
        </ToastProvider>
    );
}
