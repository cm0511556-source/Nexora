<?php /* Nexora — Photos & Videos panel */ ?>
<section class="card" id="nexora-media-panel" style="margin-top:24px;padding:22px;">
  <details id="nx-media-details">
  <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;padding:4px 0;">
    <span style="font-size:1.1rem;font-weight:800;color:#0b1d3a;">📷 Photos &amp; Videos</span>
    <span style="margin-left:auto;font-size:.8rem;font-weight:500;color:#64748b;">tap to open</span>
  </summary>
  <div style="margin-top:12px;">
  <h2 style="margin-top:0;">Photos &amp; Videos</h2>
  <p style="color:#64748b;margin-top:6px;">Upload photos and videos from your phone at any time — during creation, mid-transit, or after an incident. Customers see public items on the tracking page.</p>

  <div style="margin-top:16px;padding:16px;background:#f8fafc;border-radius:10px;">
    <label style="display:block;font-weight:600;font-size:.9rem;color:#334155;margin-bottom:8px;">Add files</label>
    <input type="file" id="nx-media-input" multiple accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/quicktime,video/webm" style="display:block;width:100%;padding:10px;border:1px dashed #94a3b8;border-radius:8px;background:#fff;box-sizing:border-box;">
    <p style="font-size:.8rem;color:#64748b;margin:8px 0 0;">Photos up to 2 MB · Videos up to 20 MB · PNG / JPG / WEBP / GIF / MP4 / MOV / WEBM</p>
    <button type="button" id="nx-media-upload" style="margin-top:12px;padding:11px 20px;border-radius:9px;border:0;background:#0b1d3a;color:#fff;font-weight:600;cursor:pointer;">Upload</button>
    <div id="nx-media-message" role="status" aria-live="polite" style="margin-top:10px;font-size:.9rem;"></div>
  </div>

  <div id="nx-media-list" style="margin-top:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">Loading…</div>
  </div>
  </details>
</section>

<script>
(function(){
  'use strict';
  const panel = document.getElementById('nexora-media-panel');
  if (!panel) return;

  const shipmentId = <?= (int)$shipmentId ?>;
  const csrfToken  = <?= json_encode(csrfToken()) ?>;
  const input  = document.getElementById('nx-media-input');
  const btn    = document.getElementById('nx-media-upload');
  const list   = document.getElementById('nx-media-list');
  const msg    = document.getElementById('nx-media-message');

  function say(t, err) { msg.textContent = t || ''; msg.style.color = err ? '#b91c1c' : '#166534'; }

  function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  async function loadMedia() {
    list.innerHTML = 'Loading…';
    try {
      const r = await fetch('/admin/media-list.php?shipment_id=' + shipmentId, { headers:{'Accept':'application/json'}, cache:'no-store' });
      const j = await r.json();
      const items = (j && Array.isArray(j.data)) ? j.data : [];
      if (!items.length) { list.innerHTML = '<div style="grid-column:1/-1;color:#64748b;font-size:.9rem;">No media yet.</div>'; return; }
      list.innerHTML = items.map(m => {
        const src = '/assets/media/' + encodeURIComponent(m.file_path);
        const thumb = m.media_type === 'photo'
          ? '<img src="' + src + '" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:8px;background:#e2e8f0;">'
          : '<video src="' + src + '" style="width:100%;height:140px;object-fit:cover;border-radius:8px;background:#e2e8f0;" muted></video>';
        return '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px;background:#fff;">' +
          thumb +
          '<input type="text" data-media-id="' + m.id + '" class="nx-media-caption" value="' + esc(m.caption || '') + '" placeholder="Caption" style="width:100%;box-sizing:border-box;padding:6px;margin-top:8px;font-size:.82rem;border:1px solid #cbd5e1;border-radius:6px;">' +
          '<div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">' +
            '<button type="button" class="nx-media-save-cap" data-media-id="' + m.id + '" style="padding:5px 9px;font-size:.78rem;border:1px solid #cbd5e1;background:#fff;border-radius:6px;cursor:pointer;">Save caption</button>' +
            '<button type="button" class="nx-media-toggle" data-media-id="' + m.id + '" style="padding:5px 9px;font-size:.78rem;border:1px solid ' + (Number(m.is_public) === 1 ? '#a7f3d0' : '#fecaca') + ';background:' + (Number(m.is_public) === 1 ? '#ecfdf5' : '#fef2f2') + ';color:' + (Number(m.is_public) === 1 ? '#065f46' : '#991b1b') + ';border-radius:6px;cursor:pointer;">' + (Number(m.is_public) === 1 ? 'Public ✓' : 'Private ✗') + '</button>' +
            '<button type="button" class="nx-media-delete" data-media-id="' + m.id + '" style="padding:5px 9px;font-size:.78rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:6px;cursor:pointer;">Delete</button>' +
          '</div>' +
        '</div>';
      }).join('');
      wire();
    } catch (e) {
      list.innerHTML = '<div style="grid-column:1/-1;color:#b91c1c;">Could not load media.</div>';
    }
  }

  function wire() {
    list.querySelectorAll('.nx-media-save-cap').forEach(b => {
      b.addEventListener('click', async () => {
        const id = b.dataset.mediaId;
        const inp = list.querySelector('.nx-media-caption[data-media-id="' + id + '"]');
        const body = new URLSearchParams({ action:'caption', shipment_id:String(shipmentId), media_id:String(id), caption:inp.value, csrf_token:csrfToken });
        b.disabled = true;
        try { const r = await fetch('/admin/media-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}); const j = await r.json(); if (!j.success) throw new Error(j.message||'Failed'); say('Caption saved.', false); } catch(e){ say(e.message, true); }
        b.disabled = false;
      });
    });
    list.querySelectorAll('.nx-media-toggle').forEach(b => {
      b.addEventListener('click', async () => {
        const id = b.dataset.mediaId;
        const body = new URLSearchParams({ action:'toggle', shipment_id:String(shipmentId), media_id:String(id), csrf_token:csrfToken });
        b.disabled = true;
        try { const r = await fetch('/admin/media-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}); const j = await r.json(); if (!j.success) throw new Error(j.message||'Failed'); loadMedia(); } catch(e){ say(e.message, true); b.disabled = false; }
      });
    });
    list.querySelectorAll('.nx-media-delete').forEach(b => {
      b.addEventListener('click', async () => {
        if (!confirm('Delete this file permanently?')) return;
        const id = b.dataset.mediaId;
        const body = new URLSearchParams({ action:'delete', shipment_id:String(shipmentId), media_id:String(id), csrf_token:csrfToken });
        b.disabled = true;
        try { const r = await fetch('/admin/media-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}); const j = await r.json(); if (!j.success) throw new Error(j.message||'Failed'); loadMedia(); } catch(e){ say(e.message, true); b.disabled = false; }
      });
    });
  }

  btn.addEventListener('click', async () => {
    if (!input.files || !input.files.length) { say('Pick at least one file first.', true); return; }
    const fd = new FormData();
    fd.set('action', 'upload');
    fd.set('shipment_id', shipmentId);
    fd.set('csrf_token', csrfToken);
    for (const f of input.files) fd.append('files[]', f);
    btn.disabled = true;
    say('Uploading…', false);
    try {
      const r = await fetch('/admin/media-action.php', { method:'POST', body:fd });
      const j = await r.json();
      if (!j.success) throw new Error(j.message || 'Upload failed');
      say(j.message || 'Uploaded.', false);
      input.value = '';
      loadMedia();
    } catch (e) { say(e.message, true); }
    btn.disabled = false;
  });

  loadMedia();
})();
</script>
