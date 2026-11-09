<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * One PO's paperwork: group (main company, e.g. Cityland), the company it was sold to, project, PO, its DRs (each
 * with a date and delivery status), its SIs (each with a date and amount), the payment (by check or in cash) and OR.
 * Paid in cash, check_date holds the day the cash came in and there is no check number or clearing.
 *
 * The DR and SI lines live in `drs` / `sis`; setting them also fills the single columns used for search, filters and
 * due dates (first date, numbers joined, overall delivery status, total amount). In the master Google Sheet each
 * group has its own tab; a record's row there has the other COLUMNS (from B on, shifted to A), one line per DR / SI
 * inside the DR and SI cells, and its SYNC ID in column Z.
 */
class Record extends Model
{
    use SoftDeletes;

    /** Column => header in the Google Sheet and in exports, in column order (A, B, C…). */
    public const COLUMNS = [
        'company' => 'GROUP',
        'sold_to' => 'COMPANY (SOLD TO)',
        'project' => 'PROJECT',
        'tin' => 'TIN',
        'po_date' => 'PO DATE',
        'po_number' => 'PO #',
        'dr_date' => 'DR DATE',
        'dr_number' => 'DR #',
        'delivery_status' => 'DELIVERY STATUS',
        'si_date' => 'SI DATE',
        'si_number' => 'SI #',
        'si_amount' => 'SI AMOUNT',
        'terms_days' => 'TERMS (DAYS)',
        'amount' => 'TOTAL AMOUNT',
        'paid' => 'PAYMENT STATUS',
        'cash' => 'PAID BY',
        'check_number' => 'CHECK #',
        'check_date' => 'CHECK / CASH DATE',
        'check_cleared' => 'CHECK CLEARED',
        'or_date' => 'OR DATE',
        'or_number' => 'OR #',
        'booklet_number' => 'BOOKLET #',
        'envelope_number' => 'ENVELOPE #',
    ];

    /** Columns holding one line per DR / SI: column => [lines, key in a line]. */
    private const LINES = [
        'dr_date' => ['drs', 'date'], 'dr_number' => ['drs', 'number'], 'delivery_status' => ['drs', 'status'],
        'si_date' => ['sis', 'date'], 'si_number' => ['sis', 'number'], 'si_amount' => ['sis', 'amount'],
    ];

    public const DELIVERY = ['Not delivered', 'Partial', 'Delivered'];

    /** Check cleared as written in the sheet, with its colour there. */
    public const CLEARED = ['Not cleared' => '#fce8b2', 'Cleared' => '#b7e1cd'];

    /** How it was paid, as written in the sheet. */
    public const PAID_BY = ['Check', 'Cash'];

    /** Payment status as written in the sheet, with its colour there. */
    public const PAYMENT = ['Unpaid' => '#f4c7c3', 'For checking' => '#fce8b2', 'Paid' => '#b7e1cd', 'Cancelled' => '#e0e0e0'];

    public const DATES = ['po_date', 'dr_date', 'si_date', 'check_date', 'or_date'];

    /** Stored fields (the activity log compares these). The DR / SI ones are filled from the lines. */
    public const FIELDS = ['company', 'sold_to', 'project', 'tin', 'po_date', 'po_number', 'dr_date', 'dr_number', 'delivery_status', 'si_date', 'si_number', 'terms_days', 'amount', 'paid', 'cash', 'check_number', 'check_date', 'check_cleared', 'or_date', 'or_number', 'booklet_number', 'envelope_number', 'si_cancelled'];

    protected $fillable = [...self::FIELDS, 'si_missing', 'wrong_computation', 'drs', 'sis', 'paid_confirmed', 'user_id', 'synced_at', 'pending_sync'];

