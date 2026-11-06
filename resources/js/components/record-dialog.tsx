import { ComboInput } from '@/components/combo-input';
import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/glint/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/glint/dialog';
import { Input } from '@/components/glint/input';
import { Select } from '@/components/glint/select';
import { StretchTabs } from '@/components/glint/stretch-tabs';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { Plus, Trash, X } from '@/icons';
import { peso, type AccountingRecord, type RecordOptions } from '@/lib/records';
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
    const form = useForm<Form>(blank);
    const { data, setData, errors, processing, transform } = form;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(editing ? toForm(editing) : blank);
        setConfirming(false);
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
        else form.post(route('records.store'), done);
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
                    <DialogTitle>{editing ? 'Edit record' : 'Add record'}</DialogTitle>
                    <DialogDescription>Copy it from the PO, DRs and sales invoices. Only the group is required.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-5">
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
                        <p className="text-right text-sm">
                            <span className="text-muted-foreground">Total </span>
                            <b className="tabular-nums">{peso(data.sis.reduce((sum, l) => sum + money(l.amount), 0))}</b>
                        </p>
                    </section>

                    <section className="grid gap-3 border-t pt-4 sm:grid-cols-2">
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
                        {date('or_date', 'OR date')}
                        {text('or_number', 'OR #')}
                    </section>

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
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            {editing ? 'Save changes' : 'Add record'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
