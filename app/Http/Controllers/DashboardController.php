<?php

namespace App\Http\Controllers;

use App\Models\Record;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What needs doing: money to collect (by how late), payments to confirm, checks not yet cleared, skipped SI / DR numbers,
 * and paid / unpaid overall and per group.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->can('dashboard')) {
            return redirect($request->user()->home());
        }
        // ponytail: every record loaded once and summed in PHP (due dates differ per SQL dialect); fine into the tens of thousands
        $records = Record::get(['id', 'company', 'sold_to', 'project', 'si_number', 'dr_number', 'po_number', 'si_date', 'terms_days', 'amount', 'paid', 'paid_confirmed', 'check_number', 'check_date', 'check_cleared', 'or_date']);
        $sum = fn ($rows) => [
            'count' => $rows->count(),
            'paid' => round($rows->filter(fn ($r) => $r->payment() === 'paid')->sum('amount'), 2),
            'checking' => round($rows->filter(fn ($r) => $r->payment() === 'checking')->sum('amount'), 2),
            'unpaid' => round($rows->where('paid', false)->sum('amount'), 2),
            'overdue' => round($rows->filter->isOverdue()->sum('amount'), 2),
        ];
        $brief = fn (Record $r) => $r->only('id', 'company', 'sold_to', 'project', 'si_number', 'dr_number', 'po_number', 'amount');

        // Days past the due date: negative while there is time left, null without an SI date and terms.
        $late = fn (Record $r) => ($due = $r->dueOn()) ? (int) $due->diffInDays(today(), false) : null;
        $unpaid = $records->where('paid', false);
        $aging = collect(['Not yet due' => [PHP_INT_MIN, 0], '1–30 days late' => [1, 30], '31–60 days late' => [31, 60], '61–90 days late' => [61, 90], 'Over 90 days late' => [91, PHP_INT_MAX]])
            ->map(function ($range, $label) use ($unpaid, $late) {
                $rows = $unpaid->filter(fn ($r) => ($l = $late($r) ?? 0) >= $range[0] && $l <= $range[1]);

                return ['label' => $label, 'count' => $rows->count(), 'amount' => round($rows->sum('amount'), 2)];
            })->values();

        $month = fn ($date) => $date && $date->isSameMonth(today());
        $uncleared = $records->filter(fn ($r) => $r->paid && ! $r->check_cleared && ($r->check_number || $r->check_date));

        return Inertia::render('dashboard', [
            'totals' => $sum($records),
            'month' => [
                'billed' => round($records->filter(fn ($r) => $month($r->si_date))->sum('amount'), 2),
                'collected' => round($records->filter(fn ($r) => $r->paid && $month($r->or_date ?? $r->check_date))->sum('amount'), 2),
            ],
            'checkingCount' => $records->filter(fn ($r) => $r->payment() === 'checking')->count(),
            'aging' => $aging,
            // Overdue, then what falls due within a week: who to call.
            'collect' => $unpaid->filter(fn ($r) => ($l = $late($r)) !== null && $l >= -7)->sortBy(fn ($r) => $r->dueOn())->take(15)
                ->map(fn (Record $r) => [...$brief($r), 'due' => $r->dueOn()->toDateString(), 'late' => $late($r)])->values(),
            'uncleared' => [
                'count' => $uncleared->count(),
                'amount' => round($uncleared->sum('amount'), 2),
                'rows' => $uncleared->sortBy(fn ($r) => $r->check_date ?? $r->or_date)->take(15)
                    ->map(fn (Record $r) => [...$brief($r), ...$r->only('check_number'), 'check_date' => $r->check_date?->toDateString()])->values(),
            ],
            'skipped' => [
                'si' => self::skipped($records, 'si_number', Setting::get('booklet.si')),
                'dr' => self::skipped($records, 'dr_number', Setting::get('booklet.dr')),
            ],
            'companies' => $records->groupBy('company')->map(fn ($rows, $company) => ['company' => $company, ...$sum($rows)])
                ->sortByDesc('unpaid')->values(),
        ]);
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
