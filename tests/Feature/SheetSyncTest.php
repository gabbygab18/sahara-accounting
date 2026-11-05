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
        $this->assertSame(['Cityland'], $this->sheet->styled); // paid green, unpaid red, once

        // Typed in the group's tab: becomes a record of that group and gets its SYNC ID.
        $this->sheet->write([$this->at('Cityland', 3, 'sold_to') => ['Uptown', '', '', '', '', '', '', 'pending', '2026-10-20', '77', '2,500', '15', '', 'Unpaid']]);
        $this->assertSame(1, $this->sync()->added);
        $new = Record::where('si_number', '77')->sole();
        $this->assertSame(['Cityland', 'Uptown', 2500.0, 15, 'Not delivered'], [$new->company, $new->sold_to, $new->amount, $new->terms_days, $new->delivery_status]);
        $this->assertSame('r'.$new->id, $this->sheet->row('Cityland', 3)[25]);
        $this->assertNull(Activity::where('record_id', $new->id)->sole()->user_id);

        // Edited in the sheet: the record follows. Then edited in the app: the row follows.
        $this->sheet->write([$this->at('Cityland', 2, 'paid') => ['Paid'], $this->at('Cityland', 2, 'or_number') => ['OR-9']]);
        $this->assertSame(1, $this->sync()->updated);
        $this->assertTrue($app->fresh()->paid);
        $app->fresh()->fill(['sis' => [['date' => '2026-10-16', 'number' => '1', 'amount' => 120]], 'pending_sync' => true])->save();
        $this->sync();
        $this->assertSame(120.0, $this->cell('Cityland', 2, 'amount'));
        $this->assertSame(['Cityland'], $this->sheet->styled);
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
        $this->assertSame(['RECORDS', 'Notes', 'Cityland', "Megaworld's"], $this->sheet->tabs());
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
        $this->assertSame(1, $this->sync()->updated);
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
        $this->assertSame(['A'], $this->sheet->fitted); // widths fit the cells

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
