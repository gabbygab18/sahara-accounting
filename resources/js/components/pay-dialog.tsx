import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/glint/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/glint/dialog';
import { Input } from '@/components/glint/input';
import { StretchTabs } from '@/components/glint/stretch-tabs';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { isoDate, peso, type AccountingRecord } from '@/lib/records';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

type Row = { id: number; check_number: string; check_date: string; or_number: string };

const label = (r: AccountingRecord) => (r.si_number ? `SI ${r.si_number}` : r.po_number ? `PO ${r.po_number}` : 'Record');

/**
 * "Mark paid" from the list: only the check (or cash) and the OR, the rest of the record stays as it is.
 * Several ticked records share how they were paid and the OR date; each has its own check #, date and OR #.
 */
export function PayDialog({ records, onClose }: { records: AccountingRecord[]; onClose: () => void }) {
    const record = records.length === 1 ? records[0] : null;
    const canConfirm = useCan('confirm');
    const form = useForm({ cash: false as boolean, check_number: '', check_date: '', or_date: '', or_number: '', rows: [] as Row[] });
    const { data, setData, errors, processing } = form;
    const err = errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!records.length) return;
        form.clearErrors();
        form.setData({
            cash: record?.cash ?? false,
            check_number: record?.check_number ?? '',
            check_date: record?.check_date ?? '',
            or_date: record?.or_date ?? isoDate(new Date()),
            or_number: record?.or_number ?? '',
            rows: records.map((r) => ({
                id: Number(r.id),
                check_number: r.check_number ?? '',
                check_date: r.check_date ?? '',
                or_number: r.or_number ?? '',
            })),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [records.map((r) => r.id).join()]);

    const setRow = (i: number, patch: Partial<Row>) =>
        setData(
            'rows',
            data.rows.map((r, j) => (j === i ? { ...r, ...patch } : r)),
        );

    return (
        <Dialog open={records.length > 0} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className={record ? 'max-w-md' : 'max-w-3xl'}>
                <DialogHeader>
                    <DialogTitle>{record ? 'Mark paid' : `Mark ${records.length} records paid`}</DialogTitle>
                    <DialogDescription>
                        {record
                            ? `${label(record)} · ${record.company} · ${peso(record.amount)}`
                            : `${peso(records.reduce((sum, r) => sum + r.amount, 0))} in all. Each SI can have its own check # and OR #.`}
                        {!canConfirm && ' — the admin double checks it before it counts as paid.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4"
                    autoComplete="off"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.patch(record ? route('records.pay', record.id) : route('records.pay-many'), {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label>Paid by</Label>
                            <StretchTabs
                                label="Paid by"
                                options={['Check', 'Cash']}
                                value={data.cash ? 'Cash' : 'Check'}
                                onChange={(v) => setData('cash', v === 'Cash')}
                            />
                        </div>
                        {!record && (
                            <div className="grid gap-1.5">
                                <Label>OR date</Label>
                                <DatePicker value={data.or_date} onChange={(v) => setData('or_date', v)} clearable />
                                {errors.or_date && <p className="text-destructive text-xs">{errors.or_date}</p>}
                            </div>
                        )}
                    </div>

                    {record ? (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {!data.cash && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="p-check">Check #</Label>
                                        <Input id="p-check" value={data.check_number} onChange={(e) => setData('check_number', e.target.value)} />
                                        {errors.check_number && <p className="text-destructive text-xs">{errors.check_number}</p>}
                                    </div>
                                )}
                                <div className="grid gap-1.5">
                                    <Label>{data.cash ? 'Cash date (when paid)' : 'Check date (for deposit)'}</Label>
                                    <DatePicker value={data.check_date} onChange={(v) => setData('check_date', v)} clearable />
                                    {errors.check_date && <p className="text-destructive text-xs">{errors.check_date}</p>}
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label>OR date</Label>
                                    <DatePicker value={data.or_date} onChange={(v) => setData('or_date', v)} clearable />
                                    {errors.or_date && <p className="text-destructive text-xs">{errors.or_date}</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="p-or">OR #</Label>
                                    <Input id="p-or" value={data.or_number} onChange={(e) => setData('or_number', e.target.value)} />
                                    {errors.or_number && <p className="text-destructive text-xs">{errors.or_number}</p>}
                                </div>
                            </div>
                        </>
                    ) : (
                        <div className="grid gap-2">
                            <div className="text-muted-foreground hidden grid-cols-[8rem_repeat(3,minmax(0,1fr))] gap-2 text-xs font-medium sm:grid">
                                <span>SI</span>
                                <span>{data.cash ? '' : 'Check #'}</span>
                                <span>{data.cash ? 'Cash date' : 'Check date'}</span>
                                <span>OR #</span>
                            </div>
                            <div className="grid max-h-[50vh] gap-3 overflow-y-auto sm:gap-2">
                                {data.rows.map((row, i) => {
                                    const r = records[i];
                                    if (!r) return null;
                                    return (
                                        <div key={row.id} className="grid grid-cols-2 items-start gap-2 sm:grid-cols-[8rem_repeat(3,minmax(0,1fr))]">
                                            <div className="col-span-2 text-sm sm:col-span-1 sm:pt-2">
                                                <span className="block font-medium">{label(r)}</span>
                                                <span className="text-muted-foreground text-xs tabular-nums">{peso(r.amount)}</span>
                                            </div>
                                            {data.cash ? (
                                                <span className="hidden sm:block" />
                                            ) : (
                                                <Input
                                                    aria-label={`Check # for ${label(r)}`}
                                                    placeholder="Check #"
                                                    className="grid-cols-1"
                                                    value={row.check_number}
                                                    onChange={(e) => setRow(i, { check_number: e.target.value })}
                                                />
                                            )}
                                            <DatePicker value={row.check_date} onChange={(v) => setRow(i, { check_date: v })} clearable />
                                            <Input
                                                aria-label={`OR # for ${label(r)}`}
                                                placeholder="OR #"
                                                className="grid-cols-1"
                                                value={row.or_number}
                                                onChange={(e) => setRow(i, { or_number: e.target.value })}
                                            />
                                            {(err[`rows.${i}.check_number`] || err[`rows.${i}.check_date`] || err[`rows.${i}.or_number`]) && (
                                                <p className="text-destructive col-span-full text-xs">
                                                    {err[`rows.${i}.check_number`] ?? err[`rows.${i}.check_date`] ?? err[`rows.${i}.or_number`]}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                            {data.rows.length > 1 && (
                                <button
                                    type="button"
                                    className="text-primary justify-self-start text-xs font-medium hover:underline"
                                    onClick={() => {
                                        const [first] = data.rows;
                                        setData(
                                            'rows',
                                            data.rows.map((r) => ({
                                                ...r,
                                                check_number: first.check_number,
                                                check_date: first.check_date,
                                                or_number: first.or_number,
                                            })),
                                        );
                                    }}
                                >
                                    Same as the first for all
                                </button>
                            )}
                        </div>
                    )}

                    <DialogFooter className="flex-row flex-wrap">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            Save as paid
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
