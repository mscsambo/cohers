<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notification\NotificationService;
use App\Services\Telegram\TelegramLinkService;
use App\Support\BotText;
use Illuminate\Http\Request;

/** "My Telegram": every dashboard user links their own chat and picks which alerts they get. */
class AccountController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function telegram(Request $request)
    {
        $user = $request->user()->load('telegramAccount', 'scopes');

        return view('account.telegram', [
            'user' => $user,
            'account' => $user->telegramAccount,
            'events' => User::STAFF_EVENTS,
            'link' => session('link'),
            'linkExpires' => session('link_expires'),
            'botUsername' => ltrim((string) config('cohers.telegram.bot_username'), '@'),
            'botLive' => (bool) config('cohers.telegram.bot_token'),
        ]);
    }

    public function link(Request $request, TelegramLinkService $links)
    {
        $issued = $links->issue($request->user(), $request->user());

        return back()->with('link', $issued['link'])->with('link_expires', $issued['expires_at']->toIso8601String());
    }

    /** Polled by the page so it flips to "connected" as soon as the bot confirms. */
    public function status(Request $request)
    {
        $acc = $request->user()->telegramAccount()->first();

        return response()->json([
            'linked' => (bool) $acc && $acc->status === 'active',
            'username' => $acc?->username,
            'linked_at' => $acc?->linked_at?->toIso8601String(),
        ]);
    }

    public function unlink(Request $request)
    {
        $acc = $request->user()->telegramAccount;
        if ($acc) {
            $this->audit->record('telegram.unlinked', $request->user(), $request->user(), 'Self-service from dashboard',
                ['telegram_user_id' => $acc->telegram_user_id]);
            $acc->delete();
        }

        return back()->with('status', 'Telegram disconnected. You will no longer receive alerts.');
    }

    public function preferences(Request $request)
    {
        $user = $request->user();
        $prefs = [];
        foreach (array_keys(User::STAFF_EVENTS) as $event) {
            $prefs[$event] = $request->boolean("events.{$event}");
        }
        // District officers cannot silence escalations for their own districts (safety rule).
        if ($user->isDistrictOfficer()) {
            $prefs['escalation'] = true;
        }

        $old = $user->notification_prefs;
        $user->forceFill(['notification_prefs' => $prefs])->save();
        $this->audit->record('account.notification_prefs', $user, $user, null, ['prefs' => $old], ['prefs' => $prefs]);

        return back()->with('status', 'Notification preferences saved.');
    }

    public function test(Request $request, NotificationService $notify)
    {
        $user = $request->user();
        if (! $user->telegramChatId()) {
            return back()->withErrors(['telegram' => 'Connect Telegram first.']);
        }
        $notify->toUser($user, 'staff.test', fn ($loc) => BotText::t('staff.test', $loc, ['name' => e($user->name)]),
            'staff-test:'.$user->id.':'.now()->timestamp);

        return back()->with('status', 'Test message queued — it arrives within seconds if the queue worker is running.');
    }
}
