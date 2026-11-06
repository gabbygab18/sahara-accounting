<?php

namespace App\Http\Controllers;

use App\Models\Record;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What needs doing: money to collect (by how late, by company), payments to confirm, checks not yet cleared, deliveries not
 * yet invoiced, invoices missing their due date, skipped SI / DR numbers; billed vs collected per month; paid / unpaid
 * overall and per group. Filtered by group and date.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->can('dashboard')) {
            return redirect($request->user()->home());
        }
        $filters = $request->validate(['company' => ['nullable', 'string'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        // ponytail: every record loaded once and summed in PHP (due dates differ per SQL dialect); fine into the tens of thousands
        $records = Record::query()
            ->where('si_cancelled', false) // a cancelled SI is no sale
            ->when($filters['company'] ?? null, fn ($q, $c) => $q->where('company', $c))
            // Dated by the invoice, or by the DR while there is no invoice yet (as in Records).
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereRaw('COALESCE(si_date, dr_date) >= ?', [$d]))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereRaw('COALESCE(si_date, dr_date) <= ?', [$d]))
            ->get(['id', 'company', 'sold_to', 'project', 'si_number', 'dr_number', 'dr_date', 'po_number', 'si_date', 'terms_days', 'amount', 'paid', 'paid_confirmed', 'cash', 'si_missing', 'check_number', 'check_date', 'check_cleared', 'or_date']);
        $sum = fn ($rows) => [
            'count' => $rows->count(),
            'paid' => round($rows->filter(fn ($r) => $r->payment() === 'paid')->sum('amount'), 2),
            'checking' => round($rows->filter(fn ($r) => $r->payment() === 'checking')->sum('amount'), 2),
            'unpaid' => round($rows->where('paid', false)->sum('amount'), 2),
            'overdue' => round($rows->filter->isOverdue()->sum('amount'), 2),
        ];
        $brief = fn (Record $r) => $r->only('id', 'company', 'sold_to', 'project', 'si_number', 'dr_number', 'po_number', 'amount');
        $unpaid = $records->where('paid', false);
        $paidOn = fn (Record $r) => $r->or_date ?? $r->check_date; // the OR, else the check (or the cash)
        $uncleared = $records->filter(fn ($r) => $r->paid && ! $r->cash && ! $r->check_cleared && ($r->check_number || $r->check_date));
        $notInvoiced = $records->filter(fn ($r) => $r->dr_number && ! $r->si_number);
        $noDue = $records->filter->missing(); // the actual SI lost, or unpaid without an SI date or terms

        return Inertia::render('dashboard', [
            'filters' => array_filter($filters),
            'groups' => Record::where('company', '!=', '')->distinct()->orderBy('company')->pluck('company'),
            'totals' => $sum($records),
            'month' => [
                'billed' => round($records->filter(fn ($r) => $r->si_date?->isSameMonth(today()))->sum('amount'), 2),
                'collected' => round($records->filter(fn ($r) => $r->paid && $paidOn($r)?->isSameMonth(today()))->sum('amount'), 2),
            ],
            'checkingCount' => $records->filter(fn ($r) => $r->payment() === 'checking')->count(),
            'aging' => collect(Record::AGING)->map(function ($bucket, $key) use ($unpaid, $brief) {
                $rows = $unpaid->filter(fn ($r) => $r->age() === $key);

                return [
                    'key' => $key, 'label' => $bucket[0], 'count' => $rows->count(), 'amount' => round($rows->sum('amount'), 2),
                    // The biggest few, previewed on hover.
                    'top' => $rows->sortByDesc('amount')->take(5)->map(fn (Record $r) => [...$brief($r), 'late' => $r->daysLate()])->values(),
                ];
            })->values(),
            // Every unpaid invoice, per group: the group with the most invoices first; in each, the most overdue first.
            'collect' => $unpaid->groupBy('company')->map(fn ($rows, $group) => [
                'group' => (string) $group,
                'count' => $rows->count(),
                'amount' => round($rows->sum('amount'), 2),
                'overdue' => round($rows->filter->isOverdue()->sum('amount'), 2),
                // ponytail: the first 50 in the card; the rest are a click away in Records
                'rows' => $rows->sortBy(fn ($r) => [$r->dueOn() === null, $r->dueOn()?->timestamp])->take(50)
                    ->map(fn (Record $r) => [...$brief($r), 'due' => $r->dueOn()?->toDateString(), 'late' => $r->daysLate()])->values(),
            ])->sortBy([['count', 'desc'], ['amount', 'desc']])->values(),
            'debtors' => self::debtors($records, $paidOn),
            'trend' => self::trend($records, $paidOn, $filters),
            'notInvoiced' => [
                'count' => $notInvoiced->count(),
                'rows' => $notInvoiced->sortBy('dr_date')->take(15)
                    ->map(fn (Record $r) => [...$brief($r), 'dr_date' => $r->dr_date?->toDateString(), 'days' => $r->dr_date ? (int) $r->dr_date->diffInDays(today()) : null])->values(),
            ],
            'noDue' => [
                'count' => $noDue->count(),
                'rows' => $noDue->sortByDesc('id')->take(15)
                    ->map(fn (Record $r) => [...$brief($r), 'missing' => $r->missing()])->values(),
            ],
            'uncleared' => [
                'count' => $uncleared->count(),
                'amount' => round($uncleared->sum('amount'), 2),
                'rows' => $uncleared->sortBy(fn ($r) => $r->check_date ?? $r->or_date)->take(15)
                    ->map(fn (Record $r) => [...$brief($r), ...$r->only('check_number'), 'check_date' => $r->check_date?->toDateString()])->values(),
            ],
            // SI / DR numbers run across every group and date, so these ignore the filters.
            'skipped' => [
                'si' => self::skipped($all = Record::get(['si_number', 'dr_number']), 'si_number', Setting::get('booklet.si')),
                'dr' => self::skipped($all, 'dr_number', Setting::get('booklet.dr')),
            ],
            'companies' => $records->groupBy('company')->map(fn ($rows, $company) => ['company' => $company, ...$sum($rows)])
                ->sortByDesc('unpaid')->values(),
        ]);
    }

    /**
     * The 10 companies (sold to; the group when blank) owing the most, with how many days they take to pay on average
     * (SI date to OR / check date, over their paid invoices).
     */
    private static function debtors(Collection $records, \Closure $paidOn): Collection
    {
        return $records->groupBy(fn ($r) => trim($r->sold_to ?: $r->company) ?: '(no company)')
            ->map(function ($rows, $name) use ($paidOn) {
                $unpaid = $rows->where('paid', false);
                $days = $rows->filter(fn ($r) => $r->paid && $r->si_date && $paidOn($r))->map(fn ($r) => $r->si_date->diffInDays($paidOn($r), false));

                return [
                    'name' => $name,
                    'group' => $rows->first()->company,
                    'unpaid' => round($unpaid->sum('amount'), 2),
                    'overdue' => round($unpaid->filter->isOverdue()->sum('amount'), 2),
                    'count' => $unpaid->count(),
                    'days' => $days->isEmpty() ? null : (int) round($days->avg()),
                ];
            })
            ->filter(fn ($c) => $c['unpaid'] > 0)->sortByDesc('unpaid')->take(10)->values();
    }

    /**
     * Billed (by SI date) and collected (by OR / check date) per month: the last 12 months, or the months of the
     * date filter (at most 24, the latest).
     */
    private static function trend(Collection $records, \Closure $paidOn, array $filters): array
    {
        $last = isset($filters['to']) ? Carbon::parse($filters['to'])->startOfMonth() : today()->startOfMonth();
        $first = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfMonth() : $last->copy()->subMonths(11);
        $first = $first->max($last->copy()->subMonths(23))->min($last);
        $months = [];
        for ($m = $first->copy(); $m->lte($last); $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'billed' => 0.0, 'collected' => 0.0];
        }
        foreach ($records as $r) {
            if (isset($months[$key = $r->si_date?->format('Y-m')])) {
                $months[$key]['billed'] += $r->amount;
            }
            if ($r->paid && isset($months[$key = $paidOn($r)?->format('Y-m')])) {
                $months[$key]['collected'] += $r->amount;
            }
        }

        return array_values(array_map(fn ($m) => [...$m, 'billed' => round($m['billed'], 2), 'collected' => round($m['collected'], 2)], $months));
    }

    /** The first SI # / DR # of the booklet: numbers from there on are watched for skips (older ones are backlog). */
    public function booklet(Request $request): RedirectResponse
    {
        $data = $request->validate(['si' => ['nullable', 'integer', 'min:0'], 'dr' => ['nullable', 'integer', 'min:0']]);
        foreach (['si', 'dr'] as $doc) {
            if ($request->has($doc)) {
                Setting::set("booklet.{$doc}", $data[$doc] === null ? null : (string) $data[$doc]);
            }
        }

        return back()->with('success', 'Saved.');
    }

    /**
     * Numbers missing from $from up to the highest one entered: receipts skipped, cancelled or not yet entered. Numbers
     * are all digits; a record may hold several ("185561, 185562"). Null until the booklet's first number is set.
     *
     * @return array{from: int, missing: int, ranges: list<array{from: int, to: int}>}|null
     */
    public static function skipped(Collection $records, string $column, ?string $from): ?array
    {
        if ($from === null || $from === '') {
            return null;
        }
        $from = (int) $from;
        $numbers = $records->flatMap(fn ($r) => preg_split('/\s*,\s*/', (string) $r->{$column}))
            ->filter(fn ($n) => ctype_digit($n) && (int) $n >= $from)->map(fn ($n) => (int) $n)->unique()->sort()->values();
        $ranges = [];
        $last = $from - 1;
        foreach ($numbers as $n) {
            if ($n - $last > 1) {
                $ranges[] = ['from' => $last + 1, 'to' => $n - 1];
            }
            $last = $n;
        }

        return ['from' => $from, 'missing' => array_sum(array_map(fn ($r) => $r['to'] - $r['from'] + 1, $ranges)), 'ranges' => array_slice($ranges, 0, 30)];
    }
}
