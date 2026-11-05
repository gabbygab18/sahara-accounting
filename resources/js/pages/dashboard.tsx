import { Button } from '@/components/glint/button';
import { Input } from '@/components/glint/input';
import { AlarmClock, Eye, Receipt, TrendingUp, Wallet } from '@/icons';
import AppLayout from '@/layouts/app-layout';
import { fmt, peso, prettyDate } from '@/lib/records';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Link, useForm } from '@inertiajs/react';

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

interface Props {
    totals: Sums;
    month: { billed: number; collected: number };
    checkingCount: number;
    aging: { label: string; count: number; amount: number }[];
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

const AGING_COLORS = ['bg-slate-300', 'bg-amber-400', 'bg-orange-500', 'bg-rose-500', 'bg-rose-800'];

export default function Dashboard({ totals, month, checkingCount, aging, collect, uncleared, skipped, companies }: Props) {
    const tiles = [
        {
            label: 'Unpaid',
            value: totals.unpaid,
            href: records({ payment: 'unpaid' }),
            icon: <Wallet />,
            cls: 'from-rose-400 to-rose-600 text-white',
            hint: totals.overdue ? `${peso(totals.overdue)} overdue` : 'Nothing overdue',
        },
        {
            label: 'For checking',
            value: totals.checking,
            href: records({ payment: 'checking' }),
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
            href: records({ payment: 'paid' }),
            icon: <TrendingUp />,
            cls: 'from-emerald-400 to-emerald-600 text-white',
            hint: `${peso(month.billed)} billed this month`,
        },
        {
            label: 'Total billed',
            value: totals.paid + totals.checking + totals.unpaid,
            href: '/records',
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
                <CardTitle hint="Unpaid invoices by how long past their terms.">Unpaid by age</CardTitle>
                <div className="bg-muted mb-4 flex h-3 overflow-hidden rounded-full">
                    {aging.map((a, i) => (
                        <span key={a.label} className={AGING_COLORS[i]} style={{ width: `${(a.amount / unpaid) * 100}%` }} title={a.label} />
                    ))}
                </div>
                <ul className="space-y-2.5">
                    {aging.map((a, i) => (
                        <li key={a.label} className="flex items-center gap-2.5 text-sm">
                            <span className={cn('size-2.5 shrink-0 rounded-full', AGING_COLORS[i])} />
                            <span className="flex-1">
                                {a.label} <span className="text-muted-foreground text-xs">· {plural(a.count, 'invoice')}</span>
                            </span>
                            <span className={cn('font-semibold tabular-nums', i > 0 && a.amount > 0 && 'text-destructive')}>{peso(a.amount)}</span>
                        </li>
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

            <Card className="min-w-0 xl:col-span-12">
                <CardTitle>Per group</CardTitle>
                <ul className="divide-y">
                    {companies.length === 0 && <Empty>No records yet.</Empty>}
                    {companies.map((c) => (
                        <li key={c.company}>
                            <Link prefetch href={records({ company: c.company })} className="hover:bg-muted/60 -mx-2 block rounded-lg px-2 py-2.5">
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
