<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Record;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Puts back booklet / envelope numbers changed from the Google Sheet between two times (from the activity log). */
class RestoreFiling extends Command
{
    protected $signature = 'records:restore-filing
        {--from= : Philippine time, e.g. "2026-11-10 10:00"; without it, lists when such changes happened}
        {--to= : Philippine time}
        {--apply : Put them back (without it, only shows what would be)}';

    protected $description = 'Put back booklet and envelope numbers changed from the Google Sheet between two times';

    private const FIELDS = ['booklet_number', 'envelope_number'];

    private const TZ = 'Asia/Manila';

    public function handle(): int
    {
        $edits = Activity::where('action', 'edited')->whereNull('user_id')
            ->where('created_at', '>=', $this->option('from') ? Carbon::parse($this->option('from'), self::TZ)->utc() : now()->subDays(2))
            ->when($this->option('to'), fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, self::TZ)->utc()))
            ->orderBy('id')->get()
            ->filter(fn (Activity $a) => array_intersect_key($a->changes ?? [], array_flip(self::FIELDS)));

        if (! $this->option('from')) {
            $this->table(['Minute (PH time)', 'Records changed from the sheet'], $edits
                ->groupBy(fn (Activity $a) => $a->created_at->tz(self::TZ)->format('Y-m-d H:i'))
                ->map(fn ($group, $minute) => [$minute, $group->pluck('record_id')->unique()->count()])->values()->all());
            $this->line('Then --from="YYYY-MM-DD HH:MM" --to="YYYY-MM-DD HH:MM" to see what would be put back, and add --apply to do it.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($edits->groupBy('record_id') as $id => $activities) {
            if (! $record = Record::find($id)) {
                continue;
            }
            foreach (self::FIELDS as $field) {
                $mine = $activities->filter(fn (Activity $a) => array_key_exists($field, $a->changes));
                if ($mine->isEmpty()) {
                    continue;
                }
                $before = $mine->first()->changes[$field][0];
                $after = $mine->last()->changes[$field][1];
                // Changed again since (in the app or the sheet): that newer value stays.
                if ((string) $record->{$field} !== (string) $after || (string) $before === (string) $after) {
                    continue;
                }
                $record->{$field} = $before === null ? null : (string) $before;
                $rows[] = [$record->label(), $field === 'booklet_number' ? 'Booklet #' : 'Envelope #', $after ?? '—', $before ?? '—'];
            }
            if ($this->option('apply') && $record->isDirty()) {
                Activity::log(null, 'edited', $record);
                $record->pending_sync = true; // written to its group tab and the SI master at the next sync
                $record->save();
            }
        }

        $this->table(['Record', 'Field', 'Now', 'Put back'], $rows);
        $this->info($this->option('apply') ? count($rows).' put back. The sheet shows them after the next sync.' : count($rows).' to put back. Nothing changed yet: add --apply to do it.');

        return self::SUCCESS;
    }
}
