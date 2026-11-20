<p id="case-description" class="whitespace-pre-line break-words text-base leading-relaxed">{{ $report->original_text }}</p>
<div id="case-translation" class="mt-3" hidden>
    <h2 class="font-semibold">{{ __('bot.miniapp.translation') }}</h2>
    <p class="whitespace-pre-line break-words leading-relaxed"></p>
</div>
<div class="mt-3 grid gap-2">
    <button type="button" class="btn-second" id="translate-case">{{ __('bot.miniapp.translate_case') }}</button>
    <button type="button" class="btn-second" id="listen-case">{{ __('bot.miniapp.read_aloud') }}</button>
</div>
<p id="case-feedback" role="status" class="text-sm"></p>
@if (config('cohers.ai.gemini_api_key'))
    <button hidden type="button" class="btn-second mt-2" id="cloud-read">{{ __('bot.miniapp.cloud_read') }}</button>
    <audio hidden controls class="mt-2 w-full" id="case-audio"></audio>
@endif
<script>
(() => {
    const original = document.getElementById('case-description').textContent.trim();
    const language = document.documentElement.lang;
    const translation = document.getElementById('case-translation');
    const translate = document.getElementById('translate-case');
    const listen = document.getElementById('listen-case');
    const feedback = document.getElementById('case-feedback');
    const synth = window.speechSynthesis;
    const readLabel = listen.textContent;
    const cloud = document.getElementById('cloud-read');
    const audio = document.getElementById('case-audio');
    let audioUrl;
    let generation = 0;
    let active = null;

    function resetAudio() {
        generation++;
        if (!cloud) return;
        cloud.disabled = false;
        cloud.hidden = true;
        audio.pause();
        audio.removeAttribute('src');
        audio.hidden = true;
        if (audioUrl) URL.revokeObjectURL(audioUrl);
        audioUrl = null;
    }
    function unavailable() {
        feedback.textContent = @json(__('bot.miniapp.voice_error'));
        if (cloud) cloud.hidden = false;
    }
    cloud?.addEventListener('click', async () => {
        const current = ++generation;
        cloud.disabled = true;
        feedback.textContent = @json(__('bot.miniapp.audio_loading'));
        try {
            const response = await fetch(@js(route('my.speech', $report)), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'audio/wav, application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                body: JSON.stringify({translated: !translation.hidden})
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('audio/wav')) throw new Error();
            const blob = await response.blob();
            if (current !== generation) return;
            audioUrl = URL.createObjectURL(blob);
            audio.src = audioUrl;
            audio.hidden = false;
            cloud.hidden = true;
            feedback.textContent = '';
            // Telegram on iOS may require a fresh tap after a network request.
            audio.play().catch(() => {});
        } catch {
            if (current === generation) feedback.textContent = @json(__('bot.miniapp.audio_failed'));
        } finally {
            if (current === generation) cloud.disabled = false;
        }
    });
    function stop() {
        if (active) synth.cancel();
        active = null;
        listen.textContent = readLabel;
    }
    translate.addEventListener('click', async () => {
        resetAudio();
        stop();
        translate.disabled = true;
        listen.disabled = true;
        feedback.textContent = @json(__('bot.miniapp.translating'));
        try {
            const response = await fetch(@json(route('my.translation', $report)), {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}
            });
            if (!response.ok) throw new Error();
            const result = await response.json();
            translation.querySelector('p').textContent = result.text;
            translation.hidden = false;
            translate.hidden = true;
            feedback.textContent = '';
            listen.focus();
        } catch {
            feedback.textContent = @json(__('bot.miniapp.translation_error'));
        } finally {
            translate.disabled = false;
            listen.disabled = false;
            offerCloudVoice();
        }
    });
    function offerCloudVoice() {
        if (cloud && !audioUrl && !synth?.getVoices().some(v => v.lang.toLowerCase().split(/[-_]/)[0] === language)) cloud.hidden = false;
    }
    offerCloudVoice();
    listen.addEventListener('click', () => {
        if (active) { stop(); return; }
        const voice = synth?.getVoices().find(v => v.lang.toLowerCase().split(/[-_]/)[0] === language);
        if (audioUrl) { audio.play().catch(() => {}); return; }
        if (!synth || !window.SpeechSynthesisUtterance || (!voice && synth.getVoices().length)) {
            unavailable();
            return;
        }
        const utterance = new SpeechSynthesisUtterance(translation.hidden ? original : translation.querySelector('p').textContent);
        utterance.lang = language === 'km' ? 'km-KH' : 'en-US';
        if (voice) utterance.voice = voice;
        utterance.rate = 0.9;
        utterance.onend = () => { if (active === utterance) stop(); };
        utterance.onerror = () => {
            if (active === utterance) { stop(); unavailable(); }
        };
        feedback.textContent = '';
        active = utterance;
        listen.textContent = @json(__('bot.miniapp.stop_reading'));
        synth.speak(utterance);
    });
    window.addEventListener('pagehide', stop);
    window.addEventListener('pagehide', resetAudio);
})();
</script>
