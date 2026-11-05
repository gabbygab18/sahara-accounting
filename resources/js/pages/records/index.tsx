import { DatePicker } from '@/components/date-picker';
import { Badge } from '@/components/glint/badge';
import { Button } from '@/components/glint/button';
import { Input } from '@/components/glint/input';
import { Pagination } from '@/components/glint/pagination';
import { Select } from '@/components/glint/select';
import { StretchTabs } from '@/components/glint/stretch-tabs';
import { PayDialog } from '@/components/pay-dialog';
import { RecordDialog } from '@/components/record-dialog';
import { ResponsiveTable } from '@/components/responsive-table';
import { useCan } from '@/hooks/use-can';
import { Download, Edit, PlusCircle, Search } from '@/icons';
import AppLayout from '@/layouts/app-layout';
import { fmt, peso, prettyDate, terms, type AccountingRecord, type Paginated, type RecordOptions } from '@/lib/records';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Filters = { q?: string; company?: string; project?: string; delivery?: string; payment?: string; from?: string; to?: string };

const PAYMENT: Record<string, string> = { All: '', Unpaid: 'unpaid', 'For checking': 'checking', Paid: 'paid' };
const ALL = '__all';

const DELIVERY_VARIANT: Record<string, 'success' | 'warning' | 'secondary'> = { Delivered: 'success', Partial: 'warning' };

