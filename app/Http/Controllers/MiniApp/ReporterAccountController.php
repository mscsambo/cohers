<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\GeographicUnit;
use App\Models\IncentiveTransaction;
use App\Models\Report;
use App\Models\SystemSetting;
use App\Models\TelegramSession;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Mini App "Location" and "Profile" tabs for community reporters. The saved village
 * and the language are the same ones the bot uses, so a change shows in both.
 */
class ReporterAccountController extends Controller
{
    public function __construct(private AuditService $audit) {}

    private function start(Request $request): void
    {
        abort_unless($request->user()->isActive(), 403);
        App::setLocale($request->user()->locale());
    }

    /** The bot's conversation record for this person (holds the saved village). */
    public static function botSession(\App\Models\User $user): ?TelegramSession
    {
        $tgId = $user->telegramAccount?->telegram_user_id;

        return $tgId ? TelegramSession::firstOrCreate(['telegram_user_id' => $tgId], ['chat_id' => $user->telegramAccount->private_chat_id ?? $tgId, 'state' => 'idle', 'data' => []]) : null;
    }

    /** Saved village of the reporter, if it still exists. */
    public static function savedVillage(\App\Models\User $user): ?GeographicUnit
    {
        $id = static::botSession($user)?->get('default_village_id');

        return $id ? GeographicUnit::where('is_active', true)->find($id) : null;
    }

    public function location(Request $request)
    {
        $this->start($request);
        $user = $request->user()->loadMissing('telegramAccount');
        $locale = app()->getLocale();
        $village = static::savedVillage($user);

        // Their own reports only: GPS pin when given, otherwise the centre of the village (or its commune).
        $pins = Report::where('reporter_id', $user->id)->with('location.parent')->latest('id')->take(200)->get()
            ->map(function (Report $r) {
                $unit = $r->location;
                $lat = $r->latitude ?? $unit?->latitude ?? $unit?->parent?->latitude;
                $lng = $r->longitude ?? $unit?->longitude ?? $unit?->parent?->longitude;

                return $lat && $lng ? [
                    'lat' => (float) $lat, 'lng' => (float) $lng, 'gps' => (bool) $r->latitude,
                    'code' => $r->reference_code, 'status' => __('bot.status.'.$r->status->reporterKey()),
                    'closed' => $r->status === \App\Enums\ReportStatus::CLOSED, 'url' => route('my.show', $r),
                ] : null;
            })->filter()->values();

        return view('miniapp.location', [
            'village' => $village,
            'villagePath' => $village ? $this->path($village, $locale) : null,
            'pins' => $pins,
            'geo' => MyReportsController::geoFor($locale),
            'last' => $village ? MyReportsController::pickerPreset($village, $locale) : null,
        ]);
    }

    public function updateLocation(Request $request)
    {
        $this->start($request);
        $data = $request->validate(['geographic_unit_id' => 'required|integer']);
        $unit = GeographicUnit::where('is_active', true)->whereIn('type', ['village', 'zone', 'commune'])->findOrFail($data['geographic_unit_id']);

        $session = static::botSession($request->user()->loadMissing('telegramAccount'));
        abort_unless($session, 422);
        $session->put(['default_village_id' => $unit->id])->save();

        return redirect()->route('my.location')->with('status', __('bot.miniapp.village_saved'));
    }

    public function profile(Request $request)
    {
        $this->start($request);
        $user = $request->user()->loadMissing('telegramAccount');
        $pointsOn = (bool) SystemSetting::get_value('incentives_enabled', false);

        return view('miniapp.profile', [
            'user' => $user,
            'reportsSent' => Report::where('reporter_id', $user->id)->count(),
            'points' => $pointsOn ? (int) IncentiveTransaction::where('user_id', $user->id)->sum('points') : null,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $this->start($request);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s()-]{6,32}$/'],
            'preferred_language' => 'required|in:km,en',
        ]);
        $user = $request->user();
        $old = $user->only(['name', 'phone', 'preferred_language']);
        $user->update($data);
        static::botSession($user->loadMissing('telegramAccount'))?->put(['locale' => $data['preferred_language']])->save();
        $this->audit->record('reporter.profile_updated', $user, $user, null, $old, $data, 'telegram');

        App::setLocale($user->locale());

        return redirect()->route('my.profile')->with('status', __('bot.miniapp.profile_saved'));
    }

    private function path(GeographicUnit $unit, string $locale): string
    {
        $parts = [];
        for ($u = $unit; $u; $u = $u->parent) {
            $parts[] = $u->name($locale) ?: $u->name_en;
        }

        return implode(' › ', array_reverse($parts));
    }
}