    protected $hidden = ['synced_at', 'pending_sync', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'po_date' => 'date:Y-m-d',
            'dr_date' => 'date:Y-m-d',
            'si_date' => 'date:Y-m-d',
            'or_date' => 'date:Y-m-d',
            'check_date' => 'date:Y-m-d',
            'amount' => 'float',
            'paid' => 'boolean',
            'paid_confirmed' => 'boolean',
            'check_cleared' => 'boolean',
            'cash' => 'boolean',
            'si_missing' => 'boolean',
            'si_cancelled' => 'boolean',
            'wrong_computation' => 'boolean',
            'terms_days' => 'integer',
            'synced_at' => 'datetime',
            'pending_sync' => 'boolean',
        ];
    }

    /** DR lines [{date, number, status}]. Setting them fills dr_date (first), dr_number (joined) and delivery_status (overall). */
    protected function drs(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => json_decode($value ?? '[]', true),
            set: function ($value) {
                $lines = self::lines($value, ['date', 'number', 'status']);
                $statuses = array_values(array_unique(array_filter(array_column($lines, 'status'))));

                return [
                    'drs' => json_encode($lines),
                    'dr_date' => collect($lines)->pluck('date')->filter()->min(),
                    'dr_number' => implode(', ', array_filter(array_column($lines, 'number'))) ?: null,
                    'delivery_status' => match (true) {
                        ! $statuses => '',
                        count($statuses) === 1 => $statuses[0],
                        (bool) array_intersect($statuses, ['Delivered', 'Partial']) => 'Partial',
                        default => $statuses[0],
                    },
                ];
            },
        );
    }

    /** SI lines [{date, number, amount}]. Setting them fills si_date (first), si_number (joined) and amount (total). */
    protected function sis(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => json_decode($value ?? '[]', true),
            set: function ($value) {
                $lines = array_map(fn ($l) => [...$l, 'amount' => $l['amount'] === null ? null : round((float) $l['amount'], 2)], self::lines($value, ['date', 'number', 'amount']));

                return [
                    'sis' => json_encode($lines),
                    'si_date' => collect($lines)->pluck('date')->filter()->min(),
                    'si_number' => implode(', ', array_filter(array_column($lines, 'number'))) ?: null,
                    'amount' => round(array_sum(array_column($lines, 'amount')), 2),
                ];
            },
        );
    }

    /** Lines with the given keys, values trimmed (blank = null), empty lines dropped. */
    private static function lines(mixed $value, array $keys): array
    {
        $lines = is_string($value) ? json_decode($value, true) : $value;

        return collect($lines ?: [])
            ->map(fn ($l) => array_combine($keys, array_map(fn ($k) => is_string($v = $l[$k] ?? null) ? (trim($v) === '' ? null : trim($v)) : $v, $keys)))
            ->filter(fn ($l) => array_filter($l, fn ($v) => $v !== null))
            ->values()->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** First SI date plus the terms; null without both. */
    public function dueOn(): ?Carbon
    {
        return $this->si_date && $this->terms_days !== null ? $this->si_date->copy()->addDays($this->terms_days) : null;
    }

    /** unpaid, checking (marked paid, waiting for the admin to confirm) or paid. */
    public function payment(): string
    {
        return ! $this->paid ? 'unpaid' : ($this->paid_confirmed ? 'paid' : 'checking');
    }

    /**
     * Unpaid invoices by how late: key => [label, first day late, last day late]. "no-due" has no SI date or terms,
     * so no due date: those can't be chased until filled in.
     */
    public const AGING = [
        'not-due' => ['Not yet due', PHP_INT_MIN, 0],
        '1-30' => ['1–30 days late', 1, 30],
        '31-60' => ['31–60 days late', 31, 60],
        '61-90' => ['61–90 days late', 61, 90],
        '90+' => ['Over 90 days late', 91, PHP_INT_MAX],
        'no-due' => ['No due date', null, null],
    ];

    /** Days past the due date: negative while there is time left, null without an SI date and terms. */
    public function daysLate(): ?int
    {
        return ($due = $this->dueOn()) ? (int) $due->diffInDays(today(), false) : null;
    }

    /** Its AGING key while unpaid, else null. */
    public function age(): ?string
    {
        if ($this->paid || $this->si_cancelled) {
            return null;
        }
        if (($late = $this->daysLate()) === null) {
            return 'no-due';
        }
        foreach (self::AGING as $key => [, $from, $to]) {
            if ($from !== null && $late >= $from && $late <= $to) {
                return $key;
            }
        }

        return null;
    }

    /**
     * What to recheck, e.g. "Wrong computation, no actual SI and SI date": an amount flagged as computed wrong, the paper
     * SI (marked lost), or, while unpaid, the SI date or terms.
     */
    public function missing(): ?string
    {
        $missing = Arr::join(array_filter([
            $this->si_missing ? 'actual SI' : null,
            ! $this->paid && ! $this->si_cancelled && ! $this->si_date ? 'SI date' : null,
            ! $this->paid && ! $this->si_cancelled && $this->terms_days === null ? 'terms' : null,
        ]), ', ', ' and ');

        return implode(', ', array_filter([$this->wrong_computation ? 'Wrong computation' : null, $missing ? "no {$missing}" : null])) ?: null;
    }

    public function isOverdue(): bool
    {
        return ! $this->paid && ! $this->si_cancelled && ($due = $this->dueOn()) && $due->lt(today());
    }

    /** What the search can be narrowed to: ?in= => column. Booklet and envelope match whole (booklet 3 is not 30). */
    public const SEARCH_IN = ['si' => 'si_number', 'booklet' => 'booklet_number', 'envelope' => 'envelope_number', 'or' => 'or_number', 'check' => 'check_number', 'po' => 'po_number', 'dr' => 'dr_number'];

    public function scopeSearch(Builder $q, string $text, ?string $in = null): void
    {
        $text = trim($text);
        match ($column = self::SEARCH_IN[$in] ?? null) {
            null => $q->where(fn ($q) => $q->whereAny(['company', 'sold_to', 'project', 'tin', 'po_number', 'dr_number', 'si_number', 'or_number', 'check_number', 'booklet_number', 'envelope_number'], 'like', "%{$text}%")),
            'booklet_number', 'envelope_number' => $q->where($column, $text),
            default => $q->where($column, 'like', "%{$text}%"),
        };
    }

    /** "SI 194651 · Cityland Dev Corp / Pines Peak Towers" for the activity log. */
    public function label(): string
    {
        $doc = $this->si_number ? "SI {$this->si_number}" : ($this->dr_number ? "DR {$this->dr_number}" : ($this->po_number ? "PO {$this->po_number}" : "record #{$this->id}"));

        return $doc.' · '.implode(' / ', array_filter([$this->company, $this->sold_to, $this->project]));
    }

    /** Its cells in COLUMNS order. Dates as YYYY-MM-DD so Sheets reads them as dates; several DRs / SIs one per line. */
    public function toRow(): array
    {
        return array_map(function ($column) {
            if ([$lines, $key] = self::LINES[$column] ?? null) {
                $values = array_column($this->{$lines}, $key);
                // Several values share a text cell, so the sheet's ₱ and date formats can't reach them: write them in.
                if ($key === 'amount' && count($values) > 1) {
                    $values = array_map(fn ($v) => $v === null ? '' : '₱'.number_format($v, 2), $values);
                }
                if ($key === 'date' && count($values) > 1) {
                    $values = array_map(fn ($v) => $v === null ? '' : Carbon::parse($v)->format('m/d/Y'), $values);
                }

                return count($values) > 1 ? implode("\n", array_map(fn ($v) => $v ?? '', $values)) : ($values[0] ?? null);
            }

            return match (true) {
                in_array($column, self::DATES, true) => $this->{$column}?->format('Y-m-d'),
                $column === 'paid' => $this->si_cancelled ? 'Cancelled' : ['unpaid' => 'Unpaid', 'checking' => 'For checking', 'paid' => 'Paid'][$this->payment()],
                // Blank until there is a check (or cash) to tell.
                $column === 'cash' => $this->cash ? 'Cash' : ($this->check_number || $this->check_date ? 'Check' : null),
                // Blank until there is a check to clear.
                $column === 'check_cleared' => $this->cash ? null : ($this->check_cleared ? 'Cleared' : ($this->check_number || $this->check_date ? 'Not cleared' : null)),
                $column === 'amount' => (float) $this->amount,
                default => $this->{$column},
            };
        }, array_keys(self::COLUMNS));
    }

    /** Fields from cells in COLUMNS order. Forgiving: "₱1,200.50", "30 days", "COD", "paid", one DR / SI per line. */
    public static function fromRow(array $cells): array
    {
        $out = [];
        $cell = [];
        foreach (array_keys(self::COLUMNS) as $i => $column) {
            $v = $cell[$column] = $cells[$i] ?? null;
            if (isset(self::LINES[$column]) || $column === 'amount') {
                continue;
            }
            $text = $v instanceof \DateTimeInterface ? null : trim((string) $v);
            $out[$column] = match (true) {
                in_array($column, self::DATES, true) => self::date($v),
                $column === 'paid' => (bool) preg_match('/^(paid|yes|y|true|1)\b/i', $text),
                $column === 'check_cleared' => (bool) preg_match('/^(cleared|yes|y|true|1)\b/i', $text),
                $column === 'cash' => (bool) preg_match('/^cash/i', $text),
                $column === 'terms_days' => $text === '' ? null : (preg_match('/\d+/', $text, $m) ? (int) $m[0] : (preg_match('/^(cod|cash)/i', $text) ? 0 : null)),
                $column === 'company' => $text,
                default => $text === '' ? null : $text,
            };
        }
        // "For checking" is paid, not yet confirmed; any other paid word means confirmed.
        $checking = (bool) preg_match('/\b(for|to) check/i', (string) $cell['paid']);
        $out['paid'] = $out['paid'] || $checking;
        $out['si_cancelled'] = (bool) preg_match('/^cancel/i', trim((string) $cell['paid']));
        $out['paid_confirmed'] = $out['paid'] && ! $checking;

        $text = fn ($v) => ($t = trim((string) $v)) === '' ? null : $t;
        $amount = fn ($v) => ($t = preg_replace('/[^\d.\-]/', '', (string) $v)) === '' ? null : (float) $t;
        $out['drs'] = self::zip($cell, ['date' => 'dr_date', 'number' => 'dr_number', 'status' => 'delivery_status'], [
            'date' => fn ($v) => self::date($v),
            'number' => $text,
            'status' => fn ($v) => ($t = trim((string) $v)) === '' ? null : self::delivery($t),
        ]);
        $out['sis'] = self::zip($cell, ['date' => 'si_date', 'number' => 'si_number', 'amount' => 'si_amount'], [
            'date' => fn ($v) => self::date($v),
            'number' => $text,
            'amount' => $amount,
        ]);
        // Only a total (an old tracker, or typed in TOTAL AMOUNT): it becomes the amount of the one SI.
        if (! array_filter(array_column($out['sis'], 'amount')) && ($total = $amount($cell['amount'])) !== null) {
            $out['sis'] = [['date' => $out['sis'][0]['date'] ?? null, 'number' => $out['sis'][0]['number'] ?? null, 'amount' => $total], ...array_slice($out['sis'], 1)];
        }

        return $out;
    }

    /** Lines from cells holding one value per line, read with $parse. */
    private static function zip(array $cell, array $columns, array $parse): array
    {
        $split = array_map(fn ($c) => is_string($cell[$c]) ? preg_split('/\r?\n/', $cell[$c]) : [$cell[$c]], $columns);
        $lines = [];
        for ($i = 0; $i < max(array_map('count', $split)); $i++) {
            $line = array_map(fn ($key) => $parse[$key]($split[$key][$i] ?? null), array_combine(array_keys($columns), array_keys($columns)));
            if (array_filter($line, fn ($v) => $v !== null)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** True when every cell is blank. */
    public static function blankRow(array $cells): bool
    {
        foreach (array_slice($cells, 0, count(self::COLUMNS)) as $v) {
            if ($v instanceof \DateTimeInterface || trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function delivery(string $text): string
    {
        if (preg_match('/^(pending|no\b|undelivered)/i', $text)) {
            return 'Not delivered';
        }
        foreach (self::DELIVERY as $d) {
            if (stripos($d, $text) === 0 || stripos($text, $d) === 0) {
                return $d;
            }
        }

        return mb_substr($text, 0, 50);
    }

    private static function date(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_numeric($v) && $v > 20000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->format('Y-m-d'); // spreadsheet serial date
        }
        $text = trim((string) $v);
        try {
            return $text === '' ? null : Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
