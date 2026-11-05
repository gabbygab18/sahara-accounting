import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/glint/button';
import { Input } from '@/components/glint/input';
import { Select } from '@/components/glint/select';
import { Hyperlink } from '@/components/ui/hyperlink';
import { AlarmClock, Eye, Receipt, TrendingUp, Wallet } from '@/icons';
import AppLayout from '@/layouts/app-layout';
import { fmt, peso, prettyDate } from '@/lib/records';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Summary', href: '/dashboard' }];

interface Sums {
    count: number;
    paid: number;
    checking: number;
    unpaid: number;
    overdue: number;
}

interface Brief {
    id: number;
    company: string;
    sold_to: string | null;
    project: string | null;
    si_number: string | null;
    dr_number: string | null;
    po_number: string | null;
    amount: number;
}

interface Gaps {
    from: number;
    missing: number;
    ranges: { from: number; to: number }[];
}

type Filters = { company?: string; from?: string; to?: string };

interface Month {
    month: string;
    billed: number;
    collected: number;
}

interface Props {
    filters: Filters;
    groups: string[];
    totals: Sums;
    month: { billed: number; collected: number };
    checkingCount: number;
    aging: { key: string; label: string; count: number; amount: number; top: (Brief & { late: number | null })[] }[];
    debtors: { name: string; group: string; unpaid: number; overdue: number; count: number; days: number | null }[];
    trend: Month[];
    notInvoiced: { count: number; rows: (Brief & { dr_date: string | null; days: number | null })[] };
    noDue: { count: number; rows: (Brief & { missing: string })[] };
    collect: (Brief & { due: string; late: number })[];
    uncleared: { count: number; amount: number; rows: (Brief & { check_number: string | null; check_date: string | null })[] };
    skipped: { si: Gaps | null; dr: Gaps | null };
    companies: (Sums & { company: string })[];
}

function Card({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('bg-card rounded-3xl p-5 shadow-sm', className)} {...props} />;
}

