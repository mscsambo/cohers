<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Groups several independent eyewitness reports describing one event. */
class IncidentCase extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['code', 'title', 'category_id', 'district_id', 'status', 'created_by'];

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class, 'report_incident_links')->withPivot('linked_by', 'reason')->withTimestamps();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'district_id');
    }
}
