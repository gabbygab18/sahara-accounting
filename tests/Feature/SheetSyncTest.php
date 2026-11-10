<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Record;
use App\Models\SyncLog;
use App\Services\SheetSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeSheet $sheet;

    private function sync(): SyncLog
    {
        return (new SheetSync($this->sheet))->run();
    }

    /** A cell of a group tab by field name (the tab has every column but the group, from A). */
    private function cell(string $tab, int $row, string $field): mixed
    {
        return $this->sheet->row($tab, $row)[array_search($field, array_keys(Record::COLUMNS), true) - 1];
    }

    /** "'Cityland'!M2" for a field's cell. */
    private function at(string $tab, int $row, string $field): string
    {
        return "'{$tab}'!".chr(64 + array_search($field, array_keys(Record::COLUMNS), true)).$row;
    }

    /** Group tabs only, without the SI master. */
    private function groupTabs(array $tabs): array
    {
        return array_values(array_diff($tabs, [SheetSync::MASTER_TAB]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = new FakeSheet([]);
    }

    public function test_records_made_in_the_app_and_in_the_sheet_meet_both_ways()
    {
        $app = Record::create(['company' => 'Cityland', 'sold_to' => 'Pines Peak Towers', 'sis' => [['date' => '2026-10-16', 'number' => '1', 'amount' => 100]]]);
        $log = $this->sync();
        $this->assertNull($log->error);
        $this->assertSame('COMPANY (SOLD TO)', $this->sheet->row('Cityland', 1)[0]);
        $this->assertSame('SYNC ID', $this->sheet->row('Cityland', 1)[25]);
        $this->assertSame('r'.$app->id, $this->sheet->row('Cityland', 2)[25]);
        $this->assertSame('Pines Peak Towers', $this->cell('Cityland', 2, 'sold_to'));
        $this->assertSame('Unpaid', $this->cell('Cityland', 2, 'paid'));
        $this->assertFalse($app->fresh()->pending_sync);
        $this->assertSame(['Cityland'], $this->groupTabs($this->sheet->styled)); // paid green, unpaid red, once

        // Typed in the group's tab: becomes a record of that group and gets its SYNC ID.
        $this->sheet->write([$this->at('Cityland', 3, 'sold_to') => ['Uptown', '', '', '', '', '', '', 'pending', '2026-10-20', '77', '2,500', '', '15', '', 'Unpaid']]);
        $this->assertSame(1, $this->sync()->added);
        $new = Record::where('si_number', '77')->sole();
        $this->assertSame(['Cityland', 'Uptown', 2500.0, 15, 'Not delivered'], [$new->company, $new->sold_to, $new->amount, $new->terms_days, $new->delivery_status]);
        $this->assertSame('r'.$new->id, $this->sheet->row('Cityland', 3)[25]);
        $this->assertNull(Activity::where('record_id', $new->id)->sole()->user_id);

        // Edited in the sheet: the record follows. Then edited in the app: the row follows.
        $this->sheet->write([$this->at('Cityland', 2, 'paid') => ['Paid'], $this->at('Cityland', 2, 'or_number') => ['OR-9']]);
        $this->sync();
        $this->assertTrue($app->fresh()->paid);
        $app->fresh()->fill(['sis' => [['date' => '2026-10-16', 'number' => '1', 'amount' => 120]], 'pending_sync' => true])->save();
        $this->sync();
        $this->assertSame(120.0, $this->cell('Cityland', 2, 'amount'));
        $this->assertSame(['Cityland'], $this->groupTabs($this->sheet->styled));
        $this->assertSame('OR-9', $this->cell('Cityland', 2, 'or_number'));
    }

    public function test_each_group_has_its_tab_and_rows_move_between_them()
    {
        // Left alone: the old single tab, and a tab of notes.
        $this->sheet->tabs['RECORDS'] = [1 => [25 => 'SYNC ID'], 2 => [0 => 'Old', 25 => 'r999']];
        $this->sheet->tabs['Notes'] = [1 => [0 => 'call Cliff']];
        $a = Record::create(['company' => 'Cityland', 'amount' => 1]);
        $b = Record::create(['company' => "Megaworld's", 'amount' => 2]);
        $this->sync();
        $this->assertSame(['SI MASTER', 'RECORDS', 'Notes', 'Cityland', "Megaworld's"], $this->sheet->tabs());
        $this->assertSame(2, Record::count());

        // Group changed in the app: the row leaves the old tab for the new one (same tab, any case).
        $a->fresh()->fill(['company' => "megaworld's", 'pending_sync' => true])->save();
        $this->sync();
        $this->assertSame([1], array_keys($this->sheet->tabs['Cityland'])); // only the header left
        $this->assertSame('r'.$a->id, $this->sheet->row("Megaworld's", 3)[25]);
        $this->sync();
        $this->assertSame("Megaworld's", $a->fresh()->company);

        // Row moved to another group's tab in the sheet: the record moves with it.
        $this->sheet->tabs['Cityland'][2] = $this->sheet->tabs["Megaworld's"][2];
        $this->sheet->deleteRows(["Megaworld's" => [2]]);
        $this->sync();
        $this->assertSame('Cityland', $b->fresh()->company);
        $this->assertSame([1 => [0 => 'call Cliff']], $this->sheet->tabs['Notes']);
    }

    public function test_deletes_go_both_ways_and_an_empty_sheet_is_not_trusted()
    {
        $a = Record::create(['company' => 'A', 'amount' => 1]);
        $b = Record::create(['company' => 'A', 'amount' => 2]);
        $c = Record::create(['company' => 'C', 'amount' => 3]);
        $this->sync();

        $a->delete(); // in the app
        $this->sync();
        $this->assertSame('r'.$b->id, $this->sheet->row('A', 2)[25]);
        $this->assertNull(Record::withTrashed()->find($a->id));

        // A whole tab deleted is not trusted: its records are written again.
        unset($this->sheet->tabs['C']);
        $this->assertSame(0, $this->sync()->deleted);
        $this->assertSame('r'.$c->id, $this->sheet->row('C', 2)[25]);

        $this->sheet->deleteRows(['A' => [2]]); // in the sheet
        $this->sync();
        $this->assertSame([$c->id], Record::pluck('id')->all());

        unset($this->sheet->tabs['C'][2]);
        $this->assertStringContainsString('came back empty', $this->sync()->error);
        $this->assertSame(1, Record::count());
    }

    public function test_a_tab_with_old_headers_gets_new_headers_and_its_rows_rewritten()
    {
        $r = Record::create(['company' => 'A', 'sold_to' => 'Uptown', 'check_number' => 'BDO 123456', 'amount' => 5]);
        $this->sync();
        $this->assertSame(['A'], $this->groupTabs($this->sheet->fitted)); // widths fit the cells

        // An older version's headers, and its row one column off.
        $this->sheet->tabs['A'][1] = ['COMPANY (SOLD TO)', 'OLD', 25 => 'SYNC ID'];
        $this->sheet->tabs['A'][2] = ['Uptown', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'BDO 123456', 25 => 'r'.$r->id];
        $this->sync();
        $this->assertSame(array_slice(array_values(Record::COLUMNS), 1), array_slice($this->sheet->row('A', 1), 0, count(Record::COLUMNS) - 1));
        $this->assertSame('BDO 123456', $this->cell('A', 2, 'check_number'));
        $this->assertSame('BDO 123456', $r->fresh()->check_number);

        $this->sheet->fitted = [];
        $this->sync(); // nothing changed: no writes, no resize
        $this->assertSame([], $this->sheet->fitted);
    }

    public function test_the_si_master_lists_every_number_in_order_with_the_missing_ones()
    {
        $si = fn (string $n, float $amount) => ['date' => '2026-10-16', 'number' => $n, 'amount' => $amount];
        Record::create(['company' => 'A', 'sold_to' => 'Uptown', 'sis' => [$si('10', 100), $si('13', 50)]]);
        Record::create(['company' => 'B', 'sis' => [$si('11', 20)]]);
        Record::create(['company' => 'B', 'sis' => [$si('13', 5), $si('C-1', 1)]]); // 13 twice; not a number: left out
        $this->sync();

        $master = $this->sheet->tabs['SI MASTER'];
        $this->assertSame('SI #', $master[1][0]);
        $this->assertSame(['10', '11', '12', '13', '13'], array_map(fn ($row) => (string) $row[0], array_slice($master, 1)));
        $this->assertEquals(['10', '2026-10-16', 'A', 'Uptown', null, 100, 'Unpaid', '', null, null, null, null, null, null, null], array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, array_slice($master[2], 0, 15)));
        $this->assertSame(['BOOKLET #', 'RECORD'], [$master[1][13], $master[1][25]]);
        $this->assertSame('Missing', $master[4][7]);
        $this->assertSame(['Duplicate', 'Duplicate'], [$master[5][7], $master[6][7]]);
        $this->assertCount(3, Record::all()); // never read back as records
        $this->assertContains('SI MASTER', $this->sheet->styled);
        $this->assertContains('SI MASTER', $this->sheet->fitted);

        // A group named like it gets its own tab.
        Record::create(['company' => 'si master', 'amount' => 1]);
        $this->sync();
        $this->assertArrayHasKey('si master (group)', $this->sheet->tabs);
    }

    public function test_booklet_and_envelope_numbers_typed_in_the_si_master_reach_the_record_and_its_group_tab()
    {
        $si = fn (string $n) => ['date' => '2026-10-16', 'number' => $n, 'amount' => 10];
        $a = Record::create(['company' => 'Cityland', 'drs' => [], 'sis' => [$si('10'), $si('11')]]);
        $b = Record::create(['company' => 'Cityland', 'drs' => [], 'sis' => [$si('12')]]);
        $this->sync();
        $this->travel(1)->minutes();
        $this->sync(); // the next sync, with nothing new: the master now shows the records as they are

        // Typed on A's second SI: A takes it, then its group row and both its master rows show it.
        $this->sheet->write(["'SI MASTER'!N3" => [300, 'E-5']]);
        $this->sync();
        $this->assertSame(['300', 'E-5'], [$a->fresh()->booklet_number, $a->fresh()->envelope_number]);
        $this->assertSame('300', $this->cell('Cityland', 2, 'booklet_number'));
        $this->assertSame(['300', '300', null], array_map(fn ($row) => $this->sheet->row('SI MASTER', $row)[13], [2, 3, 4]));
        $this->assertNull(Activity::where('record_id', $a->id)->latest('id')->first()->user_id);
        $this->travel(1)->minutes();
        $this->sync(); // the next sync, with nothing new: the master now shows the records as they are

        // The other way: typed in the group tab, shown in the master. Cleared in the master: cleared.
        $this->sheet->write([$this->at('Cityland', 3, 'booklet_number') => ['301']]);
        $this->sync();
        $this->assertSame('301', $this->sheet->row('SI MASTER', 4)[13]);
        $this->travel(1)->minutes();
        $this->sync(); // the next sync, with nothing new: the master now shows the records as they are
        $this->sheet->write(["'SI MASTER'!O2" => ['']]);
        $this->sync();
        $this->assertNull($a->fresh()->envelope_number);
        $this->travel(1)->minutes();
        $this->sync(); // the next sync, with nothing new: the master now shows the records as they are

        // Changed in the app since the master last showed it: the app wins and the master is rewritten.
        $this->sheet->write(["'SI MASTER'!N4" => ['999']]);
        $b->fresh()->fill(['booklet_number' => '302', 'pending_sync' => true])->save();
        $this->sync();
        $this->assertSame('302', $b->fresh()->booklet_number);
        $this->assertSame('302', $this->sheet->row('SI MASTER', 4)[13]);
    }

    public function test_a_sorted_or_wiped_si_master_does_not_wipe_the_numbers_and_wiped_ones_can_be_put_back()
    {
        $records = collect(range(1, 7))->map(fn ($n) => Record::create([
            'company' => 'Cityland', 'drs' => [], 'booklet_number' => (string) (300 + $n), 'sis' => [['date' => '2026-10-16', 'number' => (string) (10 + $n), 'amount' => 10]],
        ]));
        $later = function () {
            $this->travel(1)->minutes();
            $this->sync();
        };
        $this->sync();
        $later();
        $booklets = fn () => $records->map(fn ($r) => $r->fresh()->booklet_number)->all();
        $kept = $booklets();

        // Sorted on A-O only (hidden Z left as it was): rows no longer match their ids, so nothing is taken.
        [$two, $three] = [$this->sheet->tabs['SI MASTER'][2], $this->sheet->tabs['SI MASTER'][3]];
        $this->sheet->tabs['SI MASTER'][2] = [...array_slice($three, 0, 15), ...array_slice($two, 15)];
        $this->sheet->tabs['SI MASTER'][3] = [...array_slice($two, 0, 15), ...array_slice($three, 15)];
        $later();
        $this->assertSame($kept, $booklets());

        // The whole booklet column deleted: not taken, and the master shows the numbers again.
        $this->sheet->write(array_combine(array_map(fn ($row) => "'SI MASTER'!N{$row}", range(2, 8)), array_fill(0, 7, [''])));
        $later();
        $this->assertSame($kept, $booklets());
        $later();
        $this->assertSame('301', $this->sheet->row('SI MASTER', 2)[13]);

        // A few cleared is taken; the restore command puts them back from the activity log.
        $from = now()->tz('Asia/Manila')->format('Y-m-d H:i');
        $this->sheet->write(["'SI MASTER'!N2" => [''], "'SI MASTER'!N3" => ['']]);
        $later();
        $this->assertSame([null, null], array_slice($booklets(), 0, 2));
        $this->artisan('records:restore-filing', ['--from' => $from])->assertSuccessful();
        $this->assertSame([null, null], array_slice($booklets(), 0, 2)); // only shown until --apply
        $this->artisan('records:restore-filing', ['--from' => $from, '--apply' => true])->assertSuccessful();
        $this->assertSame($kept, $booklets());
        $later();
        $this->assertSame(['301', '302'], [$this->sheet->row('SI MASTER', 2)[13], $this->cell('Cityland', 3, 'booklet_number')]);
    }

    public function test_group_tabs_go_lowest_si_first_where_the_app_writes_and_a_sorted_master_stays_sorted()
    {
        $si = fn (string ...$n) => array_map(fn ($n) => ['date' => '2026-10-16', 'number' => $n, 'amount' => 10], $n);
        $c = Record::create(['company' => 'Cityland', 'drs' => [], 'booklet_number' => '1', 'sis' => $si('22')]);
        Record::create(['company' => 'Cityland', 'drs' => [], 'sis' => []]); // not invoiced yet: last
        $b = Record::create(['company' => 'Cityland', 'drs' => [], 'booklet_number' => '3', 'sis' => $si('21', '20')]);
        $this->sync();
        $order = fn () => array_map(fn ($row) => $this->sheet->row('Cityland', $row)[24], [2, 3, 4]);
        $this->assertSame([20, 22, null], $order());
        $this->assertSame([SheetSync::ORDER_HEADER, 'SYNC ID'], array_slice($this->sheet->row('Cityland', 1), 24, 2));

        // Sorted by hand in the sheet and typed in: left as it is. Changed in the app: lowest SI first again.
        $this->sheet->tabs['Cityland'] = [1 => $this->sheet->tabs['Cityland'][1], 2 => $this->sheet->tabs['Cityland'][4], 3 => $this->sheet->tabs['Cityland'][3], 4 => $this->sheet->tabs['Cityland'][2]];
        $this->sheet->write([$this->at('Cityland', 3, 'booklet_number') => ['2']]);
        $this->sync();
        $this->assertSame([null, 22, 20], $order());
        $this->assertSame('2', $c->fresh()->booklet_number);
        $b->fresh()->fill(['envelope_number' => 'E-1', 'pending_sync' => true])->save();
        $this->sync();
        $this->assertSame([20, 22, null], $order());

        // The SI master sorted by booklet in the sheet (whole rows, Z too): it stays so while booklets are typed in.
        $this->travel(1)->minutes();
        $this->sync();
        $master = $this->sheet->tabs['SI MASTER'];
        $byBooklet = [1 => $master[1], 2 => $master[4], 3 => $master[2], 4 => $master[3]]; // booklet 2 (SI 22), then 3 (SIs 20, 21)
        $this->sheet->tabs['SI MASTER'] = $byBooklet;
        $this->sheet->write(["'SI MASTER'!N2" => ['4']]);
        $this->sync();
        $this->assertSame('4', $c->fresh()->booklet_number);
        $sis = fn () => array_map(fn ($row) => (string) $row[0], array_slice($this->sheet->tabs['SI MASTER'], 1));
        $this->assertSame(['22', '20', '21'], $sis());
        Record::create(['company' => 'Cityland', 'drs' => [], 'sis' => $si('23')]);
        $this->sync();
        $this->assertSame(['22', '20', '21', '23'], $sis()); // new at the bottom

        // Sorted back by SI: written in SI order again, with the missing numbers.
        $tab = $this->sheet->tabs['SI MASTER'];
        $this->sheet->tabs['SI MASTER'] = [1 => $tab[1], 2 => $tab[3], 3 => $tab[4], 4 => $tab[2], 5 => $tab[5]];
        Record::create(['company' => 'Cityland', 'drs' => [], 'sis' => $si('25')]);
        $this->sync();
        $this->assertSame(['20', '21', '22', '23', '24', '25'], $sis()); // 24: Missing
    }

    public function test_an_app_edit_made_during_a_sync_is_not_lost()
    {
        // A sheet that lets the app change the record while the sync is writing to it.
        $this->sheet = new class([]) extends FakeSheet
        {
            public ?\Closure $during = null;

            public function write(array $rows): void
            {
                parent::write($rows);
                if (($fn = $this->during) && isset($rows["'A'!A2"])) {
                    $this->during = null;
                    $fn();
                }
            }
        };
        $r = Record::create(['company' => 'A', 'amount' => 1]);
        $this->sheet->during = function () use ($r) {
            $this->travel(2)->seconds();
            $r->fresh()->fill(['amount' => 9, 'pending_sync' => true])->save();
        };

        $this->sync();
        $this->assertSame(1.0, $this->cell('A', 2, 'amount'));
        $this->assertTrue($r->fresh()->pending_sync); // the 9 is still owed to the sheet
        $this->sync();
        $this->assertSame(9.0, $this->cell('A', 2, 'amount'));
        $this->assertFalse($r->fresh()->pending_sync);
    }
}
