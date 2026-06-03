async function loadDocuments() {
    try {
        const response = await fetch('api/documents.php', { cache: 'no-store' });
        const text = await response.text();
        console.log('documents.php status:', response.status);
        console.log('documents.php raw response:', text);

        let docs;
        try {
            docs = JSON.parse(text);
        } catch (e) {
            throw new Error('api/documents.php did not return valid JSON. Raw response logged above.');
        }

        // If backend returned an error object wrapper, normalize it for UI.
        if (docs && typeof docs === 'object' && docs.success === false && docs.error) {
            throw new Error(docs.error);
        }


        const tbody = document.getElementById('documents-list');
        if (!tbody) throw new Error("Missing element with id 'documents-list' in documents.html");

        tbody.innerHTML = '';

        // If backend returns an object instead of array, normalize.
        const list = Array.isArray(docs) ? docs : (docs && Array.isArray(docs.data) ? docs.data : []);

        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="6">No documents found.</td></tr>';
            return;
        }

        list.forEach(doc => {
            const row = tbody.insertRow();
            row.innerHTML = `
                <td>${doc.original_filename ?? '-'}</td>
                <td>${doc.category_name ?? '-'}</td>
                <td><span class="status-badge status-${doc.status ?? ''}">${doc.status ?? '-'}</span></td>
                <td>${doc.signer_name ?? '-'}</td>
                <td>${doc.signed_at ?? '-'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn btn-view" onclick="viewDocument(${doc.id})">👁️ View</button>
                        <button class="action-btn btn-download" onclick="downloadDocument(${doc.id})">📥 Download</button>
                        <button class="action-btn btn-edit" onclick="editDocument(${doc.id})">✏️ Edit</button>
                        <button class="action-btn btn-whatsapp" onclick="shareWhatsApp(${doc.id})">💬 WhatsApp</button>
                        <button class="action-btn btn-delete" onclick="deleteDocument(${doc.id})">🗑️ Delete</button>
                    </div>
                </td>
            `;
        });
    } catch (err) {
        console.error(err);
        const tbody = document.getElementById('documents-list');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="6" style="color:#b00020;font-weight:600;">${err.message}</td></tr>`;
        }
    }
}

function viewDocument(id) {
    window.open(`api/preview.php?document_id=${id}`, '_blank');
}

function downloadDocument(id) {
    window.location.href = `api/download.php?document_id=${id}`;
}

function editDocument(id) {
    window.location.href = `fill-gaps.html?id=${id}`;
}

function shareWhatsApp(id) {
    fetch(`api/share.php?document_id=${id}&method=whatsapp`)
        .then(response => response.json())
        .then(data => {
            const url = `https://wa.me/?text=${encodeURIComponent(data.share_text)}`;
            window.open(url, '_blank');
        })
        .catch(err => console.error('shareWhatsApp error:', err));
}

function deleteDocument(id) {
    if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.textContent = '⏳ Deleting...';

    fetch(`api/delete-document.php?document_id=${id}`, { method: 'GET' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadDocuments();
            } else {
                alert('Failed to delete: ' + (data.error || 'Unknown error'));
                btn.disabled = false;
                btn.textContent = '🗑️ Delete';
            }
        })
        .catch(err => {
            alert('Error deleting document: ' + err.message);
            btn.disabled = false;
            btn.textContent = '🗑️ Delete';
        });
}

loadDocuments();

