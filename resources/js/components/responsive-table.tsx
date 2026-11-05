import { Table, type TableColumn, type TableProps } from '@/components/glint/table';

const value = <T,>(c: TableColumn<T>, row: T) => (c.cell ? c.cell(row) : String(row[c.key] ?? ''));

/**
 * Glint table when its container is wide enough, stacked cards when it is not.
 * Uses a container query, so a tablet with the sidebar open also gets cards.
 * The first column becomes each card's title.
 */
export function ResponsiveTable<T extends { id: string | number }>(props: TableProps<T>) {
    const [first, ...rest] = props.columns;

    return (
        <div className="@container">
            <div className="hidden @3xl:block">
                <Table {...props} />
            </div>
            <ul className="grid gap-3 @lg:grid-cols-2 @3xl:hidden">
                {props.rows.length === 0 && <li className="text-muted-foreground py-10 text-center text-sm @lg:col-span-2">{props.empty}</li>}
                {props.rows.map((row) => (
                    <li key={row.id} className="bg-card rounded-2xl border p-4">
                        <div className="mb-3 min-w-0 text-sm">{value(first, row)}</div>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm">
                            {rest.map((c) => (
                                <div key={c.key} className="min-w-0">
                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">{c.header}</dt>
                                    <dd className="mt-0.5 truncate">{value(c, row)}</dd>
                                </div>
                            ))}
                        </dl>
                    </li>
                ))}
            </ul>
        </div>
    );
}
