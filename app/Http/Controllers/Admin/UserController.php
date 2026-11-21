<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OfficerType;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\FocalPointAssignment;
use App\Models\GeographicUnit;
use App\Models\Role;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Account & Roles: provision, suspend, link Telegram, assign role and geographic scope. */
class UserController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $users = User::with('roles', 'telegramAccount', 'scopes')
            ->when($request->role, fn ($q, $v) => $q->whereHas('roles', fn ($r) => $r->where('name', $v)))
            ->when($request->status, fn ($q, $v) => $q->where('account_status', $v))
            ->when($request->boolean('unlinked'), fn ($q) => $q->whereDoesntHave('telegramAccount'))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => Role::all()]);
    }

    public function create()
    {
        return view('admin.users.form', $this->formData(new User));
    }

    public function edit(User $user)
    {
        return view('admin.users.form', $this->formData($user->load('roles', 'scopes', 'telegramAccount', 'focalPointAssignments.geographicUnit')));
    }

    private function formData(User $user): array
    {
        $units = fn (string $type) => GeographicUnit::where('type', $type)->orderBy('name_en')->get(['id', 'parent_id', 'code', 'name_en'])
            ->map(fn ($u) => ['id' => $u->id, 'parent' => $u->parent_id, 'code' => $u->code, 'name' => $u->name_en])->values();

        // Active router mappings as picker rows: one row per location + level, with its sectors.
        $mapped = $user->exists
            ? $user->focalPointAssignments()->with('geographicUnit.parent')->where('is_active', true)->get()
            : collect();
        $areas = $mapped->groupBy(fn ($m) => $m->geographic_unit_id.':'.$m->level)->map(fn ($g) => [
            'unit' => $g->first()->geographic_unit_id,
            'level' => $g->first()->level,
            'sectors' => $g->pluck('sector')->map->value->values()->all(),
        ])->values();

        return [
            'user' => $user,
            'roles' => Role::all(),
            'officerTypes' => OfficerType::cases(),
            'geo' => ['province' => $units('province'), 'district' => $units('district'), 'commune' => $units('commune')],
            // Names for mapped villages, which the picker does not list.
            'extraUnits' => $mapped->pluck('geographicUnit')->filter(fn ($u) => $u && $u->type === 'village')
                ->map(fn ($u) => ['id' => $u->id, 'parent' => $u->parent_id, 'name' => $u->name_en.' (village)'])->values(),
            'areas' => $areas,
        ];
    }

    /** The form sends focal points as one choice, e.g. "focal_point:CAO". */
    private function splitRoleChoice(Request $request): void
    {
        if (str_starts_with((string) $request->input('role'), RoleName::FOCAL_POINT->value.':')) {
            [$role, $type] = explode(':', $request->input('role'), 2);
            $request->merge(['role' => $role, 'officer_type' => $type]);
        }
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => 'nullable|string|max:32',
            'preferred_language' => 'required|in:km,en',
            'account_status' => 'required|in:active,suspended,pending',
            'officer_type' => ['nullable', Rule::enum(OfficerType::class)],
            'role' => 'required|exists:roles,name',
            'scopes' => 'array',
            'scopes.*' => 'exists:geographic_units,id',
            'areas_present' => 'nullable|boolean',
            'areas' => 'array',
            'areas.*.unit' => 'required|exists:geographic_units,id',
            'areas.*.level' => 'required|in:primary,backup',
            'areas.*.sectors' => 'required|array|min:1',
            'areas.*.sectors.*' => ['required', Rule::in(['HUMAN', 'ANIMAL', 'ENVIRONMENT'])],
            'password' => 'nullable|string|min:10|max:100',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function store(Request $request)
    {
        $this->splitRoleChoice($request);
        $data = $this->normalise($request->validate($this->rules()));
        $this->validateRoleRules($data);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create(collect($data)->only(['name', 'email', 'phone', 'preferred_language', 'account_status', 'officer_type', 'password'])->filter()->all());
            $this->syncAccess($user, $data, $request->user());
            $this->audit->record('user.created', $user, $request->user(), $data['reason'] ?? null, null,
                ['roles' => $data['roles'], 'scopes' => $data['scopes'] ?? [], 'officer_type' => $data['officer_type'] ?? null]);

            return $user;
        });

        return redirect()->route('admin.users.edit', $user)->with('status', 'Account created.');
    }

    public function update(Request $request, User $user)
    {
        $this->splitRoleChoice($request);
        $data = $this->normalise($request->validate($this->rules($user)));
        $this->validateRoleRules($data);

        if ($user->id === $request->user()->id && ! in_array(RoleName::SYSTEM_ADMIN->value, $data['roles'], true)) {
            return back()->withErrors(['role' => 'You cannot remove your own administrator role.'])->withInput();
        }

        DB::transaction(function () use ($user, $data, $request) {
            $old = [
                'roles' => $user->roles->pluck('name')->all(),
                'scopes' => $user->scopes->pluck('id')->all(),
                'account_status' => $user->account_status,
                'officer_type' => $user->officer_type?->value,
            ];
            $fill = collect($data)->only(['name', 'email', 'phone', 'preferred_language', 'account_status', 'officer_type'])->all();
            $fill['officer_type'] = $data['officer_type'] ?? null;
            $user->fill($fill);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $this->syncAccess($user, $data, $request->user());

            $this->audit->record('user.updated', $user, $request->user(), $data['reason'] ?? null, $old, [
                'roles' => $data['roles'],
                'scopes' => array_map('intval', $data['scopes'] ?? []),
                'account_status' => $data['account_status'],
                'officer_type' => $data['officer_type'] ?? null,
                'password_changed' => ! empty($data['password']),
            ]);
        });

        return back()->with('status', 'Account updated.');
    }

    /**
     * One role per account: officer type only applies to focal points and
     * district scope only to district officers, so stale values are cleared.
     */
    private function normalise(array $data): array
    {
        $data['roles'] = [$data['role']];
        if ($data['role'] !== RoleName::FOCAL_POINT->value) {
            $data['officer_type'] = null;
        }
        if ($data['role'] !== RoleName::DISTRICT_OFFICER->value) {
            $data['scopes'] = [];
        } else {
            $data['scopes'] = $this->tidyScopes($data['scopes'] ?? []);
        }
        if ($data['role'] !== RoleName::FOCAL_POINT->value) {
            // Only focal points receive routed cases; anyone else loses their router mappings.
            $data['areas'] = [];
            $data['areas_present'] = true;
        }

        return $data;
    }

    /**
     * District-officer area: provinces, districts or communes only, and nothing
     * already covered by a selected parent (a district inside a selected province…).
     */
    private function tidyScopes(array $ids): array
    {
        $units = GeographicUnit::whereIn('id', $ids)->get(['id', 'type', 'parent_id'])->keyBy('id');
        if ($units->contains(fn ($u) => ! in_array($u->type, ['province', 'district', 'commune'], true))) {
            abort(back()->withErrors(['scopes' => 'A district officer\'s area can be a province, district or commune.'])->withInput());
        }
        $parents = GeographicUnit::whereIn('id', $units->pluck('parent_id')->filter())->pluck('parent_id', 'id');

        return $units->reject(fn ($u) => $units->has($u->parent_id) || ($u->type === 'commune' && $units->has($parents[$u->parent_id] ?? 0)))
            ->keys()->all();
    }

    /** Desired router mappings from the form: "unit:sector" => [unit, sector, level]. */
    private function desiredAreas(array $data): array
    {
        $out = [];
        foreach ($data['areas'] ?? [] as $row) {
            foreach (array_unique($row['sectors']) as $sector) {
                $key = $row['unit'].':'.$sector;
                if (isset($out[$key])) {
                    abort(back()->withErrors(['areas' => 'A location is listed twice for the same sector; keep one row (primary or backup).'])->withInput());
                }
                $out[$key] = ['unit' => (int) $row['unit'], 'sector' => $sector, 'level' => $row['level']];
            }
        }

        return $out;
    }

    private function validateRoleRules(array $data): void
    {
        $roles = $data['roles'];
        if (in_array(RoleName::FOCAL_POINT->value, $roles, true) && empty($data['officer_type'])) {
            abort(back()->withErrors(['officer_type' => 'Choose Local Focal Point — VHSG or — CAO.'])->withInput());
        }
        if (in_array(RoleName::DISTRICT_OFFICER->value, $roles, true) && empty($data['scopes'])) {
            abort(back()->withErrors(['scopes' => 'District officers need an area: a province, district or commune.'])->withInput());
        }
        if (in_array(RoleName::FOCAL_POINT->value, $roles, true)) {
            $type = OfficerType::from($data['officer_type']);
            foreach ($this->desiredAreas($data) as $a) {
                if (\App\Enums\Sector::from($a['sector'])->officerType() !== $type) {
                    abort(back()->withErrors(['areas' => "A {$type->value} cannot cover the {$a['sector']} sector (HUMAN → VHSG; ANIMAL and ENVIRONMENT → CAO)."])->withInput());
                }
            }
        }
        $privileged = array_intersect($roles, [RoleName::DISTRICT_OFFICER->value, RoleName::SYSTEM_ADMIN->value]);
        if ($privileged && empty($data['email'])) {
            abort(back()->withErrors(['email' => 'Dashboard users need an email for sign-in.'])->withInput());
        }
    }

    private function syncAccess(User $user, array $data, User $by): void
    {
        $roleIds = Role::whereIn('name', $data['roles'])->pluck('id');
        $user->roles()->syncWithPivotValues($roleIds, ['granted_by' => $by->id]);
        $user->scopes()->sync(collect($data['scopes'] ?? [])->mapWithKeys(fn ($id) => [$id => ['granted_by' => $by->id]]));
        if (! empty($data['areas_present'])) {
            $this->syncAreas($user, $this->desiredAreas($data), $by, $data['reason'] ?? null);
        }
        $user->unsetRelation('roles')->unsetRelation('scopes')->unsetRelation('focalPointAssignments');
    }

    /**
     * Brings the officer's active router mappings in line with the form. Removed or
     * changed mappings are deactivated (kept for history), new ones start today.
     */
    private function syncAreas(User $user, array $desired, User $by, ?string $reason): void
    {
        $today = now()->toDateString();
        $current = $user->focalPointAssignments()->where('is_active', true)->get()
            ->keyBy(fn ($m) => $m->geographic_unit_id.':'.$m->sector->value);

        foreach ($current as $key => $m) {
            if (! isset($desired[$key]) || $desired[$key]['level'] !== $m->level) {
                $m->update(['is_active' => false, 'effective_to' => $today]);
                $this->audit->record('routing.mapping_deactivated', $m, $by, $reason ?? 'Changed on the account page');
            }
        }
        foreach ($desired as $key => $a) {
            if (isset($current[$key]) && $current[$key]->level === $a['level']) {
                continue;
            }
            $m = FocalPointAssignment::create([
                'user_id' => $user->id, 'geographic_unit_id' => $a['unit'], 'sector' => $a['sector'], 'level' => $a['level'],
                'effective_from' => $today, 'is_active' => true, 'created_by' => $by->id,
            ]);
            $this->audit->record('routing.mapping_created', $m, $by, $reason ?? 'Assigned on the account page', null, $a);
        }
    }

    /** Issues a short-lived, single-use Telegram activation link (shown once). */
    public function linkToken(Request $request, User $user)
    {
        abort_unless($user->isActive() && ($user->isFocalPoint() || $user->isPrivileged()), 422, 'Only active officer accounts can be linked.');

        $link = app(\App\Services\Telegram\TelegramLinkService::class)->issue($user, $request->user())['link'];

        return back()->with('link', $link)->with('status', 'Activation link generated. It expires in '.config('cohers.telegram.link_token_ttl_minutes').' minutes and works once.');
    }

    public function unlinkTelegram(Request $request, User $user)
    {
        $request->validate(['reason' => 'required|string|min:3|max:500']);
        $acc = $user->telegramAccount;
        if ($acc) {
            $this->audit->record('telegram.unlinked', $user, $request->user(), $request->reason, ['telegram_user_id' => $acc->telegram_user_id]);
            $acc->delete();
        }

        return back()->with('status', 'Telegram unlinked. Issue a new activation link to relink.');
    }

    public function resetMfa(Request $request, User $user)
    {
        $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null])->save();
        $this->audit->record('auth.mfa_reset', $user, $request->user(), $request->input('reason'));

        return back()->with('status', 'MFA reset. The user must enrol again at next sign-in.');
    }
}
