import { Input, type InputProps } from '@/components/glint/input';
import { ChevronDown } from '@/icons';
import { cn } from '@/lib/utils';
import { useId, useRef, useState } from 'react';

/** A text field with a dropdown of the known values: all of them on focus, fewer as you type. A new value is still allowed. */
export function ComboInput({
    options,
    value,
    onValueChange,
    onFocus,
    onBlur,
    ...props
}: Omit<InputProps, 'value' | 'onChange'> & {
    options: string[];
    value: string;
    /** `picked`: chosen from the list rather than typed. */
    onValueChange: (value: string, picked?: boolean) => void;
}) {
    const listId = useId();
    const input = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const q = value.trim().toLowerCase();
    // Once a known value is picked, the whole list again, so it can be swapped for another.
    const shown = !q || options.some((o) => o.toLowerCase() === q) ? options : options.filter((o) => o.toLowerCase().includes(q));
    const expanded = open && shown.length > 0;

    const pick = (o: string) => {
        onValueChange(o, true);
        setOpen(false);
        setActive(-1);
    };
    const move = (i: number) => {
        setOpen(true);
        setActive(i);
        document.getElementById(`${listId}-${i}`)?.scrollIntoView({ block: 'nearest' });
    };

    return (
        <div className="relative">
            <Input
                {...props}
                ref={input}
                value={value}
                role="combobox"
                autoComplete="off"
                aria-autocomplete="list"
                aria-expanded={expanded}
                aria-controls={listId}
                aria-activedescendant={expanded && active >= 0 ? `${listId}-${active}` : undefined}
                onChange={(e) => {
                    onValueChange(e.target.value);
                    setOpen(true);
                    setActive(-1);
                }}
                onFocus={(e) => {
                    setOpen(true);
                    onFocus?.(e);
                }}
                onBlur={(e) => {
                    setOpen(false);
                    setActive(-1);
                    onBlur?.(e);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        move(Math.min(active + 1, shown.length - 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        move(Math.max(active - 1, 0));
                    } else if (e.key === 'Enter' && expanded && active >= 0) {
                        e.preventDefault();
                        pick(shown[active]);
                    } else if (e.key === 'Escape' && expanded) {
                        setOpen(false);
                    }
                }}
                endIcon={
                    <button
                        type="button"
                        tabIndex={-1}
                        aria-label="Show the list"
                        className="hover:text-foreground -mr-1 p-1"
                        // Keep the focus in the field.
                        onMouseDown={(e) => {
                            e.preventDefault();
                            input.current?.focus();
                            setOpen(!open);
                        }}
                    >
                        <ChevronDown className={cn('transition-transform', expanded && 'rotate-180')} />
                    </button>
                }
            />
            {expanded && (
                <ul
                    id={listId}
                    role="listbox"
                    className="bg-popover text-popover-foreground absolute inset-x-0 top-full z-50 mt-1 max-h-56 overflow-y-auto rounded-lg border p-1 shadow-lg"
                >
                    {shown.map((o, i) => (
                        <li
                            key={o}
                            id={`${listId}-${i}`}
                            role="option"
                            aria-selected={o.toLowerCase() === q}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(o);
                            }}
                            className={cn(
                                'hover:bg-muted cursor-pointer truncate rounded-md px-2 py-1.5 text-sm',
                                i === active && 'bg-muted',
                                o.toLowerCase() === q && 'font-semibold',
                            )}
                        >
                            {o}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
