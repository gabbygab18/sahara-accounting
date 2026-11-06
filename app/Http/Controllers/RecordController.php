<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Record;
use App\Support\RecordWorkbook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Records: the tracker itself. Add, edit, delete, search, filter and export. */
class RecordController extends Controller
{
    private const FILTERS = ['q', 'company', 'project', 'delivery', 'payment', 'from', 'to', 'show', 'order'];

    /** ?show= lists from the summary: an unpaid age group (Record::AGING), or delivered but not yet invoiced. */
    public const SHOWS = ['not-invoiced' => 'Delivered, not yet invoiced', 'missing' => 'Missing details (actual SI, SI date or terms)'];

    public function index(Request $request): Response
    {
        $filtered = $this->filtered($request);

        return Inertia::render('records/index', [
            'records' => $this->ordered($request, clone $filtered)
                ->paginate(25)->withQueryString()
                ->through(fn (Record $r) => [...$r->toArray(), 'due' => $r->dueOn()?->toDateString(), 'overdue' => $r->isOverdue()]),
            // Totals of everything the filters match, not just this page; only for accounts allowed the summary.
            'totals' => ! $request->user()->can('dashboard') ? null : [
                'paid' => round((float) (clone $filtered)->where('paid', true)->where('paid_confirmed', true)->sum('amount'), 2),
                'checking' => round((float) (clone $filtered)->where('paid', true)->where('paid_confirmed', false)->sum('amount'), 2),
                'unpaid' => round((float) (clone $filtered)->where('paid', false)->where('si_cancelled', false)->sum('amount'), 2),
            ],
            'filters' => $request->only(self::FILTERS),
            'show' => self::SHOWS[$request->show] ?? (Record::AGING[$request->show][0] ?? null),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $record = Record::create($this->validated($request) + ['user_id' => $request->user()->id]);
        Activity::log($request->user(), 'added', $record);
        $this->syncSheetSoon();

        return back()->with('success', "Saved {$record->label()}.");
    }

    public function update(Request $request, Record $record): RedirectResponse
    {
        $record->fill($this->validated($request, $record))->pending_sync = true;
        Activity::log($request->user(), 'edited', $record);
        $record->save();
        $this->syncSheetSoon();

        return back()->with('success', "Saved {$record->label()}.");
    }

    /** The admin's double check: a payment the agent marked paid is confirmed. */
    public function confirm(Request $request, Record $record): RedirectResponse
    {
        $record->fill(['paid' => true, 'paid_confirmed' => true])->pending_sync = true;
        Activity::log($request->user(), 'edited', $record);
        $record->save();
        $this->syncSheetSoon();

        return back()->with('success', "Confirmed payment of {$record->label()}.");
    }

    /** Confirm several payments at once, ticked in Records. */
    public function confirmMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']])['ids'];
        $records = Record::whereIn('id', $ids)->where('paid', true)->where('paid_confirmed', false)->get();
        foreach ($records as $record) {
            $record->fill(['paid_confirmed' => true])->pending_sync = true;
            Activity::log($request->user(), 'edited', $record);
            $record->save();
        }
        $this->syncSheetSoon();

        return back()->with('success', 'Confirmed '.$records->count().' '.Str::plural('payment', $records->count()).'.');
    }

    /** "Mark paid" from the list: the check (or cash) and the OR. From an agent it waits for the admin's double check. */
    public function pay(Request $request, Record $record): RedirectResponse
    {
        $this->markPaid($request, [$record]);

        return back()->with('success', "Marked {$record->label()} paid.");
    }

    /** The ticked unpaid records, paid by one check (or cash) and OR. */
    public function payMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']])['ids'];
        $records = Record::whereIn('id', $ids)->where('paid', false)->where('si_cancelled', false)->get();
        $this->markPaid($request, $records);

        return back()->with('success', 'Marked '.$records->count().' '.Str::plural('record', $records->count()).' paid.');
    }

