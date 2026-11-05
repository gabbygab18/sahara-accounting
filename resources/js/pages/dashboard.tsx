import { AlarmClock, CheckCircle, Eye, Receipt, Wallet } from '@/icons';
import AppLayout from '@/layouts/app-layout';
import { fmt, peso, prettyDate } from '@/lib/records';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Summary', href: '/dashboard' }];

interface Sums {
    count: number;
    paid: number;
    checking: number;
    unpaid: number;
    overdue: number;
}

interface Props {
    totals: Sums;
    companies: (Sums & { company: string })[];
    overdue: {
        id: number;
        company: string;
        sold_to: string | null;
        project: string | null;
        si_number: string | null;
        dr_number: string | null;
        po_number: string | null;
        amount: number;
        due: string;
    }[];
}

function Card({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('bg-card rounded-3xl p-5 shadow-sm', className)} {...props} />;
}

function CardTitle({ children, action }: { children: React.ReactNode; action?: React.ReactNode }) {
    return (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 className="border-primary border-l-[3px] pl-2.5 font-bold">{children}</h2>
            {action}
        </div>
    );
}

const records = (params: Record<string, string>) => `/records?${new URLSearchParams(params)}`;

export default function Dashboard({ totals, companies, overdue }: Props) {
    const tiles = [
        {
            label: 'Unpaid',
            value: totals.unpaid,
            href: records({ payment: 'unpaid' }),
            icon: <Wallet />,
            cls: 'from-amber-400 to-amber-600 text-white',
        },
        {
            label: 'Overdue',
            value: totals.overdue,
            href: records({ payment: 'unpaid' }),
            icon: <AlarmClock />,
            cls: 'from-rose-400 to-rose-600 text-white',
            hint: 'Unpaid past the terms',
        },
        {
            label: 'For checking',
            value: totals.checking,
            href: records({ payment: 'checking' }),
            icon: <Eye />,
            cls: 'from-sky-400 to-sky-600 text-white',
            hint: 'Marked paid, not yet confirmed',
        },
        {
            label: 'Paid',
            value: totals.paid,
            href: records({ payment: 'paid' }),
            icon: <CheckCircle />,
            cls: 'from-emerald-400 to-emerald-600 text-white',
        },
        {
            label: 'Total billed',
            value: totals.paid + totals.checking + totals.unpaid,
            href: '/records',
            icon: <Receipt />,
            cls: 'from-slate-100 to-slate-200 text-slate-700',
            hint: `${fmt(totals.count)} records`,
        },
    ];
    const billed = (c: Sums) => Math.max(1, c.paid + c.checking + c.unpaid);
    const max = Math.max(1, ...companies.map(billed));

    return (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <div className="grid grid-cols-2 gap-4 xl:col-span-12 xl:grid-cols-5">
                {tiles.map((t) => (
                    <Link
                        key={t.label}
                        prefetch
                        href={t.href}
                        className={cn('rounded-3xl bg-gradient-to-br p-5 shadow-sm transition hover:-translate-y-0.5', t.cls)}
                    >
                        <span className="flex items-center justify-between text-sm font-medium opacity-90">
                            {t.label} <span className="[&>svg]:size-5">{t.icon}</span>
                        </span>
                        <span className="mt-3 block truncate text-xl font-black tabular-nums sm:text-2xl">{peso(t.value)}</span>
                        {t.hint && <span className="mt-1 block text-xs opacity-80">{t.hint}</span>}
                    </Link>
                ))}
            </div>

            <Card className="min-w-0 xl:col-span-7">
                <CardTitle>Per group</CardTitle>
                <ul className="divide-y">
                    {companies.length === 0 && <li className="text-muted-foreground py-6 text-center text-sm">No records yet.</li>}
                    {companies.map((c) => (
                        <li key={c.company}>
                            <Link prefetch href={records({ company: c.company })} className="hover:bg-muted/60 -mx-2 block rounded-lg px-2 py-2.5">
                                <span className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                    <span className="min-w-0 truncate font-medium">
                                        {c.company || '(no group)'}{' '}
                                        <span className="text-muted-foreground text-xs font-normal">
                                            {fmt(c.count)} {c.count === 1 ? 'record' : 'records'}
                                        </span>
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

            <Card className="min-w-0 xl:col-span-5">
                <CardTitle>Overdue invoices</CardTitle>
                <ul className="divide-y">
                    {overdue.length === 0 && <li className="text-muted-foreground py-6 text-center text-sm">Nothing past its terms.</li>}
                    {overdue.map((r) => (
                        <li key={r.id}>
                            <Link
                                prefetch
                                href={records({ q: r.si_number ?? r.dr_number ?? r.po_number ?? r.company })}
                                className="hover:bg-muted/60 -mx-2 flex items-center gap-3 rounded-lg px-2 py-2"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {r.si_number ? `SI ${r.si_number}` : r.dr_number ? `DR ${r.dr_number}` : 'No SI #'} · {r.company}
                                    </span>
                                    <span className="text-muted-foreground block truncate text-[11px]">
                                        {[r.sold_to, r.project].filter(Boolean).join(' · ') || 'No company'} · due {prettyDate(r.due)}
                                    </span>
                                </span>
                                <span className="text-destructive shrink-0 text-sm font-semibold tabular-nums">{peso(r.amount)}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}

Dashboard.layout = (page: React.ReactNode) => <AppLayout breadcrumbs={breadcrumbs}>{page}</AppLayout>;
