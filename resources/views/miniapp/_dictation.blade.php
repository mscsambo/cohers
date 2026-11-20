<div>
    <button type="button" class="btn-second" id="dictate">🎙 {{ __('bot.miniapp.dictate') }}</button>
    @if (config('cohers.ai.gemini_api_key'))
        <p class="hint mt-1 text-sm">{{ __('bot.miniapp.dictation_notice') }}</p>
        <button type="button" id="choose-audio" class="link mt-2 underline">{{ __('bot.miniapp.dictation_file') }}</button>
    @endif
    <input type="file" id="dictation-file" accept="audio/*" capture hidden>
    <p class="mt-1 text-sm" id="dictation-status" role="status"></p>
</div>
<script>
(() => {
    const button = document.getElementById('dictate');
    const field = button.form.elements.original_text;
    const picker = document.getElementById('dictation-file');
    document.getElementById('choose-audio')?.addEventListener('click', () => { if (!busy) picker.click(); });
    const status = document.getElementById('dictation-status');
    const label = button.textContent;
    const cloud = @json((bool) config('cohers.ai.gemini_api_key'));
    const language = document.documentElement.lang === 'km' ? 'km-KH' : 'en-US';
    let stopRecording = null;
    let busy = false;
    let leaving = false;
    function reset() {
        busy = false;
        stopRecording = null;
        button.disabled = false;
        button.textContent = label;
    }
    function insert(text) {
        const joined = [field.value.trimEnd(), text.trim()].filter(Boolean).join(' ');
        if (joined.length > field.maxLength) {
            status.textContent = @json(__('bot.miniapp.dictation_long'));
            return;
        }
        field.value = joined;
        field.dispatchEvent(new Event('input', {bubbles: true}));
        status.textContent = @json(__('bot.miniapp.dictation_review'));
    }
    const fallback = @json(__('bot.miniapp.dictation_fallback'));
    async function transcribe(audio) {
        busy = true;
        button.disabled = true;
        status.textContent = @json(__('bot.miniapp.dictation_wait'));
        try {
            if (!audio.size || audio.size > 6 * 1024 * 1024) throw new Error();
            const data = new FormData();
            data.append('audio', audio, 'description');
            const response = await fetch(@js(route('my.dictation')), {
                method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': button.form.elements._token.value}, body: data
            });
            if (!response.ok) throw new Error();
            insert((await response.json()).text);
        } catch { status.textContent = fallback; }
        finally { reset(); }
    }
    picker.onchange = () => {
        const audio = picker.files[0];
        picker.value = '';
        if (audio) transcribe(audio);
    };
    function recording(stop) {
        stopRecording = stop;
        busy = true;
        button.disabled = false;
        button.textContent = @json(__('bot.miniapp.dictation_stop'));
        status.textContent = @json(__('bot.miniapp.dictation_listening'));
    }
    button.onclick = async () => {
        if (stopRecording) { stopRecording(); return; }
        if (busy) return;
        status.textContent = '';
        if (!cloud) {
            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!Recognition) { status.textContent = fallback; field.focus(); return; }
            const recognition = new Recognition();
            recognition.lang = language;
            recognition.onresult = event => insert(event.results[0][0].transcript);
            recognition.onerror = () => { status.textContent = fallback; reset(); };
            recognition.onend = () => { if (status.textContent === @json(__('bot.miniapp.dictation_listening'))) status.textContent = fallback; reset(); };
            try { recognition.start(); recording(() => recognition.stop()); }
            catch { status.textContent = fallback; reset(); }
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { picker.click(); return; }
        busy = true;
        button.disabled = true;
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({audio: true});
            if (leaving) { stream.getTracks().forEach(track => track.stop()); return; }
            const mimeType = ['audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm;codecs=opus']
                .find(type => MediaRecorder.isTypeSupported(type));
            const recorder = new MediaRecorder(stream, mimeType ? {mimeType} : {});
            const chunks = [];
            const stop = () => { if (recorder.state === 'recording') recorder.stop(); };
            const timer = setTimeout(stop, 60000);
            recorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
            recorder.onstop = () => {
                clearTimeout(timer);
                stream.getTracks().forEach(track => track.stop());
                stopRecording = null;
                if (!leaving) transcribe(new Blob(chunks, {type: recorder.mimeType}));
            };
            recorder.start();
            recording(stop);
        } catch {
            stream?.getTracks().forEach(track => track.stop());
            status.textContent = fallback;
            reset();
        }
    };
    button.form.addEventListener('submit', event => {
        if (busy) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    window.addEventListener('pagehide', () => { leaving = true; stopRecording?.(); });
    window.addEventListener('pageshow', () => { if (leaving) reset(); leaving = false; });
})();
</script>
