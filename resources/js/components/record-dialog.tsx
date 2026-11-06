import { ComboInput } from '@/components/combo-input';
import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/glint/button';
import { Checkbox } from '@/components/glint/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/glint/dialog';
import { Input } from '@/components/glint/input';
import { Select } from '@/components/glint/select';
import { StretchTabs } from '@/components/glint/stretch-tabs';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { Plus, Trash, X } from '@/icons';
import { peso, prettyDate, terms, type AccountingRecord, type RecordOptions } from '@/lib/records';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

type Line = { date: string; number: string };
type Dr = Line & { status: string };
type Si = Line & { amount: string };
type Form = Omit<
    AccountingRecord,
    'id' | 'due' | 'overdue' | 'amount' | 'terms_days' | 'drs' | 'sis' | 'dr_date' | 'dr_number' | 'delivery_status' | 'si_date' | 'si_number'
> & { terms_days: string; drs: Dr[]; sis: Si[] };

const blankDr: Dr = { date: '', number: '', status: '' };
const blankSi: Si = { date: '', number: '', amount: '' };

const blank: Form = {
    company: '',
    sold_to: '',
    project: '',
    tin: '',
    po_date: '',
    po_number: '',
    terms_days: '30',
    drs: [blankDr],
    sis: [blankSi],
    or_date: '',
    or_number: '',
    paid: false,
    paid_confirmed: false,
    cash: false,
    si_missing: false,
    si_cancelled: false,
    booklet_number: '',
    envelope_number: '',
    check_number: '',
    check_date: '',
    check_cleared: false,
};

const NOT_SET = '__none'; // a select can't hold an empty value

const str = (v: unknown) => (v === null || v === undefined ? '' : String(v));

const toForm = (r: AccountingRecord): Form => ({
    ...(Object.fromEntries(
        Object.keys(blank).map((k) => {
            const v = r[k as keyof AccountingRecord];
            return [k, typeof v === 'boolean' ? v : str(v)];
        }),
    ) as unknown as Form),
    drs: r.drs.length ? r.drs.map((l) => ({ date: str(l.date), number: str(l.number), status: str(l.status) })) : [blankDr],
    sis: r.sis.length ? r.sis.map((l) => ({ date: str(l.date), number: str(l.number), amount: str(l.amount) })) : [blankSi],
});

const money = (v: string) => Number(v.replace(/,/g, '')) || 0;

/** Rows of DRs or SIs: a header on wide screens, one row per line, and "Add another". */
function Lines<T>({
    labels,
    rows,
    onChange,
    blankRow,
    noun,
    children,
}: {
    labels: string[];
    rows: T[];
    onChange: (rows: T[]) => void;
    blankRow: T;
    noun: string;
    children: (row: T, set: (patch: Partial<T>) => void, i: number) => React.ReactNode;
}) {
    const grid = 'grid gap-2 sm:grid-cols-[1fr_1fr_1fr_2.5rem]';
    return (
        <div className="grid gap-2">
            <div className={cn(grid, 'hidden sm:grid')}>
                {labels.map((l) => (
                    <Label key={l}>{l}</Label>
                ))}
            </div>
            {rows.map((row, i) => (
                <div key={i} className={cn(grid, i > 0 && 'border-t pt-2 sm:border-0 sm:pt-0')}>
                    {children(row, (patch) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r))), i)}
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={`Remove ${noun} ${i + 1}`}
                        onClick={() => onChange(rows.length > 1 ? rows.filter((_, j) => j !== i) : [blankRow])}
                    >
                        <X className="size-4" />
                    </Button>
                </div>
            ))}
            <button
                type="button"
                className="text-primary inline-flex w-fit items-center gap-1 text-xs font-medium hover:underline"
                onClick={() => onChange([...rows, blankRow])}
            >
                <Plus className="size-3.5" /> Add another {noun}
            </button>
        </div>
    );
}

