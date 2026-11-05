<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Record;
use App\Models\User;
use App\Support\RecordWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $overrides = []): array
    {
        return [
            'company' => 'Cityland Dev Corp', 'sold_to' => 'Pines Peak Towers', 'project' => 'Ground up', 'tin' => '008-424-957-000',
            'po_date' => '2026-10-01', 'po_number' => 'PO-114', 'terms_days' => 30, 'paid' => false,
            'check_number' => null, 'check_date' => null, 'check_cleared' => false, 'or_date' => null, 'or_number' => null, 'paid_confirmed' => false,
            'drs' => [['date' => '2026-10-05', 'number' => 'DR-1', 'status' => 'Delivered']],
            ...$this->si('194651', 394.45),
            ...$overrides,
        ];
    }

    /** One SI line. */
    private function si(string $number, float $amount, string $date = '2026-10-16'): array
    {
        return ['sis' => [['date' => $date, 'number' => $number, 'amount' => $amount]]];
    }

    /** A sheet row from column => cell. */
    private static function row(array $cells): array
    {
        return array_map(fn ($column) => $cells[$column] ?? '', array_keys(Record::COLUMNS));
    }

    private static function col(string $column): int
    {
        return array_search($column, array_keys(Record::COLUMNS), true);
    }

    public function test_staff_add_and_edit_but_cannot_delete_or_see_admin_pages()
    {
        $this->actingAs($staff = User::factory()->staff()->create());

        $this->post('/records', $this->invoice())->assertSessionHasNoErrors();
        $record = Record::sole();
        $this->assertSame($staff->id, $record->user_id);
        $this->assertTrue($record->pending_sync);

        $this->put("/records/{$record->id}", $this->invoice(['paid' => true, 'or_number' => 'OR-55', 'check_number' => 'BDO 0012']))->assertSessionHasNoErrors();
        $this->assertTrue($record->fresh()->paid);
        $this->assertSame(['added', 'edited'], Activity::orderBy('id')->pluck('action')->all());
        $this->assertSame([false, true], Activity::where('action', 'edited')->sole()->changes['paid']);

        $this->delete("/records/{$record->id}")->assertForbidden();
        foreach (['/history', '/settings/users', '/settings/sheets'] as $page) {
            $this->get($page)->assertRedirect('/dashboard'); // its home, not a bare 403
        }
        foreach (['/dashboard', '/records', '/records/export'] as $page) {
            $this->get($page)->assertOk();
        }

        // A secretary who only inputs: no summary, so no totals on the records page either.
        $this->actingAs(User::factory()->create(['role' => 'custom', 'permissions' => ['records', 'add']]));
        $this->get('/records')->assertInertia(fn ($page) => $page->where('totals', null)->where('records.total', 1));
        $this->get('/dashboard')->assertRedirect('/records');
        $this->get('/settings/users')->assertRedirect('/records')->assertSessionHas('warning');
        $this->put("/records/{$record->id}", $this->invoice())->assertForbidden();
    }

    public function test_the_agent_marks_it_paid_and_the_admin_double_checks()
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->create();
        $record = Record::create($this->invoice());

        // The agent can't confirm, even by sending the field.
        $this->actingAs($staff)->put("/records/{$record->id}", $this->invoice(['paid' => true, 'paid_confirmed' => true]))->assertSessionHasNoErrors();
        $this->assertSame('checking', $record->fresh()->payment());
        $this->assertSame('For checking', $record->fresh()->toRow()[self::col('paid')]);
        $this->patch("/records/{$record->id}/confirm")->assertForbidden();

        $this->actingAs($admin)->patch("/records/{$record->id}/confirm")->assertSessionHasNoErrors();
        $this->assertSame('paid', $record->fresh()->payment());
        $this->assertSame([false, true], Activity::latest('id')->first()->changes['paid_confirmed']);

        // Once confirmed, the agent can't set it back.
        $this->actingAs($staff)->put("/records/{$record->id}", $this->invoice(['paid' => false]))->assertSessionHasNoErrors();
        $this->assertSame('paid', $record->fresh()->payment());

        // The admin can.
        $this->actingAs($admin)->put("/records/{$record->id}", $this->invoice(['paid' => true, 'paid_confirmed' => false]))->assertSessionHasNoErrors();
        $this->assertSame('checking', $record->fresh()->payment());

        $row = fn (string $status) => array_intersect_key(Record::fromRow(self::row(['paid' => $status])), ['paid' => 1, 'paid_confirmed' => 1]);
        $this->assertSame(['paid' => true, 'paid_confirmed' => false], $row('For checking'));
        $this->assertSame(['paid' => true, 'paid_confirmed' => true], $row('Paid'));
        $this->assertSame(['paid' => false, 'paid_confirmed' => false], $row('Unpaid'));

        // "Mark paid" from the list: the agent's waits for the double check, the admin's is paid.
        $other = Record::create($this->invoice($this->si('2', 394.45)));
        $this->actingAs($staff)->patch("/records/{$other->id}/pay", ['check_number' => 'BDO 7', 'check_date' => '2026-11-20', 'or_date' => '2026-11-05', 'or_number' => 'OR-1'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-20', 'Not cleared'], [$other->fresh()->check_date->toDateString(), $other->fresh()->toRow()[self::col('check_cleared')]]);
        $this->assertSame(['checking', 'BDO 7', '2026-11-05', 'OR-1'], [$other->fresh()->payment(), $other->fresh()->check_number, $other->fresh()->or_date->toDateString(), $other->fresh()->or_number]);
        $this->actingAs($admin)->patch("/records/{$other->id}/pay", ['check_number' => 'BDO 7'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $other->fresh()->payment());
        $other->delete();

        $this->get('/records?payment=checking')->assertInertia(fn ($page) => $page->where('records.total', 1)->where('totals.checking', 394.45));
    }

    public function test_the_admin_deletes_and_the_record_waits_for_the_sheet()
    {
        $this->actingAs(User::factory()->create());
        $record = Record::create($this->invoice());

        $this->delete("/records/{$record->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($record);
        $this->get('/history')->assertOk();
    }

    public function test_filters_totals_and_the_summary()
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-11-20');
        Record::create($this->invoice()); // due Nov 15: overdue
        Record::create($this->invoice([...$this->si('194652', 1000), 'paid' => true, 'paid_confirmed' => true]));
        Record::create($this->invoice(['company' => 'Megaworld', 'project' => 'Uptown', ...$this->si('200', 50, '2026-11-10')]));

        $this->get('/records?company=Cityland+Dev+Corp&payment=unpaid')->assertInertia(fn ($page) => $page
            ->where('records.total', 1)->where('records.data.0.overdue', true)->where('records.data.0.due', '2026-11-15')
            ->where('totals.unpaid', 394.45)->where('totals.paid', 0));
        $this->get('/records?q=194652')->assertInertia(fn ($page) => $page->where('records.total', 1));
        $this->get('/records?from=2026-11-01')->assertInertia(fn ($page) => $page->where('records.total', 1)
            ->where('options.companies.0.name', 'Cityland Dev Corp')->where('options.companies.0.tin', '008-424-957-000'));

        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('totals.paid', 1000)->where('totals.unpaid', 444.45)->where('totals.overdue', 394.45)
            ->where('companies.0.company', 'Cityland Dev Corp')->where('overdue.0.si_number', '194651'));
    }

    public function test_sheet_and_excel_cells_are_read_forgivingly()
    {
        // An old tracker's row: one DR, one SI, the amount only as a total.
        $fields = Record::fromRow(self::row([
            'company' => 'Cityland', 'sold_to' => 'Pines Peak', 'po_number' => 'PO 1', 'delivery_status' => 'delivered',
            'si_date' => '2026-10-16', 'si_number' => 194651, 'terms_days' => '30 days', 'amount' => '₱1,234.50', 'paid' => 'PAID',
        ]));
        $this->assertSame(30, $fields['terms_days']);
        $this->assertSame([['date' => null, 'number' => null, 'status' => 'Delivered']], $fields['drs']);
        $this->assertSame([['date' => '2026-10-16', 'number' => '194651', 'amount' => 1234.5]], $fields['sis']);
        $this->assertTrue($fields['paid']);
        $this->assertSame(0, Record::fromRow(self::row(['terms_days' => 'COD']))['terms_days']);
        $this->assertFalse(Record::fromRow(self::row(['paid' => 'unpaid']))['paid']);

        // Several DRs and SIs, one per line in their cells, each with its own status / amount.
        $record = Record::create([...Record::fromRow(self::row([
            'dr_date' => "2026-10-05\n2026-10-01", 'dr_number' => "DR-1\nDR-2", 'delivery_status' => "Delivered\npending",
            'si_number' => "11\n12", 'si_amount' => "1,000\n500.50",
        ])), 'company' => 'Cityland']);
        $this->assertSame(['2026-10-01', 'DR-1, DR-2', 'Partial'], [$record->dr_date->toDateString(), $record->dr_number, $record->delivery_status]);
        $this->assertSame(['11, 12', 1500.5], [$record->si_number, $record->amount]);
        $this->assertSame(["2026-10-05\n2026-10-01", "DR-1\nDR-2", "Delivered\nNot delivered"], array_slice($record->toRow(), self::col('dr_date'), 3));
        $this->assertSame(["11\n12", "₱1,000.00\n₱500.50", 1500.5], [$record->toRow()[self::col('si_number')], $record->toRow()[self::col('si_amount')], $record->toRow()[self::col('amount')]]);
    }

    public function test_an_export_imports_back_the_same()
    {
        Record::create($this->invoice());
        Record::create($this->invoice([...$this->si('9', 10), 'paid' => true, 'or_date' => '2026-11-02', 'terms_days' => 0]));
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        RecordWorkbook::save(Record::orderBy('id')->get(), $path);

        $rows = RecordWorkbook::read($path);
        unlink($path);
        $this->assertCount(2, $rows);
        $this->assertEquals($this->invoice(), $rows[0]);
        $this->assertSame(['2026-11-02', true, 0], [$rows[1]['or_date'], $rows[1]['paid'], $rows[1]['terms_days']]);
    }
}
