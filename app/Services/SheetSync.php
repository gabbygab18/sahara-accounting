<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Record;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Two-way sync between the app and the master Google Sheet: one tab per group (Record::company), each row a
 * record with the other Record::COLUMNS from column A, and its SYNC ID "r123" in hidden column Z. A tab counts
 * once its Z1 says SYNC ID (the app writes that when it makes the tab), so other tabs are left alone.
 *
 * Rows with a known SYNC ID update their record (the tab is its group, so moving a row to another tab moves
 * the record), unless the record was changed in the app since the last sync: then the app's values are
 * written over the row, or the row moves to its new group's tab. Rows without one are new records (their id
 * is written back). Records whose row is gone from their tab were deleted in the sheet; a whole tab gone is
 * written again rather than trusted. Records added in the app go at the bottom of their tab, and records
 * deleted in the app take their row with them.
 */
class SheetSync
{
    /** The single tab used before one tab per group: never read. Delete it once the group tabs are filled. */
    public const LEGACY_TAB = 'RECORDS';

    /** Every SI number in order, a "Missing" row for each one skipped: written by the app, never read. */
    public const MASTER_TAB = 'SI MASTER';

    /** ponytail: the master lists at most this many numbers (the highest); raise it if a booklet run gets longer */
    private const MASTER_ROWS = 5000;

    public function __construct(private readonly GoogleSheet $sheet) {}

    public static function make(): ?self
    {
        return ($sheet = GoogleSheet::fromConfig()) ? new self($sheet) : null;
    }

    /** Only one sync runs at a time; a second caller waits for the first. */
    public function run(): SyncLog
    {
        $log = new SyncLog;
        try {
            Cache::lock('sheets-sync', 300)->block(120, fn () => $this->sync($log));
        } catch (Throwable $error) {
            $log->error = $error->getMessage();
            $log->added = $log->updated = $log->deleted = $log->pushed = 0; // rolled back
        }

        // Quiet syncs (nothing changed) are not logged, so the log shows what actually happened;
        // the same failure every 15 seconds only bumps the time on its existing row, and reaches laravel.log once.
        $last = SyncLog::latest('id')->first();
        if ($log->error && $last?->error === $log->error) {
            $last->touch();
        } elseif (isset($error)) {
            report($error);
            $log->save();
        } elseif ($log->added || $log->updated || $log->deleted || $log->pushed) {
            $log->save();
        }
        Cache::forever('sheets-last-sync', ['at' => now()->toIso8601String(), 'error' => $log->error]);

        return $log;
    }

