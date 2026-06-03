// Basic fill-gaps placeholder logic

function setStatus(msg, isError = false) {
  const el = document.getElementById('status');
  if (!el) return;
  el.textContent = msg;
  el.style.color = isError ? '#b00020' : '#0b5ed7';
}

function getQueryParam(name) {
  try {
    return new URLSearchParams(window.location.search).get(name);
  } catch {
    return null;
  }
}

window.addEventListener('DOMContentLoaded', () => {
  const meta = document.getElementById('meta');
  const docId = getQueryParam('id');
  if (meta) meta.textContent = docId ? `Document ID: ${docId}` : 'No document id provided in URL.';

  const saveBtn = document.getElementById('save-btn');
  if (saveBtn) {
    saveBtn.addEventListener('click', () => {
      // Placeholder: your real implementation should call api/fill.php.
      setStatus('Saved locally (placeholder). Wire this to api/fill.php.', false);
    });
  }

  const clearBtn = document.getElementById('clear-signature');
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (typeof window.clearSignature === 'function') window.clearSignature();
      const canvas = document.getElementById('signature-pad');
      if (canvas) {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
    });
  }
});

