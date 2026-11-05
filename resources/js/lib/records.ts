export interface DrLine {
    date: string | null;
    number: string | null;
    /** Not delivered, Partial, Delivered */
    status: string | null;
}

export interface SiLine {
    date: string | null;
    number: string | null;
    amount: number | null;
}

export interface AccountingRecord {
    id: number;
    /** The group (main company, e.g. Cityland). Its tab in the Google Sheet. */
    company: string;
    /** "Sold to" on the invoice. */
    sold_to: string | null;
    project: string | null;
    tin: string | null;
    po_date: string | null;
    po_number: string | null;
    drs: DrLine[];
    sis: SiLine[];
    /** From the lines: first DR date, DR numbers joined, overall delivery status. */
    /** 30 = 30 days, 0 = COD. */
    terms_days: number | null;
    dr_date: string | null;
    dr_number: string | null;
    delivery_status: string;
    si_date: string | null;
    si_number: string | null;
    /** Total of the SI amounts. */
    or_date: string | null;
    or_number: string | null;
    amount: number;
    paid: boolean;
    /** Double checked by the admin. Paid but not confirmed = for checking. */
    paid_confirmed: boolean;
    check_number: string | null;
    /** When the check can be deposited. */
    check_date: string | null;
    check_cleared: boolean;
    /** SI date plus terms (list page only). */
    due?: string | null;
    overdue?: boolean;
}

export interface Company {
    name: string;
    tin: string | null;
    projects: string[];
    sold_to: string[];
}

export interface RecordOptions {
    companies: Company[];
    delivery: string[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export const fmt = (n: number) => n.toLocaleString('en-US', { maximumFractionDigits: 2 });

export const peso = (n: number) => '₱' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const terms = (days: number | null) => (days === null ? '—' : days === 0 ? 'COD' : `${days} days`);

/** Local YYYY-MM-DD (toISOString would shift the day in UTC+8). */
export const isoDate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** A typed date as YYYY-MM-DD, or null: 10/16/2024, 10-16-24, 10162024, 2024-10-16, Oct 16 2024 (month first). */
export const typedDate = (text: string): string | null => {
    const t = text.trim();
    let m: RegExpMatchArray | null;
    let [y, mo, d] = [0, 0, 0];
    if ((m = t.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/))) [y, mo, d] = [+m[1], +m[2], +m[3]];
    else if ((m = t.match(/^(\d{1,2})[-/. ](\d{1,2})[-/. ](\d{2}|\d{4})$/) ?? t.match(/^(\d{2})(\d{2})(\d{4})$/))) [mo, d, y] = [+m[1], +m[2], +m[3]];
    else if (/[a-z]/i.test(t) && !isNaN(Date.parse(t))) return isoDate(new Date(t));
    else return null;
    if (y < 100) y += 2000;
    const date = new Date(y, mo - 1, d);
    return date.getMonth() === mo - 1 && date.getDate() === d ? isoDate(date) : null;
};

export const parseDate = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};

export const prettyDate = (s: string | null) =>
    s ? parseDate(s.slice(0, 10)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