    private function sync(SyncLog $log): void
    {
        $headers = array_slice(array_values(Record::COLUMNS), 1);
        $titles = array_values(array_filter($this->sheet->tabs(), fn ($t) => $t !== self::LEGACY_TAB));
        $read = $titles ? $this->sheet->read(array_map(fn ($t) => GoogleSheet::range($t, 'A1:Z'), $titles)) : [];
        $tabs = []; // group tab => sheet row => cells
        $writes = [];
        $stale = []; // tabs whose header row is not the current columns (made by an older version)
        foreach ($titles as $title) {
            $rows = $read[GoogleSheet::range($title, 'A1:Z')];
            if (trim((string) ($rows[1][25] ?? '')) === 'SYNC ID') {
                if (array_map(fn ($c) => trim((string) $c), array_pad(array_slice($rows[1], 0, count($headers)), count($headers), '')) !== $headers) {
                    // Its rows may sit under the wrong headers: fix the headers and write every row from the app.
                    $stale[$title] = true;
                    $writes[GoogleSheet::range($title, 'A1')] = $headers;
                }
                unset($rows[1]);
                $tabs[$title] = $rows;
            }
        }
        $records = Record::withTrashed()->get()->keyBy('id');
        // An empty read would delete everything; refuse rather than trust it.
        $synced = $records->contains(fn ($r) => $r->synced_at && ! $r->pending_sync && ! $r->trashed());
        if ($synced && ! collect($tabs)->contains(fn ($rows) => (bool) array_filter($rows))) {
            throw new RuntimeException('The group tabs came back empty, so nothing was changed. Check that they were not cleared.');
        }

        // Sheets tab names are unique ignoring case, so "cityland" goes in the "Cityland" tab.
        $lower = [];
        foreach (array_keys($tabs) as $title) {
            $lower[mb_strtolower($title)] = $title;
        }
        $tabOf = function (Record $r) use (&$lower): string {
            $name = mb_substr(trim($r->company), 0, 100) ?: 'No group';
            $name = in_array(mb_strtolower($name), [mb_strtolower(self::LEGACY_TAB), mb_strtolower(self::MASTER_TAB)], true) ? "{$name} (group)" : $name;

            return $lower[mb_strtolower($name)] ?? $name;
        };

        $sent = []; // id => updated_at of the version written to the sheet
        $gone = []; // tab => sheet rows to remove: records deleted in the app, or moved to another group
        $seen = [];
        $append = [];
        // ponytail: appends go below the last row read; a row typed in the same second can be overwritten
        $next = array_map(fn ($rows) => max([1, ...array_keys($rows)]) + 1, $tabs);

        DB::transaction(function () use ($tabs, $stale, $records, $headers, $tabOf, $log, &$lower, &$writes, &$sent, &$gone, &$seen, &$append, &$next) {
            foreach ($tabs as $tab => $rows) {
                $tab = (string) $tab; // a tab named "123" is an int key
                foreach ($rows as $row => $cells) {
                    $id = preg_match('/^r(\d+)$/', trim((string) ($cells[25] ?? '')), $m) ? (int) $m[1] : null;
                    // A copied row repeats an id; only its first row keeps the record.
                    $record = $id && ! isset($seen[$id]) ? $records->get($id) : null;

                    if ($record) {
                        $seen[$id] = true;
                        if ($record->trashed()) {
                            $gone[$tab][] = $row;
                        } elseif ($record->pending_sync && $tabOf($record) !== $tab) {
                            $gone[$tab][] = $row; // its group was changed in the app
                            $append[] = $record;
                        } elseif ($record->pending_sync || isset($stale[$tab])) {
                            $writes[GoogleSheet::range($tab, "A{$row}")] = $this->cells($record);
                            $sent[$id] = $record->updated_at;
                        } else {
                            $record->fill(Record::fromRow([$tab, ...array_slice($cells, 0, count($headers))]));
                            if ($record->isDirty()) {
                                Activity::log(null, 'edited', $record);
                                $record->save();
                                $log->updated++;
                            }
                        }
                    } elseif (! Record::blankRow(['', ...$cells])) {
                        $record = Record::create(Record::fromRow([$tab, ...array_slice($cells, 0, count($headers))]) + ['synced_at' => now(), 'pending_sync' => false]);
                        Activity::log(null, 'added', $record);
                        $writes[GoogleSheet::range($tab, "Z{$row}")] = ['r'.$record->id];
                        $seen[$record->id] = true;
                        $log->added++;
                    }
                }
            }

            foreach ($records as $id => $record) {
                if (isset($seen[$id])) {
                    continue;
                }
                if ($record->trashed()) {
                    $record->forceDelete(); // never reached the sheet, or its row is already gone
                } elseif ($record->synced_at && ! $record->pending_sync && isset($tabs[$tabOf($record)])) {
                    Activity::log(null, 'deleted', $record);
                    $record->forceDelete();
                    $log->deleted++;
                } else {
                    // New in the app, changed in the app after its row was removed, or its whole tab is gone.
                    $append[] = $record;
                }
            }

            foreach ($append as $record) {
                $tab = $tabOf($record);
                if (! isset($next[$tab])) {
                    $tab = $this->sheet->prepareTab($tab, $headers);
                    $lower[mb_strtolower($tab)] = $tab;
                    $next[$tab] ??= 2;
                }
                $row = $next[$tab]++;
                $writes[GoogleSheet::range($tab, "A{$row}")] = [...array_pad($this->cells($record), 25, null), 'r'.$record->id];
                $sent[$record->id] = $record->updated_at;
            }

            // Inside the transaction: if the sheet refuses the write, the records made above are rolled back too.
            $this->sheet->write($writes);
        });

        // Only versions that were not changed again during the sync count as written.
        foreach ($sent as $id => $at) {
            Record::withTrashed()->whereKey($id)->where('updated_at', $at)->toBase()->update(['pending_sync' => false, 'synced_at' => now()]);
        }
        $log->pushed = count($sent);

        // Header colours, banding, filter, paid green / unpaid red, dropdowns, ₱ amounts, dates and alignment: once per tab (repeating is harmless, so a cleared cache only redoes it).
        $key = fn ($tab) => 'sheets-styled-v8:'.($this->sheet->spreadsheetId ?? '').':'.$tab;
        if ($unstyled = array_values(array_filter(array_map('strval', array_keys($next)), fn ($tab) => ! Cache::has($key($tab))))) {
            $col = fn ($column) => array_search($column, array_keys(Record::COLUMNS), true) - 1;
            $this->sheet->styleTabs($unstyled, [
                'columns' => count($headers),
                'colors' => [$col('paid') => Record::PAYMENT, $col('check_cleared') => Record::CLEARED],
                'lists' => [$col('paid') => array_keys(Record::PAYMENT), $col('delivery_status') => Record::DELIVERY, $col('check_cleared') => array_keys(Record::CLEARED), $col('cash') => Record::PAID_BY],
                'money' => [$col('si_amount'), $col('amount')],
                'centered' => [$col('terms_days')],
                'dates' => array_map($col, Record::DATES),
                'sections' => [
                    [$col('sold_to'), $col('tin'), '#334155'],
                    [$col('po_date'), $col('po_number'), '#1e40af'],
                    [$col('dr_date'), $col('delivery_status'), '#0f766e'],
                    [$col('si_date'), $col('amount'), '#b45309'],
                    [$col('paid'), $col('or_number'), '#6d28d9'],
                    [$col('booklet_number'), $col('envelope_number'), '#475569'],
                ],
            ]);
            foreach ($unstyled as $tab) {
                Cache::forever($key($tab), true);
            }
        }

        // Then columns as wide as their longest cell (₱ included), so check numbers and long names are not cut off.
        if ($writes || $unstyled) {
            $this->sheet->fitColumns(array_map('strval', array_keys($next)), $headers);
        }

        if ($gone) {
            $this->sheet->deleteRows($gone);
            $trashed = $records->filter->trashed()->keys();
            Record::onlyTrashed()->whereIn('id', $trashed)->forceDelete();
            $log->pushed += $trashed->count();
        }

        $this->writeMaster();
    }