    private function markPaid(Request $request, iterable $records): void
    {
        $data = $request->validate([
            'cash' => ['boolean'],
            'check_number' => ['nullable', 'string', 'max:100'],
            'check_date' => ['nullable', 'date'],
            'or_date' => ['nullable', 'date'],
            'or_number' => ['nullable', 'string', 'max:100'],
        ]);
        $data = self::cashOrCheck(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));
        foreach ($records as $record) {
            $record->fill([...$data, 'paid' => true, 'paid_confirmed' => $request->user()->can('confirm')])->pending_sync = true;
            Activity::log($request->user(), 'edited', $record);
            $record->save();
        }
        $this->syncSheetSoon();
    }

    public function destroy(Request $request, Record $record): RedirectResponse
    {
        Activity::log($request->user(), 'deleted', $record);
        // Soft delete: the next sync removes its row from the sheet, then the record for good.
        $record->delete();
        $this->syncSheetSoon();

        return back()->with('success', "Deleted {$record->label()}.");
    }

    /** What the filters show, as .xlsx, .csv (?format=csv) or a page to print or save as PDF (?format=print). */
    public function export(Request $request): BinaryFileResponse|StreamedResponse|\Illuminate\Http\Response
    {
        if ($request->format === 'print') {
            return response()->view('print', [
                'records' => $this->ordered($request, $this->filtered($request))->get(),
                'title' => $request->payment === 'unpaid' ? 'Unpaid invoices' : 'Records',
                'group' => $request->company,
                'from' => $request->from,
                'to' => $request->to,
            ]);
        }

        $records = $this->filtered($request)->orderBy('si_date')->orderBy('id')->lazy();
        $name = 'Sahara Accounting '.now()->format('Y-m-d');

        if ($request->format === 'csv') {
            return response()->streamDownload(function () use ($records) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₱ and ñ right
                fputcsv($out, array_values(Record::COLUMNS));
                foreach ($records as $r) {
                    fputcsv($out, $r->toRow());
                }
                fclose($out);
            }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $path = tempnam(sys_get_temp_dir(), 'records');
        RecordWorkbook::save($records, $path);

        return response()->download($path, "{$name}.xlsx")->deleteFileAfterSend();
    }

    /** The last added first (?order=latest), else in SI number order (1, 2, 3...) so a skipped receipt shows. */
    private function ordered(Request $request, Builder $query): Builder
    {
        return $request->order === 'latest' ? $query->latest('id')
            // "+ 0" reads "185561, 185562" as 185561 in MySQL and SQLite.
            : $query->orderByRaw('si_number IS NULL')->orderByRaw('si_number + 0')->orderBy('si_number')->orderBy('id');
    }

    private function filtered(Request $request): Builder
    {
        return Record::query()
            ->when($request->q, fn ($q, $s) => $q->search($s))
            ->when($request->company, fn ($q, $c) => $q->where('company', $c))
            ->when($request->project, fn ($q, $p) => $q->where('project', $p))
            ->when($request->delivery, fn ($q, $d) => $q->where('delivery_status', $d))
            ->when($request->payment === 'paid', fn ($q) => $q->where('paid', true)->where('paid_confirmed', true))
            ->when($request->payment === 'checking', fn ($q) => $q->where('paid', true)->where('paid_confirmed', false))
            ->when($request->payment === 'unpaid', fn ($q) => $q->where('paid', false)->where('si_cancelled', false))
            // Dated by the invoice, or by the DR while there is no invoice yet.
            ->when($request->from, fn ($q, $d) => $q->whereRaw('COALESCE(si_date, dr_date) >= ?', [$d]))
            ->when($request->to, fn ($q, $d) => $q->whereRaw('COALESCE(si_date, dr_date) <= ?', [$d]))
            ->when($request->show === 'not-invoiced', fn ($q) => $q->whereNotNull('dr_number')->whereNull('si_number'))
            ->when($request->show === 'missing', fn ($q) => $q->whereIn('id', Record::get(['id', 'paid', 'si_date', 'terms_days', 'si_missing'])
                ->filter->missing()->modelKeys()))
            // ponytail: due dates are worked out in PHP (date maths differs per SQL dialect); fine into the tens of thousands
            ->when(isset(Record::AGING[$request->show]), fn ($q) => $q->whereIn('id', Record::where('paid', false)
                ->get(['id', 'paid', 'si_date', 'terms_days'])->filter(fn ($r) => $r->age() === $request->show)->modelKeys()));
    }

    /** For the filters and the form: groups (with their latest TIN and terms), sold-to companies and projects per group, delivery statuses. */
    public function options(): array
    {
        $rows = Record::query()->toBase()->where('company', '!=', '')->orderBy('id')->get(['company', 'sold_to', 'project', 'tin', 'terms_days']);
        $companies = [];
        foreach ($rows as $r) {
            $companies[$r->company]['tin'] = $r->tin ?: ($companies[$r->company]['tin'] ?? null);
            $companies[$r->company]['terms'] = $r->terms_days ?? ($companies[$r->company]['terms'] ?? null);
            $companies[$r->company]['projects'][$r->project ?? ''] = true;
            $companies[$r->company]['sold_to'][$r->sold_to ?? ''] = true;
        }
        ksort($companies, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'companies' => collect($companies)->map(fn ($c, $name) => [
                'name' => (string) $name,
                'tin' => $c['tin'],
                'terms' => $c['terms'] === null ? null : (int) $c['terms'],
                'projects' => collect(array_keys($c['projects']))->filter()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
                'sold_to' => collect(array_keys($c['sold_to']))->filter()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            ])->values(),
            'delivery' => collect(Record::DELIVERY)->merge(Record::distinct()->pluck('delivery_status'))->filter()->unique()->values(),
        ];
    }

    /** Cash has no check number and nothing to clear. */
    private static function cashOrCheck(array $data): array
    {
        $data['cash'] = (bool) ($data['cash'] ?? false);

        return $data['cash'] ? [...$data, 'check_number' => null, 'check_cleared' => false] : $data;
    }

    private function validated(Request $request, ?Record $record = null): array
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:100'],
            'sold_to' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'po_date' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:255'],
            'terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'drs' => ['array', 'max:50'],
            'drs.*.date' => ['nullable', 'date'],
            'drs.*.number' => ['nullable', 'string', 'max:100'],
            'drs.*.status' => ['nullable', 'string', 'max:50'],
            'sis' => ['array', 'max:50'],
            'sis.*.date' => ['nullable', 'date'],
            'sis.*.number' => ['nullable', 'string', 'max:100'],
            'sis.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'or_date' => ['nullable', 'date'],
            'or_number' => ['nullable', 'string', 'max:100'],
            'paid' => ['required', 'boolean'],
            'paid_confirmed' => ['boolean'],
            'check_number' => ['nullable', 'string', 'max:100'],
            'check_date' => ['nullable', 'date'],
            'check_cleared' => ['boolean'],
            'cash' => ['boolean'],
            'si_missing' => ['boolean'],
            'si_cancelled' => ['boolean'],
            'booklet_number' => ['nullable', 'string', 'max:50'],
            'envelope_number' => ['nullable', 'string', 'max:50'],
        ]);

        $data['drs'] ??= [];
        $data['sis'] ??= [];

        // One SI number, one invoice: refuse a number already on another record (or twice on this one).
        // Old duplicates from the Sheet stay as they are; SI MASTER lists them.
        $seen = [];
        foreach ($data['sis'] as $i => $line) {
            $n = trim((string) ($line['number'] ?? ''));
            if ($n === '') {
                continue;
            }
            $key = strtolower($n);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["sis.{$i}.number" => "SI # {$n} is entered twice."]);
            }
            $seen[$key] = true;
            // "like" narrows it down; the exact match is on the comma separated numbers.
            $other = Record::where('si_number', 'like', "%{$n}%")->when($record, fn ($q) => $q->whereKeyNot($record->id))
                ->get(['id', 'company', 'sold_to', 'si_number'])
                ->first(fn (Record $r) => in_array($key, explode(', ', strtolower($r->si_number)), true));
            if ($other) {
                throw ValidationException::withMessages(["sis.{$i}.number" => "SI # {$n} is already on another record ("
                    .implode(' · ', array_filter([$other->company, $other->sold_to])).').']);
            }
        }
        $data['check_cleared'] = $request->boolean('check_cleared');
        $data['si_missing'] = $request->boolean('si_missing');
        $data['si_cancelled'] = $request->boolean('si_cancelled');
        $data = self::cashOrCheck($data);
        $data['paid_confirmed'] = $data['paid'] && $request->boolean('paid_confirmed');
        if (! $request->user()->can('confirm')) {
            // The agent's "paid" waits for the admin's double check; a confirmed payment stays as the admin left it.
            [$data['paid'], $data['paid_confirmed']] = $record?->paid_confirmed ? [true, true] : [$data['paid'], false];
        }

        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
    }
}