function CardTitle({ children, hint }: { children: React.ReactNode; hint?: React.ReactNode }) {
    return (
        <div className="mb-4">
            <h2 className="border-primary border-l-[3px] pl-2.5 font-bold">{children}</h2>
            {hint && <p className="text-muted-foreground mt-1 pl-3 text-xs">{hint}</p>}
        </div>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <li className="text-muted-foreground py-6 text-center text-sm">{children}</li>;
}

const records = (params: Record<string, string>) => `/records?${new URLSearchParams(params)}`;
const doc = (r: Brief) => (r.si_number ? `SI ${r.si_number}` : r.dr_number ? `DR ${r.dr_number}` : r.po_number ? `PO ${r.po_number}` : 'No SI #');
const find = (r: Brief) => records({ q: r.si_number ?? r.dr_number ?? r.po_number ?? r.company });
const plural = (n: number, word: string) => `${fmt(n)} ${word}${n === 1 ? '' : 's'}`;

/** One invoice in a list: what it is and who, a second line, and the amount with a note. */
function Row({ r, sub, note, noteCls }: { r: Brief; sub: string; note: string; noteCls: string }) {
    return (
        <li>
            <Link prefetch href={find(r)} className="hover:bg-muted/60 -mx-2 flex items-center gap-3 rounded-lg px-2 py-2">
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {doc(r)} · {r.company || '(no group)'}
                    </span>
                    <span className="text-muted-foreground block truncate text-[11px]">{sub}</span>
                </span>
                <span className="shrink-0 text-right">
                    <span className="block text-sm font-semibold tabular-nums">{peso(r.amount)}</span>
                    <span className={cn('block text-[11px] font-medium', noteCls)}>{note}</span>
                </span>
            </Link>
        </li>
    );
}

/** Skipped numbers of one kind of receipt, from the booklet's first number (set here). */
function Skipped({ doc, label, gaps }: { doc: 'si' | 'dr'; label: string; gaps: Gaps | null }) {
    const form = useForm({ [doc]: gaps ? String(gaps.from) : '' });
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(route('dashboard.booklet'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <div>
            <form onSubmit={save} className="mb-2.5 flex items-center gap-2">
                <span className="w-12 shrink-0 text-sm font-semibold">{label}</span>
                <Input
                    aria-label={`First ${label} of the booklet`}
                    inputMode="numeric"
                    placeholder="Booklet starts at #"
                    value={form.data[doc]}
                    onChange={(e) => form.setData(doc, e.target.value.replace(/\D/g, ''))}
                    className="h-8"
                />
                <Button type="submit" size="sm" variant="outline" disabled={form.processing || !form.isDirty}>
                    Save
                </Button>
            </form>
            {form.errors[doc] && <p className="text-destructive mb-2 text-xs">{form.errors[doc]}</p>}
            {!gaps ? (
                <p className="text-muted-foreground text-xs">Enter the first {label} of the booklet to watch for skipped numbers.</p>
            ) : gaps.ranges.length === 0 ? (
                <p className="text-xs text-emerald-600">
                    No skipped {label} from {gaps.from}.
                </p>
            ) : (
                <>
                    <p className="mb-2 text-xs">
                        <b className="text-destructive">{plural(gaps.missing, 'number')}</b> missing from {gaps.from}
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {gaps.ranges.map((g) => (
                            <span key={g.from} className="bg-muted rounded-lg px-2 py-1 text-xs font-medium tabular-nums">
                                {g.from === g.to ? g.from : `${g.from} – ${g.to}`}
                            </span>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

/** The hover card of an age group: its biggest invoices, and how many more. */
function AgingPreview({ bucket }: { bucket: Props['aging'][number] }) {
    if (bucket.count === 0) return <span className="text-muted-foreground block text-xs">No invoices here.</span>;
    return (
        <>
            <span className="block divide-y">
                {bucket.top.map((r) => (
                    <span key={r.id} className="flex items-center gap-3 py-1.5">
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-xs font-medium">
                                {doc(r)} · {r.company || '(no group)'}
                            </span>
                            <span className="text-muted-foreground block truncate text-[11px]">
                                {[r.sold_to, r.late !== null && r.late > 0 && `${plural(r.late, 'day')} late`].filter(Boolean).join(' · ') ||
                                    'No company'}
                            </span>
                        </span>
                        <span className="shrink-0 text-xs font-semibold tabular-nums">{peso(r.amount)}</span>
                    </span>
                ))}
            </span>
            <span className="text-primary mt-1.5 block text-[11px] font-medium">
                {bucket.count > bucket.top.length ? `+ ${fmt(bucket.count - bucket.top.length)} more · ` : ''}Click to see all
            </span>
        </>
    );
}

/** What each age group means, for its hover card. */
const AGING_HINTS: Record<string, string> = {
    'not-due': 'still within their terms',
    '1-30': '1 to 30 days past the due date (SI date + terms)',
    '31-60': '31 to 60 days past the due date (SI date + terms)',
    '61-90': '61 to 90 days past the due date (SI date + terms)',
    '90+': 'more than 90 days past the due date (SI date + terms)',
    'no-due': 'with no SI date or terms, so no due date yet',
};

const AGING_COLORS = ['bg-teal-300', 'bg-amber-400', 'bg-orange-500', 'bg-rose-500', 'bg-rose-800', 'bg-slate-400'];

const compact = (n: number) => '₱' + Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);

/** The axis top: the next 1, 2 or 5 × 10ⁿ at or above the biggest bar. */
const niceMax = (n: number) => {
    const p = 10 ** Math.floor(Math.log10(Math.max(n, 1)));
    return [1, 2, 5, 10].map((k) => k * p).find((v) => v >= n) ?? 10 * p;
};

const SERIES = [
    { key: 'billed', label: 'Billed', cls: 'bg-blue-600 dark:bg-blue-500' },
    { key: 'collected', label: 'Collected', cls: 'bg-emerald-600' },
] as const;

/** Billed vs collected per month: paired bars on one ₱ axis, a tooltip per month, and a table for screen readers. */
function Trend({ months }: { months: Month[] }) {
    const [hover, setHover] = useState<number | null>(null);
    const top = niceMax(Math.max(...months.flatMap((m) => [m.billed, m.collected])));
    const name = (m: string, i: number) => {
        const d = new Date(`${m}-01T00:00`);
        const short = d.toLocaleString('en-US', { month: 'short' });
        // The year on the first month and on each January; wide screens only.
        return i === 0 || d.getMonth() === 0 ? (
            <>
                {short}
                <span className="hidden sm:inline"> ’{String(d.getFullYear()).slice(2)}</span>
            </>
        ) : (
            short
        );
    };
    const full = (m: string) => new Date(`${m}-01T00:00`).toLocaleString('en-US', { month: 'long', year: 'numeric' });

    return (
        <div>
            <div className="mb-3 flex gap-4 text-xs">
                {SERIES.map((s) => (
                    <span key={s.key} className="text-muted-foreground flex items-center gap-1.5">
                        <span className={cn('size-2.5 rounded-sm', s.cls)} /> {s.label}
                    </span>
                ))}
            </div>
            <div className="relative h-48 pl-12" aria-hidden data-chart>
                {[0, 0.5, 1].map((f) => (
                    <div key={f} className="absolute right-0 left-12 border-t border-dashed border-current/10" style={{ bottom: `${f * 100}%` }}>
                        <span className="text-muted-foreground absolute -top-2 -left-12 w-10 text-right text-[10px] tabular-nums">
                            {compact(top * f)}
                        </span>
                    </div>
                ))}
                <div className="absolute inset-y-0 right-0 left-12 flex items-end gap-1">
                    {months.map((m, i) => (
                        <div
                            key={m.month}
                            className="relative flex h-full flex-1 items-end justify-center gap-[2px]"
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                        >
                            {SERIES.map((s) => (
                                <span
                                    key={s.key}
                                    className={cn(
                                        'w-full max-w-5 rounded-t-[4px] transition-opacity',
                                        s.cls,
                                        hover !== null && hover !== i && 'opacity-40',
                                    )}
                                    style={{ height: `${(m[s.key] / top) * 100}%` }}
                                />
                            ))}
                            {hover === i && (
                                <div
                                    className={cn(
                                        'bg-popover text-popover-foreground pointer-events-none absolute top-0 z-10 w-max rounded-lg border px-3 py-2 text-xs shadow-md',
                                        i < 2 ? 'left-0' : i > months.length - 3 ? 'right-0' : 'left-1/2 -translate-x-1/2',
                                    )}
                                >
                                    <p className="mb-1 font-semibold">{full(m.month)}</p>
                                    {SERIES.map((s) => (
                                        <p key={s.key} className="flex items-center gap-1.5">
                                            <span className={cn('size-2 rounded-sm', s.cls)} />
                                            <span className="text-muted-foreground">{s.label}</span>
                                            <span className="ml-auto pl-3 font-medium tabular-nums">{peso(m[s.key])}</span>
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
            <div className="text-muted-foreground mt-1.5 flex gap-1 pl-12 text-[10px]" aria-hidden>
                {months.map((m, i) => (
                    // Every other month on a phone, so the names fit.
                    <span
                        key={m.month}
                        className={cn(
                            'flex flex-1 justify-center whitespace-nowrap',
                            months.length > 6 && (months.length - 1 - i) % 2 === 1 && 'invisible sm:visible',
                        )}
                    >
                        {name(m.month, i)}
                    </span>
                ))}
            </div>
            <table className="sr-only">
                <caption>Billed and collected per month</caption>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Billed</th>
                        <th>Collected</th>
                    </tr>
                </thead>
                <tbody>
                    {months.map((m) => (
                        <tr key={m.month}>
                            <td>{full(m.month)}</td>
                            <td>{peso(m.billed)}</td>
                            <td>{peso(m.collected)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

const ALL = '__all';

export default function Dashboard({
    filters,
    groups,
    totals,
    month,
    checkingCount,
    aging,
    collect,
    debtors,
    trend,
    notInvoiced,
    noDue,
    uncleared,
    skipped,
    companies,
}: Props) {
    const go = (params: Filters) =>
        router.get('/dashboard', Object.fromEntries(Object.entries({ ...filters, ...params }).filter(([, v]) => v)), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    // Links into Records keep the summary's group and dates.
    const scoped = (params: Record<string, string>) => records({ ...(filters as Record<string, string>), ...params });
    const tiles = [
        {
            label: 'Unpaid',
            value: totals.unpaid,
            href: scoped({ payment: 'unpaid' }),
            icon: <Wallet />,
            cls: 'from-rose-400 to-rose-600 text-white',
            hint: totals.overdue ? `${peso(totals.overdue)} overdue` : 'Nothing overdue',
        },
        {
            label: 'For checking',
            value: totals.checking,
            href: scoped({ payment: 'checking' }),
            icon: <Eye />,
            cls: 'from-sky-400 to-sky-600 text-white',
            hint: checkingCount ? `${plural(checkingCount, 'payment')} to confirm` : 'Nothing to confirm',
        },
        {
            label: 'Checks not cleared',
            value: uncleared.amount,
            href: '#checks',
            icon: <AlarmClock />,
            cls: 'from-amber-400 to-amber-600 text-white',
            hint: plural(uncleared.count, 'check'),
        },
        {
            label: 'Collected this month',
            value: month.collected,
            href: scoped({ payment: 'paid' }),
            icon: <TrendingUp />,
            cls: 'from-emerald-400 to-emerald-600 text-white',
            hint: `${peso(month.billed)} billed this month`,
        },
        {
            label: 'Total billed',
            value: totals.paid + totals.checking + totals.unpaid,
            href: scoped({}),
            icon: <Receipt />,
            cls: 'from-slate-100 to-slate-200 text-slate-700',
            hint: `${fmt(totals.count)} records · ${peso(totals.paid)} paid`,
        },
    ];
    const unpaid = Math.max(
        1,
        aging.reduce((s, a) => s + a.amount, 0),
    );
    const billed = (c: Sums) => Math.max(1, c.paid + c.checking + c.unpaid);
    const max = Math.max(1, ...companies.map(billed));

    return (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <div className="bg-card flex flex-wrap items-center gap-3 rounded-3xl p-4 shadow-sm xl:col-span-12">
                <div className="w-full sm:w-56">
                    <Select
                        aria-label="Group"
                        options={[{ value: ALL, label: 'All groups' }, ...groups.map((g) => ({ value: g, label: g }))]}
                        value={filters.company ?? ALL}
                        onValueChange={(v) => go({ company: v === ALL ? '' : v })}
                    />
                </div>
                <div className="w-[calc(50%-0.375rem)] sm:w-44">
                    <DatePicker value={filters.from ?? ''} onChange={(from) => go({ from })} placeholder="From date" clearable />
                </div>
                <div className="w-[calc(50%-0.375rem)] sm:w-44">
                    <DatePicker value={filters.to ?? ''} onChange={(to) => go({ to })} placeholder="To date" clearable />
                </div>
                {(filters.company || filters.from || filters.to) && (
                    <Button variant="ghost" size="sm" onClick={() => router.get('/dashboard', {}, { preserveScroll: true, replace: true })}>
                        Clear
                    </Button>
                )}
                <span className="text-muted-foreground ml-auto text-xs">By SI date (DR date when there is no SI yet)</span>
            </div>

            <div className="grid grid-cols-2 gap-4 xl:col-span-12 xl:grid-cols-5">
                {tiles.map((t) => {
                    const body = (
                        <>
                            <span className="flex items-center justify-between gap-2 text-sm font-medium opacity-90">
                                {t.label} <span className="[&>svg]:size-5">{t.icon}</span>
                            </span>
                            <span className="mt-3 block truncate text-xl font-black tabular-nums sm:text-2xl">{peso(t.value)}</span>
                            <span className="mt-1 block truncate text-xs opacity-80">{t.hint}</span>
                        </>
                    );
                    const cls = cn('rounded-3xl bg-gradient-to-br p-5 shadow-sm transition hover:-translate-y-0.5', t.cls);
                    // A card further down this page: a plain jump, not a visit.
                    return t.href.startsWith('#') ? (
                        <a key={t.label} href={t.href} className={cls}>
                            {body}
                        </a>
                    ) : (
                        <Link key={t.label} prefetch href={t.href} className={cls}>
                            {body}
                        </Link>
                    );
                })}
            </div>

            <Card className="min-w-0 xl:col-span-7">
                <CardTitle hint="Overdue first, then what falls due within a week.">To collect</CardTitle>
                <ul className="divide-y">
                    {collect.length === 0 && <Empty>Nothing overdue or due this week.</Empty>}
                    {collect.map((r) => (
                        <Row
                            key={r.id}
                            r={r}
                            sub={`${[r.sold_to, r.project].filter(Boolean).join(' · ') || 'No company'} · due ${prettyDate(r.due)}`}
                            note={r.late > 0 ? `${plural(r.late, 'day')} late` : r.late === 0 ? 'Due today' : `Due in ${plural(-r.late, 'day')}`}
                            noteCls={r.late > 0 ? 'text-destructive' : 'text-amber-600'}
                        />
                    ))}
                </ul>
            </Card>

            <Card className="min-w-0 xl:col-span-5">
                <CardTitle hint="Unpaid invoices by how long past their terms. Click one to see them.">Unpaid by age</CardTitle>
                <div className="bg-muted mb-4 flex h-3 overflow-hidden rounded-full">
                    {aging.map((a, i) => (
                        <span key={a.label} className={AGING_COLORS[i]} style={{ width: `${(a.amount / unpaid) * 100}%` }} title={a.label} />
                    ))}
                </div>
                <ul className="space-y-0.5">
                    {aging.map((a, i) => (
                        // The whole row opens the list; the label also explains the group on hover (or focus).
                        <li
                            key={a.key}
                            onClick={() => router.visit(scoped({ show: a.key }))}
                            className="hover:bg-muted/60 -mx-2 flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm"
                        >
                            <span className={cn('size-2.5 shrink-0 rounded-full', AGING_COLORS[i])} />
                            <span className="min-w-0 flex-1">
                                <Hyperlink
                                    href={scoped({ show: a.key })}
                                    previewTitle={`${a.label}: ${peso(a.amount)}`}
                                    previewDescription={`${plural(a.count, 'unpaid invoice')} ${AGING_HINTS[a.key] ?? ''}.`}
                                    preview={<AgingPreview bucket={a} />}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        router.visit(scoped({ show: a.key }));
                                    }}
                                >
                                    {a.label}
                                </Hyperlink>{' '}
                                <span className="text-muted-foreground text-xs">· {plural(a.count, 'invoice')}</span>
                            </span>
                            <span
                                className={cn(
                                    'font-semibold tabular-nums',
                                    a.key !== 'not-due' && a.key !== 'no-due' && a.amount > 0 && 'text-destructive',
                                )}
                            >
                                {peso(a.amount)}
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>

            <Card className="min-w-0 xl:col-span-12">
                <CardTitle hint="Billed by SI date, collected by OR date (or check date). Hover a month for its amounts.">
                    Billed vs collected
                </CardTitle>
                <Trend months={trend} />
            </Card>

            <Card className="min-w-0 xl:col-span-7">
                <CardTitle hint="Unpaid per company, and how many days they usually take to pay (SI date to OR date).">Who owes the most</CardTitle>
                <ul className="divide-y">
                    {debtors.length === 0 && <Empty>No one owes anything.</Empty>}
                    {debtors.map((c) => (
                        <li key={c.name}>
                            <Link
                                prefetch
                                href={scoped({ q: c.name, payment: 'unpaid' })}
                                className="hover:bg-muted/60 -mx-2 flex items-center gap-3 rounded-lg px-2 py-2"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">{c.name}</span>
                                    <span className="text-muted-foreground block truncate text-[11px]">
                                        {[
                                            c.group !== c.name && c.group,
                                            plural(c.count, 'unpaid invoice'),
                                            c.days !== null && `usually pays in ${plural(c.days, 'day')}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <span className="shrink-0 text-right">
                                    <span className="block text-sm font-semibold tabular-nums">{peso(c.unpaid)}</span>
                                    {c.overdue > 0 && (
                                        <span className="text-destructive block text-[11px] font-medium">{peso(c.overdue)} overdue</span>
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>

            <Card className="min-w-0 xl:col-span-5">
                <CardTitle
                    hint={
                        <>
                            Has a DR but no SI yet: goods out, not billed.{' '}
                            {notInvoiced.count > 0 && (
                                <Link href={scoped({ show: 'not-invoiced' })} className="text-primary font-medium hover:underline">
                                    See all {fmt(notInvoiced.count)}
                                </Link>
                            )}
                        </>
                    }
                >
                    Delivered, not yet invoiced
                </CardTitle>
                <ul className="divide-y">
                    {notInvoiced.rows.length === 0 && <Empty>Every delivery has its SI.</Empty>}
                    {notInvoiced.rows.map((r) => (
                        <Row
                            key={r.id}
                            r={r}
                            sub={`${[r.sold_to, r.project].filter(Boolean).join(' · ') || 'No company'}${r.dr_date ? ` · delivered ${prettyDate(r.dr_date)}` : ''}`}
                            note={r.days !== null ? `${plural(r.days, 'day')} ago` : 'No DR date'}
                            noteCls={r.days !== null && r.days > 7 ? 'text-destructive' : 'text-amber-600'}
                        />
                    ))}
                </ul>
            </Card>

            <Card id="checks" className="min-w-0 scroll-mt-20 xl:col-span-7">
                <CardTitle hint="Paid by check, not yet marked Cleared.">Checks not cleared</CardTitle>
                <ul className="divide-y">
                    {uncleared.rows.length === 0 && <Empty>Every check has cleared.</Empty>}
                    {uncleared.rows.map((r) => (
                        <Row
                            key={r.id}
                            r={r}
                            sub={`Check ${r.check_number ?? '(no number)'}${r.sold_to ? ` · ${r.sold_to}` : ''}`}
                            note={r.check_date ? `Deposit ${prettyDate(r.check_date)}` : 'No deposit date'}
                            noteCls="text-amber-600"
                        />
                    ))}
                </ul>
            </Card>

            <Card className="min-w-0 xl:col-span-5">
                <CardTitle hint="Numbers from the booklet's first # up to the highest one entered that are not in the records: skipped, cancelled or not yet entered.">
                    Skipped receipts
                </CardTitle>
                <div className="space-y-5">
                    <Skipped doc="si" label="SI #" gaps={skipped.si} />
                    <Skipped doc="dr" label="DR #" gaps={skipped.dr} />
                </div>
            </Card>

            <Card className="min-w-0 xl:col-span-5">
                <CardTitle
                    hint={
                        <>
                            Unpaid, but no SI date or terms, so no due date: they never show as overdue.{' '}
                            {noDue.count > 0 && (
                                <Link href={scoped({ show: 'no-due' })} className="text-primary font-medium hover:underline">
                                    See all {fmt(noDue.count)}
                                </Link>
                            )}
                        </>
                    }
                >
                    Missing details
                </CardTitle>
                <ul className="divide-y">
                    {noDue.rows.length === 0 && <Empty>Every unpaid invoice has its SI date and terms.</Empty>}
                    {noDue.rows.map((r) => (
                        <Row
                            key={r.id}
                            r={r}
                            sub={[r.sold_to, r.project].filter(Boolean).join(' · ') || 'No company'}
                            note={`No ${r.missing}`}
                            noteCls="text-amber-600"
                        />
                    ))}
                </ul>
            </Card>

            <Card className="min-w-0 xl:col-span-7">
                <CardTitle>Per group</CardTitle>
                <ul className="divide-y">
                    {companies.length === 0 && <Empty>No records yet.</Empty>}
                    {companies.map((c) => (
                        <li key={c.company}>
                            <Link prefetch href={scoped({ company: c.company })} className="hover:bg-muted/60 -mx-2 block rounded-lg px-2 py-2.5">
                                <span className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                    <span className="min-w-0 truncate font-medium">
                                        {c.company || '(no group)'}{' '}
                                        <span className="text-muted-foreground text-xs font-normal">{plural(c.count, 'record')}</span>
                                    </span>
                                    <span className="flex gap-4 text-sm tabular-nums">
                                        <span className="text-rose-600">{peso(c.unpaid)} unpaid</span>
                                        {c.checking > 0 && <span className="text-sky-600">{peso(c.checking)} for checking</span>}
                                        <span className="text-emerald-600">{peso(c.paid)} paid</span>
                                    </span>
                                </span>
                                {/* Paid, for checking and unpaid share of what was billed, scaled to the biggest group. */}
                                <span
                                    className="bg-muted mt-2 flex h-1.5 overflow-hidden rounded-full"
                                    style={{ width: `${(billed(c) / max) * 100}%` }}
                                >
                                    <span className="bg-emerald-500" style={{ width: `${(c.paid / billed(c)) * 100}%` }} />
                                    <span className="bg-sky-500" style={{ width: `${(c.checking / billed(c)) * 100}%` }} />
                                    <span className="bg-rose-500" style={{ width: `${(c.overdue / billed(c)) * 100}%` }} />
                                    <span className="bg-amber-400" style={{ width: `${((c.unpaid - c.overdue) / billed(c)) * 100}%` }} />
                                </span>
                                {c.overdue > 0 && <span className="text-destructive mt-1 block text-xs">{peso(c.overdue)} overdue</span>}
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}

Dashboard.layout = (page: React.ReactNode) => <AppLayout breadcrumbs={breadcrumbs}>{page}</AppLayout>;
