<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Activity log: a record added, edited or deleted, by whom (null = in the Google Sheet), with what changed. */
class Activity extends Model
{
    protected $fillable = ['user_id', 'record_id', 'action', 'summary', 'changes'];

    protected $casts = ['changes' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Call before saving an edit (it reads the record's dirty fields) or after adding / before deleting. */
    public static function log(?User $user, string $action, Record $record): ?self
    {
        $changes = null;
        if ($action === 'edited') {
            $changes = [];
            foreach ([...Record::FIELDS, 'paid_confirmed'] as $field) {
                if ($record->isDirty($field)) {
                    $changes[$field] = [self::show($record->getOriginal($field)), self::show($record->{$field})];
                }
            }
            if (! $changes) {
                return null;
            }
        }

        return self::create(['user_id' => $user?->id, 'record_id' => $record->id, 'action' => $action, 'summary' => $record->label(), 'changes' => $changes]);
    }

    private static function show(mixed $v): mixed
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
    }
}