    /**
     * The "mother file": one row per SI number from the lowest to the highest, so a lost or hidden receipt shows as a
     * blank "Missing" row, and one entered twice as "Duplicate". Rewritten only when its rows change.
     */
    private function writeMaster(): void
    {
        $lines = []; // SI number => [row, ...]
        foreach (Record::get() as $r) {
            $paid = $r->toRow()[array_search('paid', array_keys(Record::COLUMNS), true)];
            foreach ($r->sis as $l) {
                if (ctype_digit((string) $l['number'])) {
                    $lines[(int) $l['number']][] = [$l['number'], $l['date'], $r->company, $r->sold_to, $r->project, $l['amount'], $paid];
                }
            }
        }
        ksort($lines);
        $rows = [['SI #', 'SI DATE', 'GROUP', 'COMPANY (SOLD TO)', 'PROJECT', 'SI AMOUNT', 'PAYMENT STATUS', 'NOTE']];
        if ($lines) {
            $last = array_key_last($lines);
            for ($n = max(array_key_first($lines), $last - self::MASTER_ROWS + 1); $n <= $last; $n++) {
                foreach ($lines[$n] ?? [[(string) $n, '', '', '', '', '', '']] as $row) {
                    $rows[] = [...$row, ! isset($lines[$n]) ? 'Missing' : (count($lines[$n]) > 1 ? 'Duplicate' : '')];
                }
            }
        }

        $key = 'sheets-master:'.($this->sheet->spreadsheetId ?? '');
        $styled = 'sheets-styled-master-v2:'.($this->sheet->spreadsheetId ?? '');
        if (Cache::get($key) === ($hash = md5(json_encode($rows))) && Cache::has($styled)) {
            return;
        }
        $made = $this->sheet->fillTab(self::MASTER_TAB, $rows, [7, ['Missing' => '#f4c7c3', 'Duplicate' => '#fce8b2']]);
        // The same look as the group tabs: SI in amber, who in slate, payment in violet; Paid green, Unpaid red.
        if ($made || ! Cache::has($styled)) {
            $this->sheet->styleTabs([self::MASTER_TAB], [
                'columns' => count($rows[0]),
                'colors' => [6 => Record::PAYMENT],
                'lists' => [],
                'money' => [5],
                'centered' => [],
                'dates' => [1],
                'sections' => [[0, 1, '#b45309'], [2, 4, '#334155'], [5, 5, '#b45309'], [6, 7, '#6d28d9']],
            ]);
            Cache::forever($styled, true);
        }
        $this->sheet->fitColumns([self::MASTER_TAB], $rows[0]);
        Cache::forever($key, $hash);
    }

    /** A record's row in its group's tab: every column but the group. */
    private function cells(Record $record): array
    {
        return array_slice($record->toRow(), 1);
    }
}
