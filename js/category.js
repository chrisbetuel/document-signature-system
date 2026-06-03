// Create category JS

async function fetchJSON(url, options) {
  const res = await fetch(url, options);
  const text = await res.text();
  try {
    return { ok: res.ok, status: res.status, data: JSON.parse(text) };
  } catch {
    throw new Error(`${url} returned non-JSON (HTTP ${res.status}). Raw: ${text.slice(0,200)}`);
  }
}

function setStatus(msg, isError = false) {
  const el = document.getElementById('category-status');
  if (!el) return;
  el.textContent = msg;
  el.style.color = isError ? '#b00020' : '#0b5ed7';
}

async function createCategory() {
  const input = document.getElementById('category-name');
  const name = input?.value?.trim();
  if (!name) return setStatus('Enter a category name.', true);

  setStatus('Creating...', false);

  const formData = new FormData();
  formData.append('category_name', name);

  try {
    const res = await fetch('api/category.php', { method: 'POST', body: formData });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`api/category.php returned non-JSON. HTTP ${res.status}. Raw: ${text.slice(0,200)}`);
    }

    if (!res.ok || (data && data.error)) {
      const msg = data?.error || `Create failed (HTTP ${res.status})`;
      return setStatus(msg, true);
    }

    setStatus('Category created!', false);
    if (input) input.value = '';
  } catch (e) {
    console.error('createCategory error:', e);
    setStatus(e.message || 'Create failed.', true);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('create-category-btn');
  if (btn) btn.addEventListener('click', createCategory);
});

