<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Google Sheets sync that changed something (or failed). */
class SyncLog extends Model
{
    protected $attributes = ['added' => 0, 'updated' => 0, 'deleted' => 0, 'pushed' => 0];
}
