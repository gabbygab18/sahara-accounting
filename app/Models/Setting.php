<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key/value settings changed from the app (e.g. the Google Sheet link and sign-in). */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return self::find($key)?->value;
    }

    public static function set(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
