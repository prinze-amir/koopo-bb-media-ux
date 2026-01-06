(function($){
  'use strict';

  const api = window.koopoBBMU || {};
  const ajaxUrl = api.ajaxUrl || '';
  const userId = api.userId || 0;

  let jcropApi = null;
  let crop = { x:0, y:0, w:0, h:0 };
  let currentMode = null; // 'avatar' | 'cover'
  let uploadedUrl = null;

  function esc(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }


function bustUrl(url){
  if (!url) return url;
  try {
    const u = new URL(url, window.location.href);
    u.searchParams.set('bbmu', String(Date.now()));
    return u.toString();
  } catch(e){
    const sep = url.indexOf('?') === -1 ? '?' : '&';
    return url + sep + 'bbmu=' + Date.now();
  }
}

function refreshAvatarDom(newUrl){
    let updated = false;

    // BuddyBoss Nouveau member header uses: #item-header-avatar and <img class="avatar ...">
    const selectors = [
      '#item-header-avatar img',
      '#item-header-avatar .avatar',
      '#buddypress #item-header-avatar img',
      '#buddypress img.avatar',
      'img.avatar',
      'img[class*="avatar"]',
      '.bb-user-avatar img',
      '.bp-avatar img',
      '.member-avatar img',
      '.profile-avatar img'
    ];

    const seen = new Set();
    selectors.forEach(sel => {
      document.querySelectorAll(sel).forEach(img => {
        if (!img || !img.getAttribute) return;
        if (img.tagName.toLowerCase() !== 'img') return;

        const key = img.src || img.getAttribute('src') || '';
        if (seen.has(img)) return;
        seen.add(img);

        // Only bust likely avatar images to avoid touching unrelated site images
        const src = img.getAttribute('src') || '';
        const isLikelyAvatar = /avatar|avatars|bpfull|bpthumb/i.test(src) || img.className && /avatar/i.test(img.className);

        if (isLikelyAvatar && src) {
          img.setAttribute('src', bustUrl(newUrl || src));
          updated = true;
        }

        // srcset too
        const srcset = img.getAttribute('srcset');
        if (srcset && isLikelyAvatar) {
          const newSet = srcset.split(',').map(s => {
            const parts = s.trim().split(' ');
            if (!parts[0]) return s;
            parts[0] = bustUrl(newUrl || parts[0]);
            return parts.join(' ');
          }).join(', ');
          img.setAttribute('srcset', newSet);
          updated = true;
        }
      });
    });

    return updated;
  }

function refreshCoverDom(newUrl){
    let updated = false;

    // BuddyBoss Nouveau member cover uses: #header-cover-image with <img class="header-cover-img">
    const imgSelectors = [
      '#header-cover-image img.header-cover-img',
      '#header-cover-image img',
      '.header-cover-img'
    ];

    imgSelectors.forEach(sel => {
      document.querySelectorAll(sel).forEach(img => {
        if (!img || img.tagName.toLowerCase() !== 'img') return;
        const src = newUrl || img.getAttribute('src') || img.src;
        if (!src) return;
        img.setAttribute('src', bustUrl(src));
        updated = true;
      });
    });

    // IMPORTANT: To avoid the "horizontal split" seam, remove background images on the cover containers.
    const containersToClear = ['#cover-image-container', '#header-cover-image'];
    containersToClear.forEach(sel => {
      const el = document.querySelector(sel);
      if (el) {
        el.style.backgroundImage = 'none';
      }
    });

    // Also clear any inline background-image on variants
    const more = document.querySelectorAll('.bb-cover-image-container, .bb-enable-cover-img');
    more.forEach(el => {
      if (el && el.style && el.style.backgroundImage) {
        el.style.backgroundImage = 'none';
      }
    });

    // Ensure the cover image fills the area
    document.querySelectorAll('#header-cover-image img.header-cover-img').forEach(img => {
      img.style.width = '100%';
      img.style.height = '100%';
      img.style.objectFit = 'cover';
      img.style.display = 'block';
    });

    return updated;
  }

function finalizeSuccess(mode, newUrl){
  closeModal();
  const ok = (mode === 'cover') ? refreshCoverDom(newUrl) : refreshAvatarDom(newUrl);
if (!ok) window.location.reload();
}


  function ensureModal(){
    let $m = $('#koopo-bbmu-modal');
    if ($m.length) return $m;

    const html = `
      <div id="koopo-bbmu-modal" class="koopo-bbmu-modal" aria-hidden="true">
        <div class="koopo-bbmu-backdrop" data-bbmu-close="1"></div>
        <div class="koopo-bbmu-dialog" role="dialog" aria-modal="true" aria-label="BuddyBoss media editor">
          <button type="button" class="koopo-bbmu-close" data-bbmu-close="1" aria-label="Close">×</button>
          <div class="koopo-bbmu-header">
            <h3 class="koopo-bbmu-title"></h3>
          </div>
          <div class="koopo-bbmu-body"></div>
        </div>
      </div>
    `;
    $('body').append(html);
    $m = $('#koopo-bbmu-modal');

    $m.on('click', '[data-bbmu-close="1"]', function(){ closeModal(); });
    $(document).on('keydown', function(e){
      if (e.key === 'Escape' && $m.hasClass('is-open')) closeModal();
    });

    return $m;
  }

  function openModal(mode){
    currentMode = mode;
    uploadedUrl = null;
    crop = {x:0,y:0,w:0,h:0};
    destroyJcrop();

    const $m = ensureModal();
    $m.addClass('is-open').attr('aria-hidden','false');
    $('body').addClass('koopo-bbmu-open');

    const title = mode === 'cover' ? api.strings.titleCover : api.strings.titleAvatar;
    $m.find('.koopo-bbmu-title').text(title);

    renderUploader(mode);
  }

  function closeModal(){
    const $m = $('#koopo-bbmu-modal');
    if (!$m.length) return;

    destroyJcrop();
    $m.removeClass('is-open').attr('aria-hidden','true');
    $('body').removeClass('koopo-bbmu-open');
    $m.find('.koopo-bbmu-body').empty();
  }

  function destroyJcrop(){
    if (jcropApi) {
      jcropApi.destroy();
      jcropApi = null;
    }
  }

  function renderUploader(mode){
    const $m = ensureModal();

    const body = `
      <div class="koopo-bbmu-step koopo-bbmu-step-upload">
        <div class="koopo-bbmu-actions koopo-bbmu-actions--top">
          <button type="button" class="button koopo-bbmu-choose-existing">Choose from Photos</button>
          <button type="button" class="button koopo-bbmu-take-photo" style="display:none;">Take a Photo</button>
          <button type="button" class="button koopo-bbmu-cancel-btn" data-bbmu-close="1">${esc(api.strings.cancel)}</button>
        </div>

        <div class="koopo-bbmu-dropzone" role="button" tabindex="0">
          <div class="koopo-bbmu-dropzone__inner">
            <div class="koopo-bbmu-dropzone__icon">📷</div>
            <div class="koopo-bbmu-dropzone__text">Drag & drop an image here</div>
            <div class="koopo-bbmu-dropzone__hint">or click Upload</div>
          </div>
          <input type="file" class="koopo-bbmu-file" accept="image/*" />
        </div>

        <div class="koopo-bbmu-preview" style="display:none;">
          <img class="koopo-bbmu-preview__img" alt="Preview" />
        </div>

        <div class="koopo-bbmu-picker" style="display:none;">
          <div class="koopo-bbmu-picker__header">
            <strong>Your Photos</strong>
            <button type="button" class="button koopo-bbmu-picker__back">Back</button>
          </div>
          <div class="koopo-bbmu-picker__grid"></div>
          <div class="koopo-bbmu-picker__footer">
            <button type="button" class="button koopo-bbmu-picker__more">Load more</button>
          </div>
        </div>

        <div class="koopo-bbmu-camera" style="display:none;">
          <div class="koopo-bbmu-camera__header">
            <strong>Camera</strong>
            <button type="button" class="button koopo-bbmu-camera__back">Back</button>
          </div>
          <video class="koopo-bbmu-camera__video" autoplay playsinline></video>
          <div class="koopo-bbmu-camera__actions">
            <button type="button" class="button button-primary koopo-bbmu-camera__capture">Capture</button>
          </div>
          <canvas class="koopo-bbmu-camera__canvas" style="display:none;"></canvas>
        </div>

        <div class="koopo-bbmu-feedback" aria-live="polite"></div>
      </div>
    `;
    $m.find('.koopo-bbmu-body').html(body);

    const $file = $m.find('.koopo-bbmu-file');
    const $dz = $m.find('.koopo-bbmu-dropzone');
    const $previewWrap = $m.find('.koopo-bbmu-preview');
    const $previewImg = $m.find('.koopo-bbmu-preview__img');
    const $picker = $m.find('.koopo-bbmu-picker');
    const $pickerGrid = $m.find('.koopo-bbmu-picker__grid');
    const $camera = $m.find('.koopo-bbmu-camera');
    const $takeBtn = $m.find('.koopo-bbmu-take-photo');

    let pickerPage = 1;

    function showPreview(file){
      try {
        const url = URL.createObjectURL(file);
        $previewImg.attr('src', url);
        $previewWrap.show();
      } catch(e){}
    }

    function showPicker(){
      $dz.hide();
      $camera.hide();
      $previewWrap.hide();
      $picker.show();
      pickerPage = 1;
      $pickerGrid.empty();
      loadPickerPage(true);
    }

    async function loadPickerPage(reset){
      setFeedback('Loading photos…', false);
      const fd = new FormData();
      fd.append('action','koopo_bbmu_list_user_media');
      fd.append('nonce', api.nonceMediaSet);
      fd.append('page', String(pickerPage));
      fd.append('per_page', '24');

      const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
      const json = await res.json();

      if (!json || !json.success) {
        setFeedback((json && json.data && json.data.message) ? json.data.message : api.strings.errorGeneric, true);
        return;
      }

      const items = (json.data && json.data.items) ? json.data.items : [];
      if (!items.length && pickerPage === 1) {
        setFeedback('No photos found yet.', false);
      } else {
        setFeedback('', false);
      }

      items.forEach(it => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'koopo-bbmu-picker__item';
        btn.setAttribute('data-media-id', String(it.media_id));
        btn.innerHTML = '<img alt="" src="'+esc(it.thumb)+'" />';
        btn.addEventListener('click', function(e){
          e.preventDefault();
          const mid = it.media_id;
          if (!mid) return;
          if (mode === 'cover') setCoverFromMedia(mid);
          else prepareAvatarFromMedia(mid);
        });
        $pickerGrid[0].appendChild(btn);
      });

      const hasMore = !!(json.data && json.data.has_more);
      $m.find('.koopo-bbmu-picker__more').toggle(hasMore);
    }

    async function showCamera(){
      $dz.hide();
      $picker.hide();
      $previewWrap.hide();
      $camera.show();

      try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: true });
        const video = $m.find('.koopo-bbmu-camera__video')[0];
        video.srcObject = stream;

        $m.find('.koopo-bbmu-camera__capture').off('click').on('click', async function(){
          const v = video;
          const canvas = $m.find('.koopo-bbmu-camera__canvas')[0];
          canvas.width = v.videoWidth || 640;
          canvas.height = v.videoHeight || 480;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(v, 0, 0, canvas.width, canvas.height);

          const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.92));
          if (!blob) return;

          // Stop stream
          try { stream.getTracks().forEach(t => t.stop()); } catch(e){}

          const file = new File([blob], 'camera.jpg', { type:'image/jpeg' });
          showPreview(file);
          uploadFile(file, mode);
        });

        // back button stops stream
        $m.find('.koopo-bbmu-camera__back').off('click').on('click', function(){
          try { stream.getTracks().forEach(t => t.stop()); } catch(e){}
          $camera.hide();
          $dz.show();
        });

      } catch(err){
        setFeedback('Camera not available or permission denied.', true);
        $camera.hide();
        $dz.show();
      }
    }

    // Show camera button only if supported
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      $takeBtn.show();
    }

    // Upload button opens file picker
    // $m.find('.koopo-bbmu-upload-btn').on('click', function(){
    //   $file[0].click();
    // });

    $m.find('.koopo-bbmu-choose-existing').on('click', function(){
      showPicker();
    });

    $takeBtn.on('click', function(){
      showCamera();
    });

    $m.find('.koopo-bbmu-picker__back').on('click', function(){
      $picker.hide();
      $dz.show();
    });

    $m.find('.koopo-bbmu-picker__more').on('click', function(){
      pickerPage += 1;
      loadPickerPage(false);
    });

    // Dropzone
    $dz.on('click', function(){
            e.preventDefault();

      $file[0].click();
    });

    $dz.on('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') $file[0].click();
    });

    $dz.on('dragover', function(e){
      e.preventDefault();
      e.stopPropagation();
      $dz.addClass('is-dragover');
    });
    $dz.on('dragleave dragend drop', function(e){
      e.preventDefault();
      e.stopPropagation();
      $dz.removeClass('is-dragover');
    });
    $dz.on('drop', function(e){
      const dt = e.originalEvent && e.originalEvent.dataTransfer;
      const file = dt && dt.files && dt.files[0];
      if (!file) return;
      showPreview(file);
      uploadFile(file, mode);
    });

    $file.on('change', function(){
      const file = this.files && this.files[0];
      if (!file) return;
      showPreview(file);
      uploadFile(file, mode);
    });
  }

  function setFeedback(msg, isError){
    const $m = ensureModal();
    const $fb = $m.find('.koopo-bbmu-feedback');
    $fb.text(msg || '');
    $fb.toggleClass('is-error', !!isError);
  }

  async function uploadFile(file, mode){
    setFeedback(api.strings.uploading || 'Uploading…', false);

    const fd = new FormData();
    fd.append('file', file);
    fd.append('_wpnonce', api.nonceUpload);

    fd.append('bp_params[object]', 'user');
    fd.append('bp_params[item_id]', String(userId));
    fd.append('bp_params[item_type]', '');

    // Required by wp_handle_upload() test_form check
    fd.append('action', (mode === 'cover') ? 'bp_cover_image_upload' : 'bp_avatar_upload');
const action = (mode === 'cover') ? 'bp_cover_image_upload' : 'bp_avatar_upload';

    try {
      const res = await fetch(ajaxUrl + '?action=' + encodeURIComponent(action), {
        method:'POST',
        credentials:'same-origin',
        body: fd
      });

      const json = await res.json();

      if (!json || !json.success || !json.data || !json.data.url) {
        setFeedback((json && json.data && json.data.message) ? json.data.message : api.strings.errorGeneric, true);
        return;
      }

      uploadedUrl = json.data.url;

      if (mode === 'cover') {
        // Cover is set server-side on upload. Update DOM in-place.
        finalizeSuccess('cover', uploadedUrl);
      } else {
        renderCropStep(uploadedUrl, json.data.width, json.data.height);
      }

    } catch (e) {
      setFeedback(api.strings.errorGeneric, true);
    }
  }

  function renderCropStep(url, width, height){
    destroyJcrop();
    const $m = ensureModal();

    const body = `
      <div class="koopo-bbmu-step koopo-bbmu-step-crop">
        <div class="koopo-bbmu-crop-wrap">
          <img id="koopo-bbmu-crop-img" class="koopo-bbmu-crop-img" src="${esc(url)}" alt="Crop image" />
        </div>
        <div class="koopo-bbmu-actions">
          <button type="button" class="button button-primary koopo-bbmu-save-crop">${esc(api.strings.setPhoto)}</button>
          <button type="button" class="button koopo-bbmu-back">${esc(api.strings.cancel)}</button>
        </div>
        <div class="koopo-bbmu-feedback" aria-live="polite"></div>
      </div>
    `;
    $m.find('.koopo-bbmu-body').html(body);

    const $img = $('#koopo-bbmu-crop-img');

    // Initialize Jcrop with square selection
    $img.Jcrop({
      aspectRatio: 1,
      setSelect: [ 0, 0, Math.min($img[0].naturalWidth || 200, 200), Math.min($img[0].naturalHeight || 200, 200) ],
      onSelect: function(c){ crop = {x:c.x,y:c.y,w:c.w,h:c.h}; },
      onChange: function(c){ crop = {x:c.x,y:c.y,w:c.w,h:c.h}; }
    }, function(){
      jcropApi = this;
      // Default crop if none chosen
      const b = jcropApi.tellSelect();
      crop = {x:b.x,y:b.y,w:b.w,h:b.h};
    });

    $m.find('.koopo-bbmu-back').on('click', function(){
      destroyJcrop();
      renderUploader('avatar');
    });

    $m.find('.koopo-bbmu-save-crop').on('click', function(){
      saveCroppedAvatar();
    });
  }

  async function saveCroppedAvatar(){
    if (!uploadedUrl) return;

    setFeedback(api.strings.saving || 'Saving…', false);

    const fd = new FormData();
    fd.append('nonce', api.nonceAvatarSet);
    fd.append('object', 'user');
    fd.append('item_id', userId);
    fd.append('item_type', '');
    fd.append('type', 'crop');

    // BuddyBoss/BuddyPress expects the URL here (core avatar.js does this)
    fd.append('original_file', uploadedUrl);

    fd.append('crop_w', Math.round(crop.w));
    fd.append('crop_h', Math.round(crop.h));
    fd.append('crop_x', Math.round(crop.x));
    fd.append('crop_y', Math.round(crop.y));

    try {
      const res = await fetch(ajaxUrl + '?action=bp_avatar_set', {
        method:'POST',
        credentials:'same-origin',
        body: fd
      });

      const json = await res.json();

      if (!json || !json.success) {
        setFeedback((json && json.data && json.data.message) ? json.data.message : api.strings.errorGeneric, true);
        return;
      }

      finalizeSuccess('avatar', (json && json.data && json.data.avatar) ? json.data.avatar : null);
    } catch (e) {
      setFeedback(api.strings.errorGeneric, true);
    }
  }

