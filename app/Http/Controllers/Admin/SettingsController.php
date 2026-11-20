<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Priority;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Support\BotText;
use Illuminate\Http\Request;

/** Language & Settings: bot copy (Khmer/English), privacy text, response targets, feature flags. */
class SettingsController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function edit()
    {
        $messages = [];
        foreach (BotText::EDITABLE as $key) {
            foreach (['km', 'en'] as $loc) {
                $messages[$key][$loc] = BotText::t($key, $loc);
            }
        }

        return view('admin.settings', [
            'messages' => $messages,
            'targets' => collect(Priority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->ackMinutes()]),
            'incentives' => (bool) SystemSetting::get_value('incentives_enabled', false),
            'requireMfa' => (bool) SystemSetting::get_value('require_mfa', config('cohers.require_mfa')),
            'verificationHours' => (int) SystemSetting::get_value('verification_target_hours', 48),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'targets' => 'required|array',
            'targets.*' => 'required|integer|min:1|max:10080',
            'messages' => 'array',
            'messages.*.*' => 'nullable|string|max:4000',
            'incentives_enabled' => 'nullable|boolean',
            'require_mfa' => 'nullable|boolean',
            'verification_target_hours' => 'required|integer|min:1|max:720',
            'reason' => 'nullable|string|max:500',
        ]);

        $changes = [];
        $set = function (string $key, $value, string $type, string $group) use (&$changes) {
            $s = SystemSetting::firstOrNew(['key' => $key]);
            if ((string) $s->value !== (string) $value) {
                $changes[$key] = ['old' => $s->value, 'new' => (string) $value];
                $s->fill(['value' => (string) $value, 'type' => $type, 'group' => $group])->save();
            }
        };

        foreach (Priority::cases() as $p) {
            $set('ack_targets.'.$p->value, (int) $data['targets'][$p->value], 'int', 'targets');
        }
        foreach ($data['messages'] ?? [] as $key => $locs) {
            if (! in_array($key, BotText::EDITABLE, true)) {
                continue;
            }
            foreach ($locs as $loc => $text) {
                if (in_array($loc, ['km', 'en'], true)) {
                    // Browsers submit textarea line breaks as CRLF; compare and store with LF only.
                    $text = str_replace("\r\n", "\n", (string) $text);
                    $default = __('bot.'.$key, [], $loc);
                    $set("msg.{$loc}.{$key}", trim($text) === trim($default) ? '' : $text, 'text', 'messages');
                }
            }
        }
        $set('incentives_enabled', $request->boolean('incentives_enabled') ? '1' : '0', 'bool', 'features');
        $set('require_mfa', $request->boolean('require_mfa') ? '1' : '0', 'bool', 'security');
        $set('verification_target_hours', (int) $data['verification_target_hours'], 'int', 'targets');

        if ($changes) {
            $this->audit->record('settings.updated', null, $request->user(), $data['reason'] ?? null,
                collect($changes)->map->old->all(), collect($changes)->map->new->all());
        }

        return back()->with('status', count($changes).' setting(s) saved.');
    }
}
