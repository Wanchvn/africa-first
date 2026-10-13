(() => {
  const btn      = document.getElementById('btn-record');
  const statusEl = document.getElementById('rec-status');
  const preview  = document.getElementById('voice-preview');
  const audio    = document.getElementById('voice-audio');
  const durInput = document.getElementById('voice-duration');
  const discard  = document.getElementById('btn-discard');
  const form     = document.getElementById('compose-form');

  if (!btn || !form) return;

  const MAX_SECONDS = 180;
  let mediaRecorder = null;
  let chunks = [];
  let startTime = 0;
  let timer = null;
  let blob = null;

  function pickMime() {
    const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
    for (const c of candidates) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported(c)) return c;
    }
    return '';
  }

  btn.addEventListener('click', async () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      mediaRecorder.stop();
      return;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
      alert('Recording is not supported in this browser.');
      return;
    }

    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      alert('Microphone permission denied.');
      return;
    }

    const mime = pickMime();
    mediaRecorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
    chunks = [];
    startTime = Date.now();

    mediaRecorder.ondataavailable = e => e.data.size && chunks.push(e.data);

    mediaRecorder.onstop = () => {
      stream.getTracks().forEach(t => t.stop());
      clearInterval(timer);

      const seconds = Math.max(1, Math.round((Date.now() - startTime) / 1000));
      blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });

      audio.src = URL.createObjectURL(blob);
      preview.hidden = false;
      durInput.value = seconds;
      statusEl.textContent = '';
      btn.textContent = '🎙️';
      btn.classList.remove('recording');
    };

    mediaRecorder.start();
    btn.textContent = '⏹';
    btn.classList.add('recording');

    timer = setInterval(() => {
      const s = Math.round((Date.now() - startTime) / 1000);
      statusEl.textContent = `${s}s / ${MAX_SECONDS}s`;
      if (s >= MAX_SECONDS) mediaRecorder.stop();
    }, 250);
  });

  discard?.addEventListener('click', () => {
    blob = null;
    preview.hidden = true;
    durInput.value = 0;
    audio.src = '';
  });

  form.addEventListener('submit', () => {
    if (!blob) return;
    const ext = blob.type.includes('mp4') ? 'm4a'
              : blob.type.includes('ogg') ? 'ogg'
              : 'webm';
    const file = new File([blob], `voice.${ext}`, { type: blob.type });
    const dt = new DataTransfer();
    dt.items.add(file);

    // Remove previous hidden input (in case of re-submit)
    form.querySelectorAll('input[name="voice"]').forEach(n => n.remove());

    const input = document.createElement('input');
    input.type = 'file';
    input.name = 'voice';
    input.files = dt.files;
    input.style.display = 'none';
    form.appendChild(input);
  });
})();