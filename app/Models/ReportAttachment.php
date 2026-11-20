<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAttachment extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['report_id', 'uploaded_by', 'source', 'kind', 'telegram_file_id', 'disk', 'path', 'mime', 'size', 'download_status'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
