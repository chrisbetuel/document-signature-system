// Dashboard scripts (keep minimal to avoid blank-page failures)

async function fetchJSON(url) {
  const res = await fetch(url, { cache: 'no-store' });
  const text = await res.text();
  try {
    return { ok: res.ok, status: res.status, data: JSON.parse(text) };
  } catch {
    throw new Error(`${url} returned non-JSON (HTTP ${res.status}). Raw: ${text.slice(0, 200)}`);
  }
}

async function loadDashboardCounts() {
  try {
    // These endpoints may not exist yet in your project; guard so the UI doesn’t blank.
    // If they do exist, fill the counters.
    const [docs, cats] = await Promise.allSettled([
      fetchJSON('api/documents.php'),
      fetchJSON('api/category.php')
    ]);

    // Defaults
    const totalDocsEl = document.getElementById('total-docs');
    const signedDocsEl = document.getElementById('signed-docs');
    const totalCatsEl = document.getElementById('total-cats');
    const pendingDocsEl = document.getElementById('pending-docs');

    if (!totalDocsEl || !signedDocsEl || !totalCatsEl || !pendingDocsEl) return;

    totalDocsEl.textContent = '0';
    signedDocsEl.textContent = '0';
    totalCatsEl.textContent = '0';
    pendingDocsEl.textContent = '0';

    const normalizeDocs = (settled) => {
      if (settled.status !== 'fulfilled') return [];
      const payload = settled.value.data;
      if (Array.isArray(payload)) return payload;
      if (payload && Array.isArray(payload.data)) return payload.data;
      return [];
    };

    const docsList = normalizeDocs(docs);

    totalDocsEl.textContent = String(docsList.length);
    const signed = docsList.filter(d => String(d.status || '').toLowerCase() === 'signed').length;
    signedDocsEl.textContent = String(signed);
    pendingDocsEl.textContent = String(Math.max(0, docsList.length - signed));

    if (cats.status === 'fulfilled') {
      const payload = cats.value.data;
      const list = Array.isArray(payload) ? payload : (payload && Array.isArray(payload.data) ? payload.data : []);
      totalCatsEl.textContent = String(list.length);
    }

    // Load documents list if it exists
    if (typeof loadDocuments === 'function') {
      loadDocuments();
    } else {
      // Lazy-load documents page JS if present
      const script = document.createElement('script');
      script.src = '/document-signature-system/js/documents.js';
      document.body.appendChild(script);
    }

  } catch (e) {
    console.error('loadDashboardCounts error:', e);
  }
}

function refreshDocuments() {
  if (typeof loadDocuments === 'function') loadDocuments();
}

window.addEventListener('DOMContentLoaded', loadDashboardCounts);