export default function Records({
    records,
    totals,
    filters,
    options,
}: {
    records: Paginated<AccountingRecord>;
    totals: { paid: number; checking: number; unpaid: number } | null;
    filters: Filters;
    options: RecordOptions;
}) {
    const [q, setQ] = useState(filters.q ?? '');
    useEffect(() => setQ(filters.q ?? ''), [filters.q]);
    const [editing, setEditing] = useState<AccountingRecord | null>(null);
    const [adding, setAdding] = useState(false);
    const [paying, setPaying] = useState<AccountingRecord | null>(null);
    const canEdit = useCan('edit');
    const canAdd = useCan('add');
    const canExport = useCan('export');
    const canConfirm = useCan('confirm');
    const projects = options.companies.find((c) => c.name === filters.company)?.projects ?? [];
    const query = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();

    const go = (params: Filters & { page?: number }) => {
        const next = Object.fromEntries(Object.entries({ ...filters, page: undefined, ...params }).filter(([, v]) => v));
        router.get(route('records.index'), next, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <div className="bg-card space-y-4 rounded-3xl p-4 shadow-sm sm:p-5">
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        className="min-w-56 flex-1"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q });
                        }}
                    >
                        <Input
                            placeholder="Group, company, project, TIN, PO, DR, SI, OR or check no."
                            aria-label="Search records"
                            startIcon={<Search className="size-4" />}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    <div className="-mx-1 max-w-full overflow-x-auto px-1 py-1">
                        <StretchTabs
                            label="Payment status"
                            options={Object.keys(PAYMENT)}
                            value={Object.keys(PAYMENT).find((k) => PAYMENT[k] === (filters.payment ?? ''))}
                            onChange={(v) => go({ payment: PAYMENT[v] })}
                        />
                    </div>
                    {canAdd && (
                        <Button startIcon={<PlusCircle className="size-4" />} onClick={() => setAdding(true)}>
                            Add record
                        </Button>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Select
                        aria-label="Group"
                        options={[{ value: ALL, label: 'All groups' }, ...options.companies.map((c) => ({ value: c.name, label: c.name }))]}
                        value={filters.company ?? ALL}
                        onValueChange={(v) => go({ company: v === ALL ? '' : v, project: '' })}
                    />
                    <Select
                        aria-label="Project"
                        disabled={!projects.length}
                        options={[
                            { value: ALL, label: filters.company ? 'All projects' : 'Pick a group first' },
                            ...projects.map((p) => ({ value: p, label: p })),
                        ]}
                        value={filters.project ?? ALL}
                        onValueChange={(v) => go({ project: v === ALL ? '' : v })}
                    />
                    <Select
                        aria-label="Delivery status"
                        options={[{ value: ALL, label: 'Any delivery status' }, ...options.delivery.map((d) => ({ value: d, label: d }))]}
                        value={filters.delivery ?? ALL}
                        onValueChange={(v) => go({ delivery: v === ALL ? '' : v })}
                    />
                    <DatePicker value={filters.from ?? ''} onChange={(from) => go({ from })} placeholder="From date" clearable />
                    <DatePicker value={filters.to ?? ''} onChange={(to) => go({ to })} placeholder="To date" clearable />
                </div>

                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border px-4 py-3 text-sm">
                    {totals ? (
                        <>
                            <span>
                                <span className="text-muted-foreground">Unpaid </span>
                                <b className="text-rose-600 tabular-nums">{peso(totals.unpaid)}</b>
                            </span>
                            <span>
                                <span className="text-muted-foreground">For checking </span>
                                <b className="text-sky-600 tabular-nums">{peso(totals.checking)}</b>
                            </span>
                            <span>
                                <span className="text-muted-foreground">Paid </span>
                                <b className="text-emerald-600 tabular-nums">{peso(totals.paid)}</b>
                            </span>
                            <span className="text-muted-foreground text-xs">for the {fmt(records.total)} records these filters match</span>
                        </>
                    ) : (
                        <span className="text-muted-foreground">
                            {fmt(records.total)} {records.total === 1 ? 'record' : 'records'} match these filters
                        </span>
                    )}
                    {canExport && (
                        <span className="ml-auto flex gap-2">
                            <a href={`${route('records.export')}?${query}`}>
                                <Button size="sm" variant="outline" startIcon={<Download className="size-4" />}>
                                    Excel
                                </Button>
                            </a>
                            <a href={`${route('records.export')}?${query}${query ? '&' : ''}format=csv`}>
                                <Button size="sm" variant="outline">
                                    CSV
                                </Button>
                            </a>
                        </span>
                    )}
                </div>

                <ResponsiveTable
                    selectable={false}
                    rows={records.data}
                    empty="No records match these filters."
                    columns={[
                        {
                            key: 'company',
                            header: 'Group / Company',
                            cell: (r) => (
                                <button
                                    type="button"
                                    disabled={!canEdit}
                                    onClick={() => setEditing(r)}
                                    className="group block max-w-64 text-left disabled:cursor-default"
                                >
                                    <span className="group-enabled:group-hover:text-primary block truncate font-medium">{r.company || '—'}</span>
                                    <span className="text-muted-foreground block truncate text-xs">
                                        {[r.sold_to, r.project].filter(Boolean).join(' · ') || 'No company'}
                                    </span>
                                </button>
                            ),
                        },
                        {
                            key: 'si_number',
                            header: 'SI # / Date',
                            cell: (r) => (
                                <span className="block">
                                    <span className="block font-medium">{r.si_number ?? '—'}</span>
                                    <span className="text-muted-foreground text-xs">{prettyDate(r.si_date)}</span>
                                </span>
                            ),
                        },
                        {
                            key: 'amount',
                            header: 'Amount',
                            align: 'right',
                            sortable: true,
                            cell: (r) => <span className="font-semibold tabular-nums">{peso(r.amount)}</span>,
                        },
                        {
                            key: 'paid',
                            header: 'Payment',
                            cell: (r) =>
                                r.paid && r.paid_confirmed ? (
                                    <Badge size="sm" variant="success">
                                        Paid
                                    </Badge>
                                ) : r.paid ? (
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <Badge size="sm" variant="outline" className="border-sky-500/40 text-sky-700 dark:text-sky-400">
                                            For checking
                                        </Badge>
                                        {canConfirm && (
                                            <button
                                                type="button"
                                                className="text-primary text-xs font-medium hover:underline"
                                                onClick={() => router.patch(route('records.confirm', r.id), {}, { preserveScroll: true })}
                                            >
                                                Confirm
                                            </button>
                                        )}
                                    </span>
                                ) : (
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <Badge size="sm" variant="destructive">
                                            {r.overdue ? 'Overdue' : 'Unpaid'}
                                        </Badge>
                                        {canEdit && (
                                            <button
                                                type="button"
                                                className="text-primary text-xs font-medium whitespace-nowrap hover:underline"
                                                onClick={() => setPaying(r)}
                                            >
                                                Mark paid
                                            </button>
                                        )}
                                    </span>
                                ),
                        },
                        {
                            key: 'due',
                            header: 'Terms / Due',
                            cell: (r) => (
                                <span className="block">
                                    <span className="block">{terms(r.terms_days)}</span>
                                    <span className={cn('text-xs', r.overdue ? 'text-destructive font-medium' : 'text-muted-foreground')}>
                                        {r.due ? `due ${prettyDate(r.due)}` : ''}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'delivery_status',
                            header: 'Delivery',
                            cell: (r) =>
                                r.delivery_status ? (
                                    <Badge size="sm" variant={DELIVERY_VARIANT[r.delivery_status] ?? 'secondary'}>
                                        {r.delivery_status}
                                    </Badge>
                                ) : (
                                    '—'
                                ),
                        },
                        {
                            key: 'po_number',
                            header: 'PO # / DR #',
                            cell: (r) => (
                                <span className="block">
                                    <span className="block">{r.po_number ?? '—'}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {r.dr_number || r.dr_date ? `DR ${r.dr_number ?? '—'} · ${prettyDate(r.dr_date)}` : 'No DR'}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'or_number',
                            header: 'OR # / Check #',
                            cell: (r) =>
                                r.or_number || r.check_number ? (
                                    <span className="block">
                                        <span className="block">{r.or_number ?? '—'}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {[
                                                r.or_date && prettyDate(r.or_date),
                                                r.check_number && `check ${r.check_number}`,
                                                r.check_date && `deposit ${prettyDate(r.check_date)}`,
                                                (r.check_number || r.check_date) && (r.check_cleared ? 'cleared' : 'not cleared'),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                ) : (
                                    '—'
                                ),
                        },
                        ...(canEdit
                            ? [
                                  {
                                      key: 'id' as const,
                                      header: '',
                                      align: 'right' as const,
                                      cell: (r: AccountingRecord) => (
                                          <button
                                              type="button"
                                              onClick={() => setEditing(r)}
                                              className="text-muted-foreground hover:bg-muted hover:text-foreground inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium"
                                          >
                                              <Edit className="size-3.5" /> Edit
                                          </button>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        {records.total ? `${records.from}–${records.to} of ${fmt(records.total)} records` : 'No records'}
                    </p>
                    {records.last_page > 1 && (
                        <Pagination total={records.last_page} page={records.current_page} onPageChange={(page) => go({ page })} />
                    )}
                </div>
            </div>
            <PayDialog record={paying} onClose={() => setPaying(null)} />
            <RecordDialog open={adding || !!editing} onOpenChange={(o) => !o && (setAdding(false), setEditing(null))} editing={editing} />
        </>
    );
}

// Persistent layout: the sidebar and header stay mounted while pages change.
Records.layout = (page: React.ReactNode) => <AppLayout breadcrumbs={[{ title: 'Records', href: '/records' }]}>{page}</AppLayout>;