function isMyProfile(){
  const header = document.querySelector('#item-header[data-bp-item-id]');
  if (!header) return false;
  const id = parseInt(header.getAttribute('data-bp-item-id') || '0', 10);
  return id && id === parseInt(String(userId), 10);
}

function injectPhotoActions(){
  if (!isMyProfile()) return;

  document.querySelectorAll('li.bb-photo-li').forEach(li => {
    if (li.querySelector('.koopo-bbmu-set-avatar')) return;

    const mediaId = li.getAttribute('data-id');
    if (!mediaId) return;

    const ul = li.querySelector('.media-action_list.bb_more_dropdown ul');
    if (!ul) return;

    const liAvatar = document.createElement('li');
    liAvatar.className = 'koopo-bbmu-li koopo-bbmu-li-avatar';
    liAvatar.innerHTML = '<a href="#" class="koopo-bbmu-set-avatar" data-media-id="'+mediaId+'">Set as Profile Photo</a>';

    const liCover = document.createElement('li');
    liCover.className = 'koopo-bbmu-li koopo-bbmu-li-cover';
    liCover.innerHTML = '<a href="#" class="koopo-bbmu-set-cover" data-media-id="'+mediaId+'">Set as Cover Photo</a>';

    // Insert near top (after more-options-view)
    ul.insertBefore(liCover, ul.firstChild);
    ul.insertBefore(liAvatar, ul.firstChild);
  });
}

