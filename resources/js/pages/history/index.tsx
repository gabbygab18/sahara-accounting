import { Badge, type BadgeVariant } from '@/components/glint/badge';
import { Pagination } from '@/components/glint/pagination';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/lib/records';
import { router } from '@inertiajs/react';

interface Activity {
    id: number;
    record_id: number | null;
    action: 'added' | 'edited' | 'deleted';
    summary: string;
    /** field => [before, after], on edits. */
    changes: Record<string, [unknown, unknown]> | null;
    created_at: string;
    user: string | null;
}

const ACTION: Record<Activity['action'], { label: string; variant: BadgeVariant }> = {
    added: { label: 'Added', variant: 'success' },
    edited: { label: 'Edited', variant: 'warning' },
    deleted: { label: 'Deleted', variant: 'destructive' },
};

const when = (iso: string) =>
    new Date(iso).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

const show = (field: string, v: unknown) =>
    v === null || v === ''
        ? '—'
        : field === 'paid'
          ? v
              ? 'Paid'
              : 'Unpaid'
          : field === 'amount'
            ? `₱${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2 })}`
            : String(v);

export default function History({ activities, labels }: { activities: Paginated<Activity>; labels: Record<string, string> }) {
    return (
        <div className="bg-card space-y-4 rounded-3xl p-4 shadow-sm sm:p-5">
            <div>
                <h2 className="border-primary border-l-[3px] pl-2.5 font-bold">Activity Log</h2>
                <p className="text-muted-foreground mt-1 text-sm">
                    Every record added, edited and deleted, here or in the Google Sheet, newest first.
                </p>
            </div>

            <ul className="space-y-3">
                {activities.data.length === 0 && <li className="text-muted-foreground py-10 text-center text-sm">Nothing yet.</li>}
                {activities.data.map((a) => (
                    <li key={a.id} className="rounded-2xl border p-3 sm:p-4">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Badge size="sm" variant={ACTION[a.action].variant}>
                                {ACTION[a.action].label}
                            </Badge>
                            <span className="font-medium">{a.summary}</span>
                            <span className="text-muted-foreground ml-auto text-xs">
                                {a.user ?? 'Google Sheet'} · {when(a.created_at)}
                            </span>
                        </div>
                        {a.changes && (
                            <ul className="mt-2 grid gap-1 text-sm">
                                {Object.entries(a.changes).map(([field, [before, after]]) => (
                                    <li key={field} className="flex flex-wrap gap-x-2">
                                        <span className="text-muted-foreground w-36 shrink-0 text-xs font-semibold tracking-wide uppercase">
                                            {labels[field] ?? field}
                                        </span>
                                        <span className="text-muted-foreground line-through">{show(field, before)}</span>
                                        <span>→ {show(field, after)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ul>

            {activities.last_page > 1 && (
                <Pagination
                    className="justify-end"
                    total={activities.last_page}
                    page={activities.current_page}
                    onPageChange={(page) => router.get('/history', { page }, { preserveScroll: true })}
                />
            )}
        </div>
    );
}

// Persistent layout: the sidebar and header stay mounted while pages change.
History.layout = (page: React.ReactNode) => <AppLayout breadcrumbs={[{ title: 'Activity Log', href: '/history' }]}>{page}</AppLayout>;
