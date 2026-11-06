<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $group ?: 'All groups' }} · {{ now('Asia/Manila')->format('M j, Y') }}</title>
    {{-- A printable list (or Save as PDF) to hand to whoever collects: plain, black on white, fits A4. --}}
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font: 11px/1.4 system-ui, -apple-system, "Segoe UI", Arial, sans-serif; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0; }
        .sub { color: #555; margin: 2px 0 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #eee; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .late { color: #b91c1c; font-weight: 600; }
        .muted { color: #666; }
        tfoot td { font-weight: 700; background: #f6f6f6; }
        .remarks { width: 18%; }
        .bar { margin-bottom: 14px; }
        .bar button { font: inherit; font-size: 13px; padding: 8px 14px; border-radius: 8px; border: 0; background: #2563eb; color: #fff; cursor: pointer; }
        @media print { body { margin: 0; } .bar { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="print()">Print or save as PDF</button></div>
    <h1>{{ $title }} · {{ $group ?: 'All groups' }}</h1>
    <p class="sub">
        Sahara Construction Supply · {{ $records->count() }} {{ Str::plural('invoice', $records->count()) }}
        @if ($from || $to) · dated {{ $from ? \Illuminate\Support\Carbon::parse($from)->format('M j, Y') : '…' }} to {{ $to ? \Illuminate\Support\Carbon::parse($to)->format('M j, Y') : 'now' }} @endif
        · as of {{ now('Asia/Manila')->format('M j, Y g:i A') }}
    </p>
    <table>
        <thead>
            <tr>
                <th>SI #</th>
                <th>SI date</th>
                <th>Company (sold to) · Project</th>
                <th>PO # / DR #</th>
                <th>Due</th>
                <th class="num">Amount</th>
                <th class="remarks">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $r)
                @php($late = $r->daysLate())
                <tr>
                    <td>{{ $r->si_number ?? '—' }}@if ($r->si_missing)<br><span class="late">Actual SI missing</span>@endif</td>
                    <td>{{ $r->si_date?->format('M j, Y') ?? '—' }}</td>
                    <td>{{ implode(' · ', array_filter([$r->sold_to, $r->project])) ?: '—' }}@unless ($group)<br><span class="muted">{{ $r->company }}</span>@endunless</td>
                    <td>{{ $r->po_number ?? '—' }}@if ($r->dr_number)<br><span class="muted">DR {{ $r->dr_number }}</span>@endif</td>
                    <td>
                        @if ($r->paid)
                            {{ $r->paid_confirmed ? 'Paid' : 'For checking' }}
                        @elseif ($late === null)
                            <span class="muted">No due date</span>
                        @else
                            {{ $r->dueOn()->format('M j, Y') }}<br>
                            <span class="{{ $late > 0 ? 'late' : 'muted' }}">{{ $late > 0 ? $late.' '.Str::plural('day', $late).' late' : ($late === 0 ? 'Due today' : 'Due in '.-$late.' '.Str::plural('day', -$late)) }}</span>
                        @endif
                    </td>
                    <td class="num">₱{{ number_format($r->amount, 2) }}</td>
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Nothing to collect.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total</td>
                <td class="num">₱{{ number_format($records->sum('amount'), 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <script>addEventListener('load', () => print());</script>
</body>
</html>
