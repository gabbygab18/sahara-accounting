import { Calendar } from '@/components/glint/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/glint/popover';
import { Calendar as CalendarIcon, X } from '@/icons';
import { isoDate, parseDate, typedDate } from '@/lib/records';
import { cn } from '@/lib/utils';
import { useEffect, useState } from 'react';

const shown = (value: string) => (value ? parseDate(value).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' }) : '');

/** Value is YYYY-MM-DD or '' (no date). Type it (10/16/2024, 2024-10-16, Oct 16 2024) or pick it from the calendar. */
export function DatePicker({
    value,
    onChange,
    placeholder = 'MM/DD/YYYY',
    clearable = false,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    clearable?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState(shown(value));
    useEffect(() => setText(shown(value)), [value]);

    // A date that can't be read goes back to what was there.
    const commit = () => {
        const date = text.trim() === '' ? '' : typedDate(text);
        if (date === null) setText(shown(value));
        else if (date !== value) onChange(date);
        else setText(shown(value));
    };

    return (
        <div className={cn('relative', className)}>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger
                    aria-label="Pick from the calendar"
                    // Just the icon inside the field: none of the trigger's own button border, fill, shadow or height.
                    className="text-muted-foreground hover:text-foreground hover:bg-muted aria-expanded:bg-muted absolute top-1/2 left-1.5 size-7 -translate-y-1/2 rounded-lg border-0 bg-transparent p-0 shadow-none"
                >
                    <CalendarIcon className="size-4" />
                </PopoverTrigger>
                <PopoverContent align="start" className="p-2">
                    <Calendar
                        selected={value ? parseDate(value) : null}
                        onSelect={(d) => {
                            onChange(isoDate(d));
                            setOpen(false);
                        }}
                    />
                </PopoverContent>
            </Popover>
            <input
                inputMode="numeric"
                placeholder={placeholder}
                value={text}
                onChange={(e) => setText(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        commit();
                    }
                }}
                className="border-input bg-background focus-visible:ring-ring/40 h-10 w-full rounded-xl border pr-8 pl-9 text-sm outline-none focus-visible:ring-2"
            />
            {clearable && value && (
                <button
                    type="button"
                    aria-label="Clear date"
                    onClick={() => onChange('')}
                    className="text-muted-foreground hover:text-foreground absolute top-1/2 right-2 -translate-y-1/2 rounded p-1"
                >
                    <X className="size-3.5" />
                </button>
            )}
        </div>
    );
}
