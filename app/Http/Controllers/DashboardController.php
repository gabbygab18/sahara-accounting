<?php

namespace App\Http\Controllers;

use App\Models\Record;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Summary: paid and unpaid amounts, overall and per company, and what is past its terms. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->can('dashboard')) {
            return redirect($request->user()->home());
        }
        // ponytail: every record loaded once and summed in PHP (due dates differ per SQL dialect); fine into the tens of thousands
        $records = Record::get(['id', 'company', 'sold_to', 'project', 'si_number', 'dr_number', 'po_number', 'si_date', 'terms_days', 'amount', 'paid', 'paid_confirmed']);
        $sum = fn ($rows) => [
            'count' => $rows->count(),
            'paid' => round($rows->filter(fn ($r) => $r->payment() === 'paid')->sum('amount'), 2),
            'checking' => round($rows->filter(fn ($r) => $r->payment() === 'checking')->sum('amount'), 2),
            'unpaid' => round($rows->where('paid', false)->sum('amount'), 2),
            'overdue' => round($rows->filter->isOverdue()->sum('amount'), 2),
        ];

        return Inertia::render('dashboard', [
            'totals' => $sum($records),
            'companies' => $records->groupBy('company')->map(fn ($rows, $company) => ['company' => $company, ...$sum($rows)])
                ->sortByDesc('unpaid')->values(),
            'overdue' => $records->filter->isOverdue()->sortBy(fn ($r) => $r->dueOn())->take(10)
                ->map(fn (Record $r) => [...$r->only('id', 'company', 'sold_to', 'project', 'si_number', 'dr_number', 'po_number', 'amount'), 'due' => $r->dueOn()->toDateString()])->values(),
        ]);
    }
}