function Field({ label, id, error, children }: { label: string; id?: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid min-w-0 content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}

/** Several numbers in one field, stored comma separated ("1001, 1002") so the Sheet and exports keep one cell. */
function Numbers({ id, value, onChange, noun }: { id: string; value: string; onChange: (v: string) => void; noun: string }) {
    const [rows, setRows] = useState(() => value.split(',').map((v) => v.trim()));
    useEffect(() => {
        if (
            rows
                .map((v) => v.trim())
                .filter(Boolean)
                .join(', ') !== value
        )
            setRows(value.split(',').map((v) => v.trim()));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);
    const set = (next: string[]) => {
        setRows(next.length ? next : ['']);
        onChange(
            next
                .map((v) => v.trim())
                .filter(Boolean)
                .join(', '),
        );
    };
    return (
        <div className="grid gap-1.5">
            {rows.map((v, i) => (
                <div key={i} className="flex gap-1">
                    <Input
                        id={i ? undefined : id}
                        aria-label={i ? `${noun} ${i + 1}` : undefined}
                        value={v}
                        // A pasted "1001, 1002" splits into rows.
                        onChange={(e) => set(rows.flatMap((r, j) => (j === i ? e.target.value.split(',') : [r])))}
                    />
                    {rows.length > 1 && (
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label={`Remove ${noun} ${v}`}
                            onClick={() => set(rows.filter((_, j) => j !== i))}
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            ))}
            <button
                type="button"
                className="text-primary inline-flex w-fit items-center gap-1 text-xs font-medium hover:underline"
                onClick={() => setRows([...rows, ''])}
            >
                <Plus className="size-3.5" /> Add another {noun}
            </button>
        </div>
    );
}

const join = (parts: unknown[]) => parts.filter(Boolean).join(' · ');

/** A new record as it will be saved, to double check before adding it. Blank fields are left out. */
function Review({ data, canConfirm }: { data: Form; canConfirm: boolean }) {
    const check = !data.cash && (data.check_number || data.check_date);
    const rows: [string, string][] = [
        ['Group', data.company],
        ['Company (sold to)', data.sold_to ?? ''],
        ['Project', data.project ?? ''],
        ['TIN', data.tin ?? ''],
        ['PO', join([data.po_number, data.po_date && prettyDate(data.po_date)])],
        ['Terms', data.terms_days === '' ? '' : terms(Number(data.terms_days))],
        [
            'DR',
            data.drs
                .map((l) => join([l.number && `DR ${l.number}`, l.date && prettyDate(l.date), l.status]))
                .filter(Boolean)
                .join('\n'),
        ],
        [
            'SI',
            data.sis
                .map((l) => join([l.number && `SI ${l.number}`, l.date && prettyDate(l.date), l.amount && peso(money(l.amount))]))
                .filter(Boolean)
                .join('\n'),
        ],
        ['Actual SI', data.si_missing ? 'Missing' : ''],
        ['Cancelled', data.si_cancelled ? 'Yes, this SI is cancelled' : ''],
        ['Booklet #', data.booklet_number ?? ''],
        ['Envelope #', data.envelope_number ?? ''],
        ['Total', peso(data.sis.reduce((sum, l) => sum + money(l.amount), 0))],
        ['Payment', !data.paid ? 'Unpaid' : canConfirm && !data.paid_confirmed ? 'For checking' : 'Paid'],
        ['Paid by', data.cash ? 'Cash' : check || data.paid ? 'Check' : ''],
        ['Cash date', data.cash && data.check_date ? prettyDate(data.check_date) : ''],
        [
            'Check',
            check
                ? join([
                      data.check_number,
                      data.check_date && `deposit ${prettyDate(data.check_date)}`,
                      data.check_cleared ? 'cleared' : 'not cleared',
                  ])
                : '',
        ],
        ['OR', join([data.or_number, data.or_date && prettyDate(data.or_date)])],
    ];
    return (
        <dl className="divide-y rounded-2xl border">
            {rows
                .filter(([, v]) => v)
                .map(([k, v]) => (
                    <div key={k} className="grid gap-1 px-4 py-2.5 text-sm sm:grid-cols-[10rem_1fr] sm:gap-3">
                        <dt className="text-muted-foreground">{k}</dt>
                        <dd className="font-medium whitespace-pre-line">{v}</dd>
                    </div>
                ))}
        </dl>
    );
}

/** Add a record (no `editing`) or change one. Laid out like the paper: who, delivery, invoice, payment. */
export function RecordDialog({
    open,
    onOpenChange,
    editing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editing?: AccountingRecord | null;
}) {
    const [options, setOptions] = useState<RecordOptions>();
    const canDelete = useCan('delete');
    const canConfirm = useCan('confirm');
    // Staff cannot undo the admin's double check.
    const locked = !canConfirm && !!editing?.paid_confirmed;
    const [confirming, setConfirming] = useState(false);
    // A new record is shown once more to double check before it is added.
    const [reviewing, setReviewing] = useState(false);
    const form = useForm<Form>(blank);
    const { data, setData, errors, processing, transform } = form;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(editing ? toForm(editing) : blank);
        setConfirming(false);
        setReviewing(false);
        fetch(route('records.options'), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then(setOptions)
            .catch(() => {}); // suggestions only: the form works without them
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id]);

    const known = (name: string) => options?.companies.find((c) => c.name.toLowerCase() === name.trim().toLowerCase());
    const company = known(data.company);
    // A known group fills its TIN when that is still empty, and, on a new record, its usual terms.
    const setGroup = (name: string) => {
        const c = known(name);
        setData((d) => ({
            ...d,
            company: name,
            tin: d.tin || (c?.tin ?? ''),
            terms_days: c && !editing && c.terms !== null ? String(c.terms) : d.terms_days,
        }));
    };
    transform((d) => ({
        ...d,
        terms_days: d.terms_days === '' ? null : d.terms_days,
        sis: d.sis.map((l) => ({ ...l, amount: l.amount.trim() === '' ? null : l.amount.replace(/,/g, '') })),
    }));
    const lineError = (prefix: string) => Object.entries(errors as Record<string, string>).find(([k]) => k.startsWith(prefix))?.[1];
    const delivery = options?.delivery ?? ['Not delivered', 'Partial', 'Delivered'];

    const done = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) form.put(route('records.update', editing.id), done);
        else if (!reviewing) setReviewing(true);
        // Back to the form to fix what the server refused (e.g. an SI # already used).
        else form.post(route('records.store'), { ...done, onError: () => setReviewing(false) });
    };

    const text = (key: keyof Form, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <Field label={label} id={`r-${key}`} error={errors[key]}>
            <Input id={`r-${key}`} value={String(data[key] ?? '')} onChange={(e) => setData(key, e.target.value as never)} {...props} />
        </Field>
    );
    const numbers = (key: 'po_number', label: string) => (
        <Field label={label} id={`r-${key}`} error={errors[key]}>
            <Numbers id={`r-${key}`} noun={label.replace(' #', '')} value={data[key] ?? ''} onChange={(v) => setData(key, v)} />
        </Field>
    );
    const date = (key: 'po_date' | 'check_date' | 'or_date', label: string) => (
        <Field label={label} error={errors[key]}>
            <DatePicker value={data[key] ?? ''} onChange={(v) => setData(key, v)} clearable />
        </Field>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Edit record' : reviewing ? 'Double check' : 'Add record'}</DialogTitle>
                    <DialogDescription>
                        {reviewing
                            ? 'This is what will be added. Go back to fix anything, or confirm.'
                            : 'Copy it from the PO, DRs and sales invoices. Only the group is required.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-5">
                    {reviewing && <Review data={data} canConfirm={canConfirm} />}
                    <div className={cn('grid gap-5', reviewing && 'hidden')}>
                        <section className="grid gap-3 sm:grid-cols-2">
                            <Field label="Group (main company)" id="r-company" error={errors.company}>
                                <ComboInput
                                    id="r-company"
                                    required
                                    placeholder="e.g. Cityland Dev Corp"
                                    options={options?.companies.map((c) => c.name) ?? []}
                                    value={data.company}
                                    onValueChange={(v, picked) => (picked ? setGroup(v) : setData('company', v))}
                                    onBlur={() => setGroup(data.company)}
                                />
                            </Field>
                            <Field label="Company (Sold to on the SI)" id="r-sold_to" error={errors.sold_to}>
                                <ComboInput
                                    id="r-sold_to"
                                    placeholder="e.g. Pines Peak Towers"
                                    options={company?.sold_to ?? []}
                                    value={data.sold_to ?? ''}
                                    onValueChange={(v) => setData('sold_to', v)}
                                />
                            </Field>
                            <Field label="Project" id="r-project" error={errors.project}>
                                <ComboInput
                                    id="r-project"
                                    placeholder="e.g. Ground up"
                                    options={company?.projects ?? []}
                                    value={data.project ?? ''}
                                    onValueChange={(v) => setData('project', v)}
                                />
                            </Field>
                            {text('tin', 'TIN')}
                            {text('booklet_number', 'Booklet #', { placeholder: 'Optional' })}
                            {text('envelope_number', 'Envelope #', { placeholder: 'Optional' })}
                        </section>

                        <section className="grid gap-3 border-t pt-4 sm:grid-cols-3">
                            {date('po_date', 'PO date')}
                            {numbers('po_number', 'PO #')}
                            {text('terms_days', 'Terms (days)', { inputMode: 'numeric', placeholder: '30, or 0 for COD' })}
                        </section>

                        <section className="grid gap-1.5 border-t pt-4">
                            <Lines
                                labels={['DR date', 'DR #', 'Delivery status']}
                                rows={data.drs}
                                onChange={(drs) => setData('drs', drs)}
                                blankRow={blankDr}
                                noun="DR"
                            >
                                {(l, set, i) => (
                                    <>
                                        <DatePicker value={l.date} onChange={(date) => set({ date })} placeholder="DR date" clearable />
                                        <Input
                                            aria-label={`DR # ${i + 1}`}
                                            placeholder="DR #"
                                            value={l.number}
                                            onChange={(e) => set({ number: e.target.value })}
                                        />
                                        <Select
                                            aria-label={`Delivery status ${i + 1}`}
                                            options={[{ value: NOT_SET, label: 'Not set' }, ...delivery.map((d) => ({ value: d, label: d }))]}
                                            value={l.status || NOT_SET}
                                            onValueChange={(v) => set({ status: v === NOT_SET ? '' : v })}
                                        />
                                    </>
                                )}
                            </Lines>
                            {lineError('drs') && <p className="text-destructive text-xs">{lineError('drs')}</p>}
                        </section>

                        <section className="grid gap-1.5 border-t pt-4">
                            <Lines
                                labels={['SI date', 'SI #', 'Amount']}
                                rows={data.sis}
                                onChange={(sis) => setData('sis', sis)}
                                blankRow={blankSi}
                                noun="SI"
                            >
                                {(l, set, i) => (
                                    <>
                                        <DatePicker value={l.date} onChange={(date) => set({ date })} placeholder="SI date" clearable />
                                        <Input
                                            aria-label={`SI # ${i + 1}`}
                                            placeholder="SI #"
                                            value={l.number}
                                            onChange={(e) => set({ number: e.target.value })}
                                        />
                                        <Input
                                            aria-label={`SI amount ${i + 1}`}
                                            inputMode="decimal"
                                            startIcon="₱"
                                            placeholder="0.00"
                                            value={l.amount}
                                            onChange={(e) => set({ amount: e.target.value })}
                                        />
                                    </>
                                )}
                            </Lines>
                            {lineError('sis') && <p className="text-destructive text-xs">{lineError('sis')}</p>}
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="grid gap-2">
                                    <Checkbox
                                        label="Cancelled SI"
                                        description="Keeps the SI # on record; it is left out of what is owed."
                                        checked={data.si_cancelled}
                                        onChange={(e) => setData('si_cancelled', e.target.checked)}
                                    />
                                    <Checkbox
                                        label="Missing the actual SI"
                                        description="The paper copy can't be found. It shows under Missing details on the dashboard."
                                        checked={data.si_missing}
                                        onChange={(e) => setData('si_missing', e.target.checked)}
                                    />
                                </div>
                                <p className="text-right text-sm">
                                    <span className="text-muted-foreground">Total </span>
                                    <b className="tabular-nums">{peso(data.sis.reduce((sum, l) => sum + money(l.amount), 0))}</b>
                                </p>
                            </div>
                        </section>

                        <section className="grid gap-3 border-t pt-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Payment">
                                    {locked ? (
                                        <p className="text-sm text-emerald-600">Paid, confirmed by the admin.</p>
                                    ) : (
                                        <>
                                            <StretchTabs
                                                label="Payment status"
                                                options={canConfirm ? ['Unpaid', 'For checking', 'Paid'] : ['Unpaid', 'Paid']}
                                                value={!data.paid ? 'Unpaid' : canConfirm && !data.paid_confirmed ? 'For checking' : 'Paid'}
                                                onChange={(v) => setData((d) => ({ ...d, paid: v !== 'Unpaid', paid_confirmed: v === 'Paid' }))}
                                            />
                                            {!canConfirm && data.paid && (
                                                <p className="text-muted-foreground text-xs">The admin double checks it before it counts as paid.</p>
                                            )}
                                        </>
                                    )}
                                </Field>
                                <Field label="Paid by">
                                    <StretchTabs
                                        label="Paid by"
                                        options={['Check', 'Cash']}
                                        value={data.cash ? 'Cash' : 'Check'}
                                        onChange={(v) => setData('cash', v === 'Cash')}
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {data.cash ? (
                                    date('check_date', 'Cash date (when paid)')
                                ) : (
                                    <>
                                        {text('check_number', 'Check #')}
                                        {date('check_date', 'Check date (for deposit)')}
                                        <Field label="Check">
                                            <StretchTabs
                                                label="Check cleared"
                                                options={['Not cleared', 'Cleared']}
                                                value={data.check_cleared ? 'Cleared' : 'Not cleared'}
                                                onChange={(v) => setData('check_cleared', v === 'Cleared')}
                                            />
                                        </Field>
                                    </>
                                )}
                                {date('or_date', 'OR date')}
                                {text('or_number', 'OR #')}
                            </div>
                        </section>
                    </div>

                    <DialogFooter className="flex-row flex-wrap">
                        {editing && canDelete && (
                            <Button
                                type="button"
                                variant={confirming ? 'destructive' : 'ghost'}
                                className="mr-auto"
                                startIcon={<Trash className="size-4" />}
                                onClick={() => (confirming ? form.delete(route('records.destroy', editing.id), done) : setConfirming(true))}
                            >
                                {confirming ? 'Yes, delete it' : 'Delete'}
                            </Button>
                        )}
                        <Button type="button" variant="outline" onClick={() => (reviewing ? setReviewing(false) : onOpenChange(false))}>
                            {reviewing ? 'Back' : 'Cancel'}
                        </Button>
                        <Button type="submit" loading={processing}>
                            {editing ? 'Save changes' : reviewing ? 'Confirm and add' : 'Add record'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
