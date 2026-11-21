<?php

namespace App\Models;

use App\Enums\OfficerType;
use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use \App\Models\Concerns\HasPublicUuid;

    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'preferred_language', 'account_status',
        'officer_type', 'consented_at', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_enabled' => 'boolean',
            'officer_type' => OfficerType::class,
            'notification_prefs' => 'array',
        ];
    }

    /** Telegram alerts dashboard users can opt in or out of (all on by default). */
    public const STAFF_EVENTS = [
        'assignment' => 'New assignments and reassignments to VHSG / CAO',
        'escalation' => 'Escalations, critical and overdue alerts',
        'outcome' => 'Field findings submitted (cases ready for closure)',
    ];

    public function wantsNotification(string $event): bool
    {
        return (bool) (($this->notification_prefs ?? [])[$event] ?? true);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('granted_by')->withTimestamps();
    }

    public function scopes(): BelongsToMany
    {
        return $this->belongsToMany(GeographicUnit::class, 'user_geographic_scopes')->withTimestamps();
    }

    public function telegramAccount(): HasOne
    {
        return $this->hasOne(TelegramAccount::class);
    }

    public function focalPointAssignments(): HasMany
    {
        return $this->hasMany(FocalPointAssignment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function reportAssignments(): HasMany
    {
        return $this->hasMany(ReportAssignment::class, 'focal_point_user_id');
    }

    public function hasRole(RoleName|string ...$roles): bool
    {
        $names = array_map(fn ($r) => $r instanceof RoleName ? $r->value : $r, $roles);

        return $this->roles->pluck('name')->intersect($names)->isNotEmpty();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles->loadMissing('permissions')
            ->flatMap->permissions->pluck('name')->contains($permission);
    }

    public function isActive(): bool
    {
        return $this->account_status === 'active';
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleName::SYSTEM_ADMIN);
    }

    public function isDistrictOfficer(): bool
    {
        return $this->hasRole(RoleName::DISTRICT_OFFICER);
    }

    public function isFocalPoint(): bool
    {
        return $this->hasRole(RoleName::FOCAL_POINT) && $this->officer_type !== null;
    }

    public function isPrivileged(): bool
    {
        return $this->hasRole(RoleName::DISTRICT_OFFICER, RoleName::SYSTEM_ADMIN);
    }

    /** Roles that may sign in to the web dashboard: admin, district officer, and VHSG/CAO focal points. */
    public function canUseDashboard(): bool
    {
        return $this->isPrivileged() || $this->isFocalPoint() || $this->hasRole(RoleName::HEALTH_CENTER);
    }

    /** Cached area scope, keyed to the loaded scopes collection. */
    private ?array $areaCache = null;

    /**
     * District-officer access from the assigned units:
     *  - province → every district in it (including ones added later),
     *  - district → the whole district,
     *  - commune  → only reports from that commune.
     *
     * @return array{districts: int[], communes: int[], partial_districts: int[]}
     */
    public function areaScope(): array
    {
        $units = $this->scopes;
        if ($this->areaCache !== null && $this->areaCache['key'] === spl_object_id($units)) {
            return $this->areaCache['scope'];
        }

        $provinces = $units->where('type', 'province')->pluck('id');
        $districts = $units->where('type', 'district')->pluck('id')
            ->merge($provinces->isEmpty() ? [] : GeographicUnit::where('type', 'district')->whereIn('parent_id', $provinces)->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        // Communes inside an already-covered district add nothing.
        $communes = $units->where('type', 'commune')->reject(fn ($c) => in_array((int) $c->parent_id, $districts, true));

        $scope = [
            'districts' => $districts,
            'communes' => $communes->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'partial_districts' => $communes->pluck('parent_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
        $this->areaCache = ['key' => spl_object_id($units), 'scope' => $scope];

        return $scope;
    }

    /** Districts this user can see at least part of (filters, menus, workload). */
    public function districtIds(): array
    {
        $a = $this->areaScope();

        return array_values(array_unique(array_merge($a['districts'], $a['partial_districts'])));
    }

    /** Whether a report in this district / commune is inside the user's area. */
    public function coversArea(?int $districtId, ?int $communeId): bool
    {
        $a = $this->areaScope();

        return in_array($districtId, $a['districts'], true) || ($communeId && in_array($communeId, $a['communes'], true));
    }

    /** Human-readable area, e.g. "Prey Veng (province), Ba Phnum, Chheu Kach (commune)". */
    public function areaLabel(): string
    {
        return $this->scopes->sortBy(fn ($u) => [array_search($u->type, ['province', 'district', 'commune'], true), $u->name_en])
            ->map(fn ($u) => $u->name_en.($u->type === 'district' ? '' : " ({$u->type})"))->implode(', ');
    }

    public function telegramChatId(): ?int
    {
        $acc = $this->telegramAccount;

        return $acc && $acc->status === 'active' ? $acc->private_chat_id : null;
    }

    public function locale(): string
    {
        return in_array($this->preferred_language, ['km', 'en'], true) ? $this->preferred_language : 'km';
    }

    public function scopeWithRole(Builder $q, RoleName $role): Builder
    {
        return $q->whereHas('roles', fn ($r) => $r->where('name', $role->value));
    }
}
