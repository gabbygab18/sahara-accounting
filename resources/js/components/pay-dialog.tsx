import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/glint/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/glint/dialog';
import { Input } from '@/components/glint/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import { isoDate, peso, type AccountingRecord } from '@/lib/records';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** "Mark paid" from the list: only the check and the OR, the rest of the record stays as it is. */
export function PayDialog({ record, onClose }: { record: AccountingRecord | null; onClose: () => void }) {
    const canConfirm = useCan('confirm');
    const form = useForm({ check_number: '', check_date: '', or_date: '', or_number: '' });
    const { data, setData, errors, processing } = form;

    useEffect(() => {
        if (!record) return;
        form.clearErrors();
        form.setData({
            check_number: record.check_number ?? '',
            check_date: record.check_date ?? '',
            or_date: record.or_date ?? isoDate(new Date()),
            or_number: record.or_number ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [record?.id]);

    return (
        <Dialog open={!!record} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Mark paid</DialogTitle>
                    <DialogDescription>
                        {record &&
                            `${record.si_number ? `SI ${record.si_number}` : record.po_number ? `PO ${record.po_number}` : 'Record'} · ${record.company} · ${peso(record.amount)}`}
                        {!canConfirm && ' — the admin double checks it before it counts as paid.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (record) form.patch(route('records.pay', record.id), { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="p-check">Check #</Label>
                            <Input id="p-check" autoFocus value={data.check_number} onChange={(e) => setData('check_number', e.target.value)} />
                            {errors.check_number && <p className="text-destructive text-xs">{errors.check_number}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Check date (for deposit)</Label>
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
