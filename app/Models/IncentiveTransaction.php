<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Optional, auditable points ledger. Disabled until policy is approved. */
class IncentiveTransaction extends Model
{
    protected $fillable = ['user_id', 'report_id', 'points', 'reason', 'created_by'];
}