async function prepareAvatarFromMedia(mediaId){
  openModal('avatar');
  setFeedback('Preparing…', false);

  const fd = new FormData();
  fd.append('action', 'koopo_bbmu_prepare_avatar_from_media');
  fd.append('nonce', api.nonceMediaSet);
  fd.append('media_id', String(mediaId));

  const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
  const json = await res.json();

  if (!json || !json.success || !json.data || !json.data.url) {
    setFeedback((json && json.data && json.data.message) ? json.data.message : api.strings.errorGeneric, true);
    return;
  }

  uploadedUrl = json.data.url;
  renderCropStep(uploadedUrl, json.data.width, json.data.height);
}

async function setCoverFromMedia(mediaId){
  openModal('cover');
  setFeedback('Preparing…', false);

  const fd = new FormData();
  fd.append('action', 'koopo_bbmu_set_cover_from_media');
  fd.append('nonce', api.nonceMediaSet);
  fd.append('media_id', String(mediaId));

  const res = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
  const json = await res.json();

  if (!json || !json.success || !json.data || !json.data.url) {
    setFeedback((json && json.data && json.data.message) ? json.data.message : api.strings.errorGeneric, true);
    return;
  }

  finalizeSuccess('cover', json.data.url);
}

function captureMediaActionClicks(){
  document.addEventListener('pointerdown', function(e){
    const t = e.target && e.target.closest ? e.target.closest('.koopo-bbmu-set-avatar, .koopo-bbmu-set-cover') : null;
    if (!t) return;
    e.preventDefault();
    e.stopPropagation();
    const mediaId = t.getAttribute('data-media-id');
    if (!mediaId) return;
    if (t.classList.contains('koopo-bbmu-set-cover')) setCoverFromMedia(mediaId);
    else prepareAvatarFromMedia(mediaId);
  }, true);
}

  function initInterceptors(){
    // Intercept BuddyBoss "change avatar" and "change cover" links anywhere on the page.
    $(document).on('click', 'a[href*="change-avatar"]', function(e){
      e.preventDefault();
      openModal('avatar');
    });

    $(document).on('click', 'a[href*="change-cover-image"]', function(e){
      e.preventDefault();
      openModal('cover');
    });
  }

  $(function(){ initInterceptors(); injectPhotoActions(); observeMediaGrid(); observeGlobalDom(); captureMediaActionClicks(); });

function observeGlobalDom(){
  const obs = new MutationObserver(function(){
    injectPhotoActions();
  });
  obs.observe(document.body, { childList:true, subtree:true });
}

function observeMediaGrid(){
  const container = document.querySelector('#media-stream, .bb-media-container');
  if (!container) return;

  const obs = new MutationObserver(function(){
    injectPhotoActions();
  });
  obs.observe(container, { childList:true, subtree:true });
}

})(jQuery);
