// Upload page JS

async function fetchJSON(url) {
  const res = await fetch(url, { cache: 'no-store' });
  const text = await res.text();
  try {
    return { ok: res.ok, status: res.status, data: JSON.parse(text) };
  } catch {
    throw new Error(`${url} returned non-JSON (HTTP ${res.status}). Raw: ${text.slice(0, 200)}`);
  }
}

function setStatus(msg, isError = false) {
  const el = document.getElementById('upload-status');
  if (!el) return;
  el.textContent = msg;
  el.style.color = isError ? '#b00020' : '#0b5ed7';
}

async function loadCategories() {
  const select = document.getElementById('category');
  if (!select) return;

  select.innerHTML = '';

  try {
    const payload = await fetchJSON('api/category.php');
    const list = Array.isArray(payload.data)
      ? payload.data
      : (payload.data && Array.isArray(payload.data.data) ? payload.data.data : []);

    if (!list.length) {
      select.innerHTML = '<option value="">No categories</option>';
      return;
    }

    // If API returns {id,name} use those keys; otherwise keep raw.
    list.forEach(c => {
      const id = c.id ?? c.category_id ?? c.categoryId ?? c[0];
      const name = c.name ?? c.category_name ?? c.categoryName ?? c[1] ?? String(id);
      const opt = document.createElement('option');
      opt.value = id;
      opt.textContent = name;
      select.appendChild(opt);
    });
  } catch (e) {
    console.error('loadCategories error:', e);
    select.innerHTML = '<option value="">Failed to load categories</option>';
  }
}

async function uploadDocument() {
  const fileInput = document.getElementById('document-file');
  const categorySelect = document.getElementById('category');
  const signerNameInput = document.getElementById('signer-name');

  const file = fileInput?.files?.[0];
  const categoryId = categorySelect?.value;
  const signerName = signerNameInput?.value?.trim();

  if (!file) return setStatus('Select a PDF file first.', true);
  if (!categoryId) return setStatus('Select a category.', true);
  if (!signerName) return setStatus('Enter signer name.', true);

  setStatus('Uploading...', false);

  const formData = new FormData();
  formData.append('document', file);
  formData.append('category_id', categoryId);
  formData.append('category_name', categorySelect?.selectedOptions?.[0]?.textContent?.trim() || '');
  formData.append('signer_name', signerName);


  try {
    const res = await fetch('api/upload.php', {
      method: 'POST',
      body: formData
    });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`Upload failed (non-JSON). HTTP ${res.status}. Raw: ${text.slice(0,200)}`);
    }

    if (!res.ok || (data && data.error)) {
      const msg = data?.error || `Upload failed (HTTP ${res.status})`;
      return setStatus(msg, true);
    }

    setStatus('Upload successful!', false);
  } catch (e) {
    console.error('uploadDocument error:', e);
    setStatus(e.message || 'Upload failed.', true);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  loadCategories();
  const uploadBtn = document.getElementById('upload-btn');
  if (uploadBtn) uploadBtn.addEventListener('click', uploadDocument);
});

