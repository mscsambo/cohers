@extends('layouts.app')
@section('title', 'Configuration')
@section('subtitle', 'Gemini AI, Telegram bot and notification settings · values here override .env')
@section('content')
@php
    $secretField = function (string $key, string $name, string $label, string $placeholder) use ($secrets) {
        return compact('key', 'name', 'label', 'placeholder') + $secrets[$key];
    };
@endphp

<form method="POST" action="{{ route('admin.configuration.update') }}" class="space-y-5" id="configForm">
    @csrf @method('PUT')
    <div class="grid gap-5 xl:grid-cols-2">
        {{-- Gemini --}}
        <x-card title="Gemini AI triage" subtitle="Advisory classification only — never a diagnosis">
            <div class="space-y-4">
                <div>
                    <label class="label">Triage driver</label>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach (['gemini' => ['Gemini API', 'Live Google model'], 'keyword' => ['Keyword (offline)', 'No internet or key needed'], 'none' => ['Disabled', 'Every report → manual triage']] as $v => [$t, $d])
                            <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm {{ $driver === $v ? 'border-teal-500 bg-teal-50' : 'border-slate-200' }}">
                                <input type="radio" name="ai_driver" value="{{ $v }}" @checked($driver === $v) class="mt-0.5">
                                <span><b>{{ $t }}</b><br><span class="text-xs text-slate-500">{{ $d }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>

                @include('admin.partials.secret', ['f' => $secretField('ai.gemini_api_key', 'gemini_api_key', 'Gemini API key', 'AIza…')])
                <p class="-mt-2 text-xs text-slate-500">Get a key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="text-teal-700 hover:underline">aistudio.google.com/apikey</a>. Stored encrypted.</p>

                <div>
                    <label class="label">Model</label>
                    <div class="flex gap-2">
                        <select name="gemini_model" id="geminiModel" class="input">
                            @foreach ($models as $id => $label)<option value="{{ $id }}" @selected($model === $id)>{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" onclick="loadModels()" class="btn btn-ghost whitespace-nowrap" id="loadModelsBtn">Load from Google</button>
                    </div>
                    <p id="modelsMsg" class="mt-1 text-xs text-slate-500">"Load from Google" lists every model your key can use.</p>
                    <input name="gemini_model_custom" class="input mt-2 font-mono text-xs" placeholder="…or type a model ID, e.g. gemini-2.5-flash-preview">
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-3"><label class="label">API endpoint</label><input name="gemini_endpoint" value="{{ $endpoint }}" class="input font-mono text-xs"></div>
                    <div><label class="label">Timeout (seconds)</label><input type="number" name="gemini_timeout" value="{{ $timeout }}" min="3" max="120" class="input"></div>
                    <div class="sm:col-span-2"><label class="label">Min. confidence for auto-routing</label><input type="number" step="0.05" min="0" max="1" name="min_confidence" value="{{ $minConfidence }}" class="input"></div>
                </div>
            </div>
        </x-card>

        {{-- Telegram --}}
        <x-card title="Telegram bot" subtitle="Reporter and focal-point channel">
            <div class="space-y-4">
                @include('admin.partials.secret', ['f' => $secretField('telegram.bot_token', 'telegram_bot_token', 'Bot token (from @BotFather)', '123456789:AA…')])
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">Bot username</label><input name="telegram_bot_username" value="{{ $botUsername }}" class="input" placeholder="cohers_bot"><p class="mt-1 text-xs text-slate-500">Used in officer activation links. "Test connection" fills it automatically.</p></div>
                    <div><label class="label">Activation link lifetime (minutes)</label><input type="number" name="link_ttl" value="{{ $linkTtl }}" class="input"></div>
                </div>
                @include('admin.partials.secret', ['f' => $secretField('telegram.webhook_secret', 'telegram_webhook_secret', 'Webhook secret', 'long random string, 16+ characters')])
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">Max photos per report</label><input type="number" name="max_photos" value="{{ $maxPhotos }}" min="0" max="10" class="input"></div>
                    <div><label class="label">Reference code prefix</label><input name="reference_prefix" value="{{ $prefix }}" class="input font-mono" maxlength="6"><p class="mt-1 text-xs text-slate-500">Applies to new reports only.</p></div>
                </div>
            </div>
        </x-card>
    </div>

    <x-card title="Reporter notifications" subtitle="What case owners receive in Telegram">
        <ul class="mb-4 list-inside list-disc space-y-1 text-sm text-slate-600">
            <li><b>Assigned</b> — sent every time the case is assigned or passed to another VHSG / CAO, with the officer type and location.</li>
            <li><b>Accepted</b> — sent when the officer acknowledges the case.</li>
            <li><b>Under verification, questions, reviewed, closed</b> — sent as the case progresses. Officer notes are never shared.</li>
        </ul>
        <label class="flex items-start gap-3 text-sm">
            <input type="checkbox" name="share_officer_name" value="1" @checked($shareOfficerName) class="mt-1">
            <span><b>Include the officer's name</b> in the assignment alert<br><span class="text-xs text-slate-500">Off by default for privacy. Turn on only if your program policy allows reporters to know which officer handles their case.</span></span>
        </label>
    </x-card>

    <div class="flex flex-wrap items-center gap-3">
        <input name="reason" class="input max-w-md" placeholder="Reason for change (optional, audited)">
        <button class="btn btn-primary">Save configuration</button>
        <span class="text-xs text-slate-500">Leave a secret field empty to keep its current value.</span>
    </div>
</form>

<div class="mt-5 grid gap-5 xl:grid-cols-2">
    <x-card title="Test Gemini" subtitle="Uses the saved key and model · nothing is stored">
        <form method="POST" action="{{ route('admin.configuration.test-gemini') }}" class="space-y-2">
            @csrf
            <textarea name="text" rows="2" class="input" placeholder="Sample report text (optional)"></textarea>
            <button class="btn btn-ghost">Run test call</button>
        </form>
        @if ($r = session('gemini_test'))
            <div class="mt-3 rounded-lg border p-3 text-sm {{ $r['valid'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' }}">
                <b>{{ $r['valid'] ? 'Success' : 'Failed' }}</b> · {{ $r['model'] ?? config('cohers.ai.gemini_model') }} · {{ $r['latency_ms'] ?? '?' }} ms
                @if (! empty($r['error']))<div class="mt-1 break-words text-xs">{{ $r['error'] }}</div>@endif
                @if (! empty($r['result']))<pre class="mt-2 overflow-auto rounded bg-white/70 p-2 text-[11px]">{{ json_encode($r['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
            </div>
        @endif
    </x-card>

    <x-card title="Test Telegram & webhook">
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.configuration.test-telegram') }}">@csrf<button class="btn btn-ghost">Test connection</button></form>
            <form method="POST" action="{{ route('admin.configuration.webhook') }}" class="flex flex-wrap gap-2">@csrf
                <input type="hidden" name="action" value="set">
                <input name="url" class="input w-64 font-mono text-xs" placeholder="https://your-domain (default APP_URL)">
                <button class="btn btn-ghost">Set webhook</button>
            </form>
            <form method="POST" action="{{ route('admin.configuration.webhook') }}" onsubmit="return confirm('Remove the webhook? The bot will only work with polling.')">@csrf
                <input type="hidden" name="action" value="delete"><button class="btn btn-ghost">Delete webhook</button>
            </form>
        </div>
        <p class="mt-2 text-xs text-slate-500">Webhook endpoint: <code class="rounded bg-slate-100 px-1">{{ $webhookUrl }}</code>. On a local PC use polling instead: <code class="rounded bg-slate-100 px-1">php artisan cohers:telegram-poll</code>.</p>
        @if ($t = session('telegram_test'))
            <div class="mt-3 rounded-lg border p-3 text-sm {{ $t['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' }}">
                <b>{{ $t['message'] }}</b>
                @if ($t['ok'])<div class="mt-1 text-xs">Webhook: {{ $t['webhook'] }} · pending updates: {{ $t['pending'] }} @if ($t['last_error']) · last error: {{ $t['last_error'] }} @endif</div>@endif
            </div>
        @endif
    </x-card>
</div>
@endsection

@push('scripts')
<script>
    async function loadModels() {
        const btn = document.getElementById('loadModelsBtn'), msg = document.getElementById('modelsMsg'), sel = document.getElementById('geminiModel');
        btn.disabled = true; msg.textContent = 'Loading…';
        try {
            const res = await fetch(@json(route('admin.configuration.gemini-models')), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || res.status);
            const current = sel.value;
            sel.innerHTML = data.models.map(m => `<option value="${m.id}" ${m.id === current ? 'selected' : ''}>${m.id} — ${m.label}</option>`).join('');
            msg.textContent = `${data.models.length} models available for this key. Remember to save.`;
        } catch (e) {
            msg.textContent = 'Could not load models: ' + e.message;
            msg.className = 'mt-1 text-xs text-rose-600';
        } finally { btn.disabled = false; }
    }
</script>
@endpush
