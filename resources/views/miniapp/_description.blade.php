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
    let active = null;

    function stop() {
        if (active) synth.cancel();
        active = null;
        listen.textContent = readLabel;
    }
    translate.addEventListener('click', async () => {
        stop();
        translate.disabled = true;
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
        }
    });
    synth?.getVoices();
    listen.addEventListener('click', () => {
        if (active) { stop(); return; }
        const voice = synth?.getVoices().find(v => v.lang.toLowerCase().split(/[-_]/)[0] === language);
        const error = @json(__('bot.miniapp.voice_error'));
        if (!synth || !window.SpeechSynthesisUtterance || (!voice && synth.getVoices().length)) {
            feedback.textContent = error;
            return;
        }
        const utterance = new SpeechSynthesisUtterance(translation.hidden ? original : translation.querySelector('p').textContent);
        utterance.lang = language === 'km' ? 'km-KH' : 'en-US';
        if (voice) utterance.voice = voice;
        utterance.rate = 0.9;
        utterance.onend = () => { if (active === utterance) stop(); };
        utterance.onerror = () => {
            if (active === utterance) { stop(); feedback.textContent = error; }
        };
        feedback.textContent = '';
        active = utterance;
        listen.textContent = @json(__('bot.miniapp.stop_reading'));
        synth.speak(utterance);
    });
    window.addEventListener('pagehide', stop);
})();
</script>
