(() => {
  if (!window.KoopoStoriesUI) return;

  const {
    API_BASE,
    t,
    apiGet,
    apiPost,
    el,
    refreshTray,
  } = window.KoopoStoriesUI;

  async function uploader() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*,video/*';
    input.style.position = 'fixed';
    input.style.left = '-9999px';
    input.style.top = '0';
    document.body.appendChild(input);
    input.onchange = () => {
      const file = input.files && input.files[0];
      if (!file) {
        input.remove();
        return;
      }
      openComposer(file);
      input.value = '';
      input.remove();
    };
    input.onclick = () => {
      // Reset so selecting the same file still triggers change on mobile.
      input.value = '';
    };
    input.click();
  }

  function openComposer(file, options = {}) {
    let composerFile = file;
    // Simple preview + confirm composer (MVP+)
    const overlay = el('div', { class: 'koopo-stories__composer', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title', html: 'Post a story' });

    const preview = el('div', {
      class: 'koopo-stories__composer-preview',
      style: 'position:relative;display:flex;align-items:center;justify-content:center;'
    });
    const previewWrap = el('div', { class: 'koopo-stories__composer-media-wrap', style: 'position:relative;' });
    let url = URL.createObjectURL(composerFile);

    let mediaEl;
    if ((composerFile.type || '').startsWith('video/')) {
      mediaEl = document.createElement('video');
      mediaEl.src = url;
      mediaEl.muted = true;
      mediaEl.playsInline = true;
      mediaEl.controls = true;
      mediaEl.autoplay = true;
    } else {
      mediaEl = document.createElement('img');
      mediaEl.src = url;
      mediaEl.alt = '';
    }
    previewWrap.appendChild(mediaEl);
    preview.appendChild(previewWrap);
    const syncPreviewWrapSize = () => {
      const rect = mediaEl.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      previewWrap.style.width = `${rect.width}px`;
      previewWrap.style.height = `${rect.height}px`;
    };
    const scheduleSyncPreviewWrap = () => {
      requestAnimationFrame(() => {
        requestAnimationFrame(syncPreviewWrapSize);
      });
    };
    if (mediaEl.tagName === 'IMG') {
      mediaEl.addEventListener('load', scheduleSyncPreviewWrap);
    } else {
      mediaEl.addEventListener('loadedmetadata', scheduleSyncPreviewWrap);
      mediaEl.addEventListener('loadeddata', scheduleSyncPreviewWrap);
    }
    window.addEventListener('resize', syncPreviewWrapSize);
    preview.addEventListener('pointerdown', (e) => {
      if (e.target === preview || e.target === mediaEl) {
        previewWrap.querySelectorAll('.koopo-stories__sticker.is-active').forEach((node) => node.classList.remove('is-active'));
      }
    });

    // Sticker toolbar (compact: @, Aa, Sticker Menu)
    const stickerToolbar = el('div', { class: 'koopo-stories__composer-toolbar' });
    const stickerMenu = el('div', { class: 'koopo-stories__composer-sticker-menu', hidden: 'hidden' });
    const providerCfg = window.KoopoStoriesStickerProviders || {};
    const stickerMenuItems = [
      { iconClass: 'dashicons dashicons-admin-links', title: 'Link', type: 'link' },
      { iconClass: 'dashicons dashicons-location', title: 'Location', type: 'location' },
      { iconClass: 'dashicons dashicons-chart-bar', title: 'Poll', type: 'poll' },
    ];
    if (providerCfg.giphy && providerCfg.giphy.enabled) {
      stickerMenuItems.push({ iconClass: 'dashicons dashicons-smiley', title: 'GIF', type: 'giphy' });
    }
    if (providerCfg.tenor && providerCfg.tenor.enabled) {
      stickerMenuItems.push({ iconClass: 'dashicons dashicons-format-video', title: 'Tenor', type: 'tenor' });
    }
    if (providerCfg.lottie && providerCfg.lottie.enabled) {
      stickerMenuItems.push({ iconClass: 'dashicons dashicons-smiley', title: 'Lottie', type: 'lottie' });
    }

    const makeCircleTool = (icon, label, clickHandler) => {
      const button = el('button', {
        class: 'koopo-stories__composer-sticker-btn',
        type: 'button',
        'aria-label': label,
        title: label,
      });
      const iconNode = el('span', { class: 'koopo-stories__composer-sticker-btn-icon' });
      if (icon && icon.className) {
        iconNode.className = `koopo-stories__composer-sticker-btn-icon ${icon.className}`;
      } else {
        iconNode.textContent = (icon && icon.text) ? icon.text : '';
      }
      button.appendChild(iconNode);
      button.onclick = clickHandler;
      return button;
    };

    // Array to store stickers to be added
    const pendingStickers = [];
    let stickerCid = 1;
    const assignStickerCid = (sticker) => {
      if (!sticker._cid) sticker._cid = `s_${stickerCid++}`;
      return sticker._cid;
    };

    const addTextSticker = () => {
      const sticker = {
        type: 'text',
        data: {
          text: '...',
          style: {
            text_color: '#ffffff',
            bg_color: '#000000',
            bg_opacity: 0.6,
            font_size: 18,
            font_family: 'inherit',
            font_weight: 'normal',
            font_style: 'normal',
            text_decoration: 'none',
            text_align: 'center',
          },
        },
        position: { x: 50, y: 50 },
      };
      assignStickerCid(sticker);
      pendingStickers.push(sticker);
      const stickerPreview = createStickerElement(sticker, pendingStickers, previewWrap, assignStickerCid);
      if (!stickerPreview) return;
      stickerPreview.style.cursor = 'move';
      previewWrap.appendChild(stickerPreview);
      makeDraggable(stickerPreview, previewWrap, sticker.position);
      const content = stickerPreview.__stickerContent || stickerPreview.querySelector('.koopo-stories__sticker-text');
      if (content) openInlineTextEditor(sticker, content, previewWrap);
    };

    const mentionBtn = makeCircleTool({ text: '@' }, 'Mention', () => {
      stickerMenu.hidden = true;
      openStickerModal('mention', pendingStickers, previewWrap, assignStickerCid, {});
    });
    const textBtn = makeCircleTool({ text: 'Aa' }, 'Text', () => {
      stickerMenu.hidden = true;
      addTextSticker();
    });
    const menuBtn = makeCircleTool({ className: 'dashicons dashicons-sticky' }, 'Stickers', () => {
      stickerMenu.hidden = !stickerMenu.hidden;
    });

    stickerToolbar.appendChild(mentionBtn);
    stickerToolbar.appendChild(textBtn);
    stickerToolbar.appendChild(menuBtn);
    if ((composerFile.type || '').startsWith('image/')) {
      const editBtn = makeCircleTool({ className: 'dashicons dashicons-admin-customizer' }, 'Edit Photo', async () => {
        const edited = await openImageEditorModal(composerFile);
        if (!edited) return;
        try { URL.revokeObjectURL(url); } catch (e) {}
        composerFile = edited;
        url = URL.createObjectURL(composerFile);
        mediaEl.src = url;
        if (typeof scheduleSyncPreviewWrap === 'function') {
          scheduleSyncPreviewWrap();
        }
      });
      stickerToolbar.appendChild(editBtn);
    }

    stickerMenuItems.forEach((item) => {
      const menuBtnItem = el('button', {
        class: 'koopo-stories__composer-sticker-menu-btn',
        type: 'button',
        title: item.title,
        'aria-label': item.title,
      });
      const menuIcon = el('span', { class: item.iconClass || 'dashicons dashicons-admin-generic' });
      menuBtnItem.appendChild(menuIcon);
      menuBtnItem.onclick = () => {
        stickerMenu.hidden = true;
        if (item.type === 'giphy' || item.type === 'tenor' || item.type === 'lottie') {
          openExternalStickerModal(item.type, pendingStickers, previewWrap, assignStickerCid);
          return;
        }
        openStickerModal(item.type, pendingStickers, previewWrap, assignStickerCid, {});
      };
      stickerMenu.appendChild(menuBtnItem);
    });
    panel.addEventListener('pointerdown', (e) => {
      if (!stickerToolbar.contains(e.target) && !stickerMenu.contains(e.target)) {
        stickerMenu.hidden = true;
      }
    });

    // Privacy selector
    const privacyWrap = el('div', { class: 'koopo-stories__composer-privacy' });
    const privacyLabel = el('label', { class: 'koopo-stories__composer-privacy-label' });
    privacyLabel.textContent = 'Who can see this?';
    const privacySelect = el('select', { class: 'koopo-stories__composer-privacy-select' });

    const publicOption = el('option', { value: 'public' });
    publicOption.textContent = 'Public';
    publicOption.selected = true;
    const friendsOption = el('option', { value: 'friends' });
    friendsOption.textContent = 'Friends Only';
    const closeFriendsOption = el('option', { value: 'close_friends' });
    closeFriendsOption.textContent = 'Close Friends';

    privacySelect.appendChild(publicOption);
    privacySelect.appendChild(friendsOption);
    privacySelect.appendChild(closeFriendsOption);

    privacyWrap.appendChild(privacyLabel);
    privacyWrap.appendChild(privacySelect);

    const shareActivityWrap = el('label', { class: 'koopo-stories__composer-privacy-label', style: 'display:flex;align-items:center;gap:8px;margin-top:10px;' });
    const shareActivityCheckbox = el('input', { type: 'checkbox' });
    const shareActivityText = el('span', { html: 'Also share to activity' });
    shareActivityWrap.appendChild(shareActivityCheckbox);
    shareActivityWrap.appendChild(shareActivityText);
    if (window.KoopoStories && window.KoopoStories.shareStoryToActivity) {
      privacyWrap.appendChild(shareActivityWrap);
    }

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel', type: 'button' });
    cancelBtn.textContent = 'Cancel';
    const postBtn = el('button', { class: 'koopo-stories__composer-post', type: 'button' });
    postBtn.textContent = 'Post';
    const status = el('div', { class: 'koopo-stories__composer-status', html: '' });
    status.setAttribute('aria-live', 'polite');

    actions.appendChild(cancelBtn);
    actions.appendChild(postBtn);

    function close() {
      try { URL.revokeObjectURL(url); } catch (e) { }
      overlay.remove();
    }

    cancelBtn.addEventListener('click', close);

    // Process initial stickers
    if (options.stickers && Array.isArray(options.stickers)) {
      setTimeout(() => {
        options.stickers.forEach(s => {
          assignStickerCid(s);
          pendingStickers.push(s);
          const stickerPreview = createStickerElement(s, pendingStickers, previewWrap, assignStickerCid);
          if (stickerPreview) {
            stickerPreview.style.cursor = 'move';
            previewWrap.appendChild(stickerPreview);
            makeDraggable(stickerPreview, previewWrap, s.position);
          }
        });
      }, 100);
    }
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
    });

    postBtn.addEventListener('click', async () => {
      cancelBtn.disabled = true;
      postBtn.disabled = true;
      status.textContent = 'Uploading...';

      const fd = new FormData();
      fd.append('file', composerFile);
      fd.append('privacy', privacySelect.value);

      try {
        const response = await apiPost(`${API_BASE}`, fd);

        // If stickers were added, attach them to the story
        if (pendingStickers.length > 0 && response.story_id && response.item_id) {
          status.textContent = 'Adding stickers...';

          for (const sticker of pendingStickers) {
            try {
              // Send as JSON payload instead of FormData
              const stickerPayload = {
                type: sticker.type,
                data: sticker.data,
                position_x: sticker.position.x,
                position_y: sticker.position.y,
              };

              await apiPost(`${API_BASE}/${response.story_id}/items/${response.item_id}/stickers`, stickerPayload);
            } catch (stickerErr) {
              console.error('Failed to add sticker:', stickerErr);
              // Continue with other stickers even if one fails
            }
          }
        }

        if (window.KoopoStories && window.KoopoStories.shareStoryToActivity && shareActivityCheckbox.checked && response.story_id) {
          status.textContent = 'Sharing to activity...';
          try {
            const form = new FormData();
            form.append('action', 'koopo_stories_share_story_activity');
            form.append('story_id', String(response.story_id));
            // Send the newly created item_id to ensure correct item is used
            if (response.item_id) {
              form.append('item_id', String(response.item_id));
            }
            if (window.KoopoStories.shareNonce) form.append('nonce', window.KoopoStories.shareNonce);
            const res = await fetch(window.KoopoStories.shareAjaxUrl || '', { method: 'POST', credentials: 'same-origin', body: form });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok || !payload.success) {
              throw new Error(payload?.data?.message || 'Share to activity failed');
            }
          } catch (e) {
            console.error('Failed to share to activity:', e);
            status.textContent = e.message || 'Share to activity failed';
          }
        }

        status.textContent = 'Posted!';
        // Refresh all trays/widgets on page
        document.querySelectorAll('.koopo-stories').forEach(c => refreshTray(c));
        setTimeout(close, 400);
      } catch (e) {
        status.textContent = e.message || 'Upload failed';
        cancelBtn.disabled = false;
        postBtn.disabled = false;
      }
    });

    panel.appendChild(title);
    panel.appendChild(preview);
    panel.appendChild(stickerToolbar);
    panel.appendChild(stickerMenu);
    panel.appendChild(privacyWrap);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.focus();
  }

  function loadLottiePlayer() {
    if (window.customElements && window.customElements.get('lottie-player')) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const id = 'koopo-lottie-player-script';
      const existing = document.getElementById(id);
      if (existing) {
        existing.addEventListener('load', () => resolve(), { once: true });
        existing.addEventListener('error', () => reject(new Error('Failed to load lottie player')), { once: true });
        return;
      }
      const script = document.createElement('script');
      script.id = id;
      script.src = 'https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js';
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error('Failed to load lottie player'));
      document.head.appendChild(script);
    });
  }

  async function fetchProviderResults(provider, query = '') {
    const cfg = window.KoopoStoriesStickerProviders || {};
    const q = encodeURIComponent(query || '');

    if (provider === 'giphy' && cfg.giphy && cfg.giphy.enabled && cfg.giphy.apiKey) {
      const endpoint = query
        ? `https://api.giphy.com/v1/stickers/search?api_key=${encodeURIComponent(cfg.giphy.apiKey)}&limit=24&q=${q}`
        : `https://api.giphy.com/v1/stickers/trending?api_key=${encodeURIComponent(cfg.giphy.apiKey)}&limit=24`;
      const res = await fetch(endpoint);
      if (!res.ok) throw new Error('GIPHY request failed');
      const json = await res.json();
      return (json.data || []).map((i) => ({
        preview: i && i.images && i.images.fixed_width_small ? i.images.fixed_width_small.url : '',
        url: i && i.images && i.images.original ? i.images.original.url : '',
        type: 'image/gif',
        label: i && i.title ? i.title : 'GIPHY sticker',
      })).filter((x) => x.preview && x.url);
    }

    if (provider === 'tenor' && cfg.tenor && cfg.tenor.enabled && cfg.tenor.apiKey) {
      const endpoint = query
        ? `https://tenor.googleapis.com/v2/search?key=${encodeURIComponent(cfg.tenor.apiKey)}&q=${q}&limit=24&media_filter=gif`
        : `https://tenor.googleapis.com/v2/featured?key=${encodeURIComponent(cfg.tenor.apiKey)}&limit=24&media_filter=gif`;
      const res = await fetch(endpoint);
      if (!res.ok) throw new Error('Tenor request failed');
      const json = await res.json();
      return (json.results || []).map((i) => {
        const media = i.media_formats || {};
        const gif = media.gif || media.mediumgif || media.tinygif;
        return {
          preview: gif && gif.url ? gif.url : '',
          url: gif && gif.url ? gif.url : '',
          type: 'image/gif',
          label: i.content_description || 'Tenor sticker',
        };
      }).filter((x) => x.preview && x.url);
    }

    if (provider === 'lottie' && cfg.lottie && cfg.lottie.enabled) {
      const library = Array.isArray(cfg.lottie.library) ? cfg.lottie.library : [];
      return library.slice(0, 30).map((url, idx) => ({
        preview: '',
        url,
        type: 'application/json',
        label: `Lottie ${idx + 1}`,
      }));
    }

    return [];
  }

  function openExternalStickerModal(provider, pendingStickers, preview, assignStickerCid) {
    const labels = { giphy: 'GIPHY', tenor: 'Tenor', lottie: 'Lottie' };
    const providerName = labels[provider] || 'Stickers';
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;' });
    const modal = el('div', { class: 'koopo-stories__composer-panel koopo-stories__composer-panel--modal', style: 'max-width:500px;' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = `Add ${providerName} sticker`;

    const searchWrap = el('div', { style: 'display:flex;gap:8px;margin-bottom:10px;' });
    const search = el('input', { type: 'text', placeholder: `Search ${providerName}`, class: 'regular-text', style: 'flex:1;' });
    const searchBtn = el('button', { type: 'button', class: 'button button-primary' });
    searchBtn.textContent = 'Search';
    searchWrap.appendChild(search);
    searchWrap.appendChild(searchBtn);

    const status = el('div', { style: 'font-size:12px;opacity:.8;margin-bottom:8px;' });
    const grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:8px;max-height:320px;overflow:auto;padding:2px;' });
    const footer = el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:12px;' });
    const loadMoreBtn = el('button', { type: 'button', class: 'button button-secondary' });
    loadMoreBtn.textContent = 'Load more';
    const closeBtn = el('button', { type: 'button', class: 'button' });
    closeBtn.textContent = 'Close';
    footer.appendChild(loadMoreBtn);
    footer.appendChild(closeBtn);
    let allResults = [];
    let renderedCount = 0;
    const pageSize = 10;

    const shuffle = (arr) => {
      const copy = arr.slice();
      for (let i = copy.length - 1; i > 0; i -= 1) {
        const j = Math.floor(Math.random() * (i + 1));
        [copy[i], copy[j]] = [copy[j], copy[i]];
      }
      return copy;
    };

    const appendItemTile = async (item) => {
      const tile = el('button', {
        type: 'button',
        style: 'height:88px;border:1px solid rgba(0,0,0,.12);border-radius:10px;overflow:hidden;background:#f6f7fb;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;'
      });

      if (item.type === 'application/json') {
        try {
          await loadLottiePlayer();
          const lp = el('lottie-player', {
            src: item.url,
            background: 'transparent',
            speed: '1',
            loop: 'true',
            autoplay: 'true',
            style: 'width:100%;height:100%;'
          });
          tile.appendChild(lp);
        } catch (e) {
          tile.textContent = 'Lottie';
        }
      } else {
        const img = el('img', { src: item.preview, alt: item.label || '', style: 'width:100%;height:100%;object-fit:cover;' });
        tile.appendChild(img);
      }

      tile.onclick = () => {
        const sticker = {
          type: 'media',
          data: {
            url: item.url,
            mime: item.type,
            provider,
            title: item.label || providerName,
          },
          position: { x: 50, y: 50 },
        };
        assignStickerCid(sticker);
        pendingStickers.push(sticker);
        const stickerPreview = createStickerElement(sticker, pendingStickers, previewWrap, assignStickerCid);
        if (stickerPreview) {
          previewWrap.appendChild(stickerPreview);
          makeDraggable(stickerPreview, previewWrap, sticker.position);
        }
        overlay.remove();
      };
      grid.appendChild(tile);
    };

    const updateLoadMoreVisibility = () => {
      loadMoreBtn.style.display = renderedCount < allResults.length ? 'inline-flex' : 'none';
    };

    const renderNextPage = async () => {
      const nextChunk = allResults.slice(renderedCount, renderedCount + pageSize);
      for (const item of nextChunk) {
        // Keep order deterministic for this page while preserving async lottie rendering.
        await appendItemTile(item);
      }
      renderedCount += nextChunk.length;
      status.textContent = `${Math.min(renderedCount, allResults.length)} / ${allResults.length} shown`;
      updateLoadMoreVisibility();
    };

    const renderItems = async (items) => {
      grid.innerHTML = '';
      renderedCount = 0;
      allResults = shuffle(items || []);
      if (!allResults.length) {
        status.textContent = 'No results';
        loadMoreBtn.style.display = 'none';
        return;
      }
      await renderNextPage();
    };

    const runSearch = async () => {
      status.textContent = 'Loading...';
      searchBtn.disabled = true;
      loadMoreBtn.disabled = true;
      try {
        const items = await fetchProviderResults(provider, search.value.trim());
        await renderItems(items);
      } catch (e) {
        status.textContent = e.message || 'Failed to load stickers';
        loadMoreBtn.style.display = 'none';
      } finally {
        searchBtn.disabled = false;
        loadMoreBtn.disabled = false;
      }
    };

    closeBtn.onclick = () => overlay.remove();
    loadMoreBtn.onclick = async () => {
      loadMoreBtn.disabled = true;
      try {
        await renderNextPage();
      } finally {
        loadMoreBtn.disabled = false;
      }
    };
    searchBtn.onclick = runSearch;
    search.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        runSearch();
      }
    });

    modal.appendChild(title);
    modal.appendChild(searchWrap);
    modal.appendChild(status);
    modal.appendChild(grid);
    modal.appendChild(footer);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    runSearch();
  }

  function openImageEditorModal(file) {
    return new Promise((resolve) => {
      const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;' });
      const panel = el('div', { class: 'koopo-stories__composer-panel koopo-stories__composer-panel--modal', style: 'max-width:640px;max-height:90vh;overflow:auto;' });
      const header = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:8px;' });
      const title = el('div', { class: 'koopo-stories__composer-title', html: 'Edit photo' });
      const headerCloseBtn = el('button', { type: 'button', class: 'button', style: 'line-height:1;padding:4px 10px;' });
      headerCloseBtn.textContent = 'X';
      header.appendChild(title);
      header.appendChild(headerCloseBtn);
      const canvas = el('canvas', { style: 'width:100%;max-height:58vh;border-radius:12px;background:#111;' });
      const controls = el('div', { class: 'koopo-stories__filter-controls' });
      const controlsTabs = el('div', { class: 'koopo-stories__filter-tabs' });
      const controlsPanel = el('div', { class: 'koopo-stories__filter-panels' });
      const presetsWrap = el('div', { class: 'koopo-stories__filter-presets' });
      const footer = el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:12px;' });
      const exitBtn = el('button', { type: 'button', class: 'button' });
      const saveBtn = el('button', { type: 'button', class: 'button button-primary' });
      exitBtn.innerHTML = '<span class="dashicons dashicons-no-alt" style="font-size:16px;line-height:1.1;"></span> Exit';
      saveBtn.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:16px;line-height:1.1;"></span> Save';

      const mkRange = (label, min, max, value, help, icons = {}) => {
        const wrap = el('label', { class: 'koopo-stories__filter-control' });
        const row = el('div', { class: 'koopo-stories__filter-control-head' });
        const iconWrap = el('span', { class: 'koopo-stories__filter-icons' });
        const wpIcon = el('i', { class: `koopo-stories__lib-icon dashicons ${icons.wp || 'dashicons-admin-generic'}` });
        iconWrap.appendChild(wpIcon);

        const txt = el('span', { class: 'koopo-stories__filter-control-title' });
        txt.textContent = label;
        const valueBadge = el('span', { class: 'koopo-stories__filter-control-value' });
        valueBadge.textContent = `${value}%`;
        row.appendChild(iconWrap);
        row.appendChild(txt);
        row.appendChild(valueBadge);

        const input = el('input', { type: 'range', min: String(min), max: String(max), value: String(value) });
        const helpText = el('small', { class: 'koopo-stories__filter-control-help' });
        helpText.textContent = help || '';
        input.addEventListener('input', () => {
          valueBadge.textContent = `${input.value}%`;
        });

        wrap.appendChild(row);
        wrap.appendChild(input);
        wrap.appendChild(helpText);
        return { wrap, input };
      };

      const brightness = mkRange('Brightness', 50, 150, 100, 'Adjust overall light level.', {
        wp: 'dashicons-lightbulb',
        bb: 'bb-icon-sun',
        el: 'eicon-lightbox',
      });
      const contrast = mkRange('Contrast', 50, 150, 100, 'Increase or soften dark/light separation.', {
        wp: 'dashicons-image-filter',
        bb: 'bb-icon-adjust',
        el: 'eicon-adjust',
      });
      const saturate = mkRange('Saturation', 0, 200, 100, 'Boost or mute colors.', {
        wp: 'dashicons-art',
        bb: 'bb-icon-palette',
        el: 'eicon-color-picker',
      });
      const cropZoom = mkRange('Crop Zoom', 100, 200, 100, 'Zoom in/out before saving.', {
        wp: 'dashicons-search',
        bb: 'bb-icon-zoom-in',
        el: 'eicon-zoom-in-bold',
      });
      const controlDefs = [
        { key: 'brightness', label: 'Brightness', icon: 'dashicons-lightbulb', control: brightness },
        { key: 'contrast', label: 'Contrast', icon: 'dashicons-image-filter', control: contrast },
        { key: 'saturate', label: 'Saturation', icon: 'dashicons-art', control: saturate },
        { key: 'crop', label: 'Crop Zoom', icon: 'dashicons-search', control: cropZoom },
      ];

      const activateControl = (key) => {
        controlsTabs.querySelectorAll('.koopo-stories__filter-tab').forEach((btn) => {
          btn.classList.toggle('is-active', btn.dataset.control === key);
        });
        controlsPanel.querySelectorAll('.koopo-stories__filter-panel').forEach((panelEl) => {
          panelEl.style.display = panelEl.dataset.control === key ? 'block' : 'none';
        });
      };

      controlDefs.forEach((def, index) => {
        const tabBtn = el('button', {
          type: 'button',
          class: `koopo-stories__filter-tab${index === 0 ? ' is-active' : ''}`,
          'data-control': def.key,
          title: def.label,
          'aria-label': def.label,
        });
        tabBtn.innerHTML = `<span class="dashicons ${def.icon}"></span>`;
        tabBtn.addEventListener('click', () => activateControl(def.key));
        controlsTabs.appendChild(tabBtn);

        const panel = el('div', {
          class: 'koopo-stories__filter-panel',
          'data-control': def.key,
          style: index === 0 ? 'display:block;' : 'display:none;',
        });
        panel.appendChild(def.control.wrap);
        controlsPanel.appendChild(panel);
      });

      controls.appendChild(controlsTabs);
      controls.appendChild(controlsPanel);

      const presets = [
        { key: 'normal', label: 'Normal', brightness: 100, contrast: 100, saturate: 100 },
        { key: 'clarendon', label: 'Clarendon', brightness: 106, contrast: 112, saturate: 128 },
        { key: 'gingham', label: 'Gingham', brightness: 108, contrast: 94, saturate: 90 },
        { key: 'moon', label: 'Moon', brightness: 102, contrast: 114, saturate: 40 },
        { key: 'lark', label: 'Lark', brightness: 110, contrast: 96, saturate: 112 },
        { key: 'reyes', label: 'Reyes', brightness: 112, contrast: 90, saturate: 86 },
        { key: 'juno', label: 'Juno', brightness: 112, contrast: 108, saturate: 132 },
        { key: 'slumber', label: 'Slumber', brightness: 96, contrast: 88, saturate: 78 },
      ];

      const setPreset = (preset) => {
        const pairs = [
          [brightness.input, preset.brightness],
          [contrast.input, preset.contrast],
          [saturate.input, preset.saturate],
          [cropZoom.input, 100],
        ];
        pairs.forEach(([input, value]) => {
          input.value = String(value);
          input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        presetsWrap.querySelectorAll('.koopo-stories__filter-preset').forEach((node) => node.classList.remove('is-active'));
        const active = presetsWrap.querySelector(`[data-preset="${preset.key}"]`);
        if (active) active.classList.add('is-active');
      };

      presets.forEach((preset) => {
        const btn = el('button', {
          type: 'button',
          class: `koopo-stories__filter-preset${preset.key === 'normal' ? ' is-active' : ''}`,
          'data-preset': preset.key,
        });
        btn.textContent = preset.label;
        btn.addEventListener('click', () => setPreset(preset));
        presetsWrap.appendChild(btn);
      });

      footer.appendChild(exitBtn);
      footer.appendChild(saveBtn);

      panel.appendChild(header);
      panel.appendChild(canvas);
      panel.appendChild(presetsWrap);
      panel.appendChild(controls);
      panel.appendChild(footer);
      overlay.appendChild(panel);
      document.body.appendChild(overlay);

      const ctx = canvas.getContext('2d');
      const img = new Image();
      const src = URL.createObjectURL(file);
      img.onload = () => {
        const maxW = 1080;
        const maxH = 1350;
        const ratio = Math.min(maxW / img.width, maxH / img.height, 1);
        canvas.width = Math.round(img.width * ratio);
        canvas.height = Math.round(img.height * ratio);
        draw();
      };
      img.src = src;

      const draw = () => {
        if (!ctx || !img.width) return;
        const zoom = (parseInt(cropZoom.input.value, 10) || 100) / 100;
        const cropW = img.width / zoom;
        const cropH = img.height / zoom;
        const sx = Math.max(0, (img.width - cropW) / 2);
        const sy = Math.max(0, (img.height - cropH) / 2);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.filter = `brightness(${brightness.input.value}%) contrast(${contrast.input.value}%) saturate(${saturate.input.value}%)`;
        ctx.drawImage(img, sx, sy, cropW, cropH, 0, 0, canvas.width, canvas.height);
        ctx.filter = 'none';
      };

      [brightness, contrast, saturate, cropZoom].forEach((ctl) => ctl.input.addEventListener('input', draw));

      const cleanup = () => {
        try { URL.revokeObjectURL(src); } catch (e) {}
        overlay.remove();
      };
      const closeWithoutSave = () => {
        cleanup();
        resolve(null);
      };
      headerCloseBtn.onclick = closeWithoutSave;
      exitBtn.onclick = closeWithoutSave;
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeWithoutSave();
      });
      const onEsc = (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          document.removeEventListener('keydown', onEsc);
          closeWithoutSave();
        }
      };
      document.addEventListener('keydown', onEsc);

      saveBtn.onclick = () => {
        canvas.toBlob((blob) => {
          cleanup();
          if (!blob) {
            resolve(null);
            return;
          }
          resolve(new File([blob], `edited-${Date.now()}.jpg`, { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.92);
      };
    });
  }

  // Open modal to add a sticker
  function openStickerModal(type, pendingStickers, preview, assignStickerCid, options = {}) {
    const existingSticker = options.existingSticker || null;
    const initialData = (existingSticker && existingSticker.data) ? existingSticker.data : {};
    const modalOverlay = el('div', {
      class: 'koopo-stories__composer',
      style: 'z-index:9999999;'
    });

    const modalPanel = el('div', {
      class: 'koopo-stories__composer-panel koopo-stories__composer-panel--modal',
      style: 'max-width:400px;'
    });

    const modalTitle = el('div', { class: 'koopo-stories__composer-title' });
      modalTitle.textContent = `${existingSticker ? 'Edit' : 'Add'} ${type.charAt(0).toUpperCase() + type.slice(1)}`;

    const form = el('div', { class: 'koopo-stories__composer-form' });

    // Form fields based on sticker type
    let stickerData = {};
    let isValid = false;

    if (type === 'mention') {
      const inputWrap = el('div', { style: 'position:relative;' });
      const input = el('input', {
        type: 'text',
        placeholder: 'Enter username',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;'
      });
      if (initialData.username) input.value = initialData.username;
      const inputSpinner = el('div', { class: 'koopo-stories__input-spinner' });
      inputSpinner.appendChild(el('div', { class: 'koopo-stories__spinner koopo-stories__spinner--sm' }));
      inputWrap.appendChild(input);
      inputWrap.appendChild(inputSpinner);

      const dropdown = el('div', {
        class: 'koopo-stories__mention-dropdown',
        style: 'position:relative;margin-top:8px;'
      });

      input.addEventListener('input', async () => {
        const value = input.value.trim();
        if (value.length < 2) {
          dropdown.innerHTML = '';
          inputSpinner.style.display = 'none';
          return;
        }

        try {
          inputSpinner.style.display = 'block';
          const users = await apiGet(`${API_BASE}/search-users?query=${encodeURIComponent(value)}`);
          dropdown.innerHTML = '';

          (users.users || []).forEach(user => {
            const option = el('div', {
              style: 'padding:8px;border:1px solid #eee;border-radius:6px;margin-bottom:4px;cursor:pointer;display:flex;align-items:center;gap:8px;'
            });

            const avatar = el('img', { src: user.avatar || '', style: 'width:32px;height:32px;border-radius:50%;' });
            const name = el('span');
            name.textContent = user.name || user.username;

            option.appendChild(avatar);
            option.appendChild(name);

            option.onclick = () => {
              input.value = user.username;
              stickerData.user_id = user.id;
              stickerData.username = user.username;
              stickerData.profile_url = user.profile_url;
              dropdown.innerHTML = '';
              isValid = true;
            };

            dropdown.appendChild(option);
          });
        } catch (e) {
          console.error('Failed to search users:', e);
        } finally {
          inputSpinner.style.display = 'none';
        }
      });

      form.appendChild(inputWrap);
      form.appendChild(dropdown);
    } else if (type === 'link') {
      const titleInput = el('input', {
        type: 'text',
        placeholder: 'Link title',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });
      const urlInput = el('input', {
        type: 'url',
        placeholder: 'https://example.com',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;'
      });
      titleInput.value = initialData.title || '';
      urlInput.value = initialData.url || '';

      form.appendChild(titleInput);
      form.appendChild(urlInput);

      stickerData.getData = () => {
        return {
          title: titleInput.value.trim() || urlInput.value.trim(),
          url: urlInput.value.trim()
        };
      };
    } else if (type === 'location') {
      const nameInput = el('input', {
        type: 'text',
        placeholder: 'Location name',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });
      const latInput = el('input', {
        type: 'number',
        placeholder: 'Latitude (optional)',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });
      const lngInput = el('input', {
        type: 'number',
        placeholder: 'Longitude (optional)',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;'
      });
      nameInput.value = initialData.name || '';
      if (initialData.lat != null) latInput.value = initialData.lat;
      if (initialData.lng != null) lngInput.value = initialData.lng;

      form.appendChild(nameInput);
      form.appendChild(latInput);
      form.appendChild(lngInput);

      stickerData.getData = () => {
        return {
          name: nameInput.value.trim(),
          lat: parseFloat(latInput.value),
          lng: parseFloat(lngInput.value)
        };
      };
    } else if (type === 'poll') {
      const questionInput = el('input', {
        type: 'text',
        placeholder: 'Poll question',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });

      const option1 = el('input', {
        type: 'text',
        placeholder: 'Option 1',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });

      const option2 = el('input', {
        type: 'text',
        placeholder: 'Option 2',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });

      const option3 = el('input', {
        type: 'text',
        placeholder: 'Option 3 (optional)',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });

      const option4 = el('input', {
        type: 'text',
        placeholder: 'Option 4 (optional)',
        style: 'width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;margin-bottom:10px;'
      });
      questionInput.value = initialData.question || '';
      if (initialData.options && Array.isArray(initialData.options)) {
        option1.value = initialData.options[0]?.text || '';
        option2.value = initialData.options[1]?.text || '';
        option3.value = initialData.options[2]?.text || '';
        option4.value = initialData.options[3]?.text || '';
      }

      form.appendChild(questionInput);
      form.appendChild(option1);
      form.appendChild(option2);
      form.appendChild(option3);
      form.appendChild(option4);

      stickerData.getData = () => {
        return {
          question: questionInput.value.trim(),
          options: [
            { text: option1.value.trim(), votes: 0 },
            { text: option2.value.trim(), votes: 0 },
            option3.value.trim() ? { text: option3.value.trim(), votes: 0 } : null,
            option4.value.trim() ? { text: option4.value.trim(), votes: 0 } : null,
          ].filter(Boolean)
        };
      };
    } else if (type === 'text') {
      const editorWrap = el('div', { style: 'border:1px solid #ddd;border-radius:10px;padding:8px;background:#fff;' });
      const toolbar = el('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px;' });
      const mkFmtBtn = (label, title) => {
        const b = el('button', {
          type: 'button',
          title,
          style: 'padding:6px 8px;border:1px solid #ddd;border-radius:6px;background:#f8f8f8;cursor:pointer;font-size:12px;'
        });
        b.textContent = label;
        return b;
      };
      const boldBtn = mkFmtBtn('B', 'Bold');
      const italicBtn = mkFmtBtn('I', 'Italic');
      const underlineBtn = mkFmtBtn('U', 'Underline');
      toolbar.appendChild(boldBtn);
      toolbar.appendChild(italicBtn);
      toolbar.appendChild(underlineBtn);

      const editor = el('div', {
        contenteditable: 'true',
        style: 'min-height:86px;border:1px solid #e3e3e3;border-radius:8px;padding:10px;outline:none;background:#fff;'
      });
      editor.textContent = initialData.text || '';
      editor.setAttribute('data-placeholder', 'Write a caption...');
      editorWrap.appendChild(toolbar);
      editorWrap.appendChild(editor);

      const toggleBtn = (btn, active) => {
        btn.style.background = active ? '#111' : '#f8f8f8';
        btn.style.color = active ? '#fff' : '#111';
      };
      let isBold = !!(initialData.style && (String(initialData.style.font_weight) === 'bold' || Number(initialData.style.font_weight) >= 600));
      let isItalic = !!(initialData.style && initialData.style.font_style === 'italic');
      let isUnderline = !!(initialData.style && initialData.style.text_decoration === 'underline');
      toggleBtn(boldBtn, isBold);
      toggleBtn(italicBtn, isItalic);
      toggleBtn(underlineBtn, isUnderline);
      boldBtn.onclick = () => { isBold = !isBold; toggleBtn(boldBtn, isBold); };
      italicBtn.onclick = () => { isItalic = !isItalic; toggleBtn(italicBtn, isItalic); };
      underlineBtn.onclick = () => { isUnderline = !isUnderline; toggleBtn(underlineBtn, isUnderline); };

      const styleRow = el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;' });
      const colorInput = el('input', { type: 'color', value: (initialData.style && initialData.style.text_color) || '#ffffff', title: 'Text color', style: 'width:44px;height:36px;padding:2px;' });
      const bgInput = el('input', { type: 'color', value: (initialData.style && initialData.style.bg_color) || '#000000', title: 'Background color', style: 'width:44px;height:36px;padding:2px;' });
      const sizeInput = el('input', { type: 'range', min: '14', max: '48', value: ((initialData.style && initialData.style.font_size) || 18), style: 'width:130px;' });
      const fontInput = el('select', { style: 'padding:8px;border:1px solid #ddd;border-radius:6px;' });
      const alignInput = el('select', { style: 'padding:8px;border:1px solid #ddd;border-radius:6px;' });
      [
        ['left', 'Align Left'],
        ['center', 'Align Center'],
        ['right', 'Align Right'],
      ].forEach(([v, l]) => {
        const opt = el('option', { value: v });
        opt.textContent = l;
        alignInput.appendChild(opt);
      });
      if (initialData.style && initialData.style.text_align) alignInput.value = initialData.style.text_align;
      const opacityLabel = el('label', { style: 'font-size:12px;color:#666;' });
      opacityLabel.textContent = 'BG Opacity';
      const opacityInput = el('input', {
        type: 'range',
        min: '0',
        max: '100',
        value: String(Math.round(((initialData.style && initialData.style.bg_opacity != null ? initialData.style.bg_opacity : 0.6) * 100))),
        style: 'width:120px;'
      });
      const opacityValue = el('span', { style: 'font-size:12px;min-width:36px;display:inline-block;' });
      opacityValue.textContent = `${opacityInput.value}%`;
      opacityInput.addEventListener('input', () => { opacityValue.textContent = `${opacityInput.value}%`; });
      ['inherit', 'Georgia', 'Trebuchet MS', 'Courier New', 'Tahoma'].forEach((f) => {
        const opt = el('option', { value: f });
        opt.textContent = f === 'inherit' ? 'Default font' : f;
        fontInput.appendChild(opt);
      });
      if (initialData.style && initialData.style.font_family) {
        fontInput.value = initialData.style.font_family;
      }
      styleRow.appendChild(colorInput);
      styleRow.appendChild(bgInput);
      styleRow.appendChild(sizeInput);
      styleRow.appendChild(fontInput);
      form.appendChild(editorWrap);
      form.appendChild(styleRow);
      styleRow.appendChild(alignInput);
      styleRow.appendChild(opacityLabel);
      styleRow.appendChild(opacityInput);
      styleRow.appendChild(opacityValue);
      stickerData.getData = () => {
        return {
          text: (editor.textContent || '').trim(),
          style: {
            text_color: colorInput.value,
            bg_color: bgInput.value,
            bg_opacity: Math.max(0, Math.min(1, (parseInt(opacityInput.value, 10) || 0) / 100)),
            font_size: parseInt(sizeInput.value, 10) || 18,
            font_family: fontInput.value || 'inherit',
            font_weight: isBold ? 'bold' : 'normal',
            font_style: isItalic ? 'italic' : 'normal',
            text_decoration: isUnderline ? 'underline' : 'none',
            text_align: alignInput.value || 'center',
          },
        };
      };
    }

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel', type: 'button' });
    cancelBtn.textContent = 'Cancel';
    const addBtn = el('button', { class: 'koopo-stories__composer-post', type: 'button' });
    addBtn.textContent = existingSticker ? 'Save Sticker' : 'Add Sticker';

    const closeModal = () => {
      modalOverlay.remove();
    };

    cancelBtn.onclick = closeModal;
    modalOverlay.onclick = (e) => { if (e.target === modalOverlay) closeModal(); };

    addBtn.onclick = () => {
      if (type === 'mention') {
        isValid = !!stickerData.user_id;
      } else {
        stickerData = stickerData.getData ? stickerData.getData() : stickerData;
        if (type === 'link') isValid = !!stickerData.url;
        if (type === 'location') isValid = !!stickerData.name;
        if (type === 'poll') isValid = !!stickerData.question && stickerData.options.length >= 2;
        if (type === 'text') isValid = !!stickerData.text;
      }

      if (!isValid) {
        alert('Please fill in required fields');
        return;
      }

      if (existingSticker && typeof options.onSave === 'function') {
        options.onSave(stickerData);
        closeModal();
        return;
      }

      // Add sticker to pending list
      const sticker = {
        type,
        data: stickerData,
        position: { x: 50, y: 50 } // Default center position
      };
      if (typeof assignStickerCid === 'function') {
        assignStickerCid(sticker);
      }

      pendingStickers.push(sticker);

      // Show preview of sticker on the media
      const stickerPreview = createStickerElement(sticker, pendingStickers, previewWrap, assignStickerCid);
      if (stickerPreview) {
        stickerPreview.style.cursor = 'move';
        previewWrap.appendChild(stickerPreview);

        // Make sticker draggable within preview
        makeDraggable(stickerPreview, previewWrap, sticker.position);
      }

      closeModal();
    };

    actions.appendChild(cancelBtn);
    actions.appendChild(addBtn);
    modalPanel.appendChild(modalTitle);
    modalPanel.appendChild(form);
    modalPanel.appendChild(actions);
    modalOverlay.appendChild(modalPanel);
    document.body.appendChild(modalOverlay);
  }

  // Make sticker draggable within preview
  function makeDraggable(element, container, position) {
    let isDragging = false;
    let startX, startY, initialLeft, initialTop;

    const startDrag = (clientX, clientY) => {
      isDragging = true;
      startX = clientX;
      startY = clientY;
      const rect = container.getBoundingClientRect();
      initialLeft = (position.x / 100) * rect.width;
      initialTop = (position.y / 100) * rect.height;
    };

    const updatePosition = (clientX, clientY) => {
      const deltaX = clientX - startX;
      const deltaY = clientY - startY;
      const rect = container.getBoundingClientRect();

      const newLeft = initialLeft + deltaX;
      const newTop = initialTop + deltaY;

      // Update position as percentage
      position.x = Math.max(0, Math.min(100, (newLeft / rect.width) * 100));
      position.y = Math.max(0, Math.min(100, (newTop / rect.height) * 100));

      element.style.left = position.x + '%';
      element.style.top = position.y + '%';
    };

    const endDrag = () => {
      isDragging = false;
    };

    if ('PointerEvent' in window) {
      element.style.touchAction = 'none';
      element.addEventListener('pointerdown', (e) => {
        if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('.koopo-stories__sticker-controls')) return;
        startDrag(e.clientX, e.clientY);
        element.setPointerCapture(e.pointerId);
        e.preventDefault();
      });
      element.addEventListener('pointermove', (e) => {
        if (!isDragging) return;
        updatePosition(e.clientX, e.clientY);
      });
      element.addEventListener('pointerup', endDrag);
      element.addEventListener('pointercancel', endDrag);
      return;
    }

    element.onmousedown = (e) => {
      if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('.koopo-stories__sticker-controls')) return;
      startDrag(e.clientX, e.clientY);
      e.preventDefault();
    };

    document.onmousemove = (e) => {
      if (!isDragging) return;
      updatePosition(e.clientX, e.clientY);
    };

    document.onmouseup = endDrag;
  }

  function ensureStickerStyle(sticker) {
    sticker.data = sticker.data || {};
    sticker.data.style = sticker.data.style || {};
    if (typeof sticker.data.style.scale !== 'number') sticker.data.style.scale = 1;
    return sticker.data.style;
  }

  function hexToRgba(hex, alpha) {
    if (!hex || typeof hex !== 'string' || !hex.startsWith('#')) return '';
    const h = hex.replace('#', '');
    const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
    if (full.length !== 6) return '';
    const n = parseInt(full, 16);
    const r = (n >> 16) & 255;
    const g = (n >> 8) & 255;
    const b = n & 255;
    const a = Math.max(0, Math.min(1, Number(alpha)));
    return `rgba(${r}, ${g}, ${b}, ${a})`;
  }

  function applyStickerPresentation(content, sticker) {
    const s = ensureStickerStyle(sticker);
    const hasBox = s.box_w || s.box_h;
    if (hasBox) {
      if (s.box_w) content.style.width = `${s.box_w}px`;
      if (s.box_h) content.style.height = `${s.box_h}px`;
      content.style.transform = 'none';
      content.style.transformOrigin = 'center';
    } else {
      const scaleX = Math.max(0.5, Math.min(2.5, parseFloat(s.scale_x != null ? s.scale_x : (parseFloat(s.scale) || 1))));
      const scaleY = Math.max(0.5, Math.min(2.5, parseFloat(s.scale_y != null ? s.scale_y : (parseFloat(s.scale) || 1))));
      content.style.transform = `scale(${scaleX}, ${scaleY})`;
      content.style.transformOrigin = 'center';
    }
    if (s.text_color) content.style.color = s.text_color;
    if (s.bg_color) {
      const opacity = (s.bg_opacity != null) ? Number(s.bg_opacity) : 1;
      content.style.backgroundColor = hexToRgba(s.bg_color, opacity);
    }
    if (s.box_w) content.style.width = `${s.box_w}px`;
    if (s.box_h) content.style.height = `${s.box_h}px`;
    if (s.font_size) content.style.fontSize = `${parseInt(s.font_size, 10)}px`;
    if (s.font_family && s.font_family !== 'inherit') content.style.fontFamily = s.font_family;
    if (s.font_weight) content.style.fontWeight = s.font_weight;
    if (s.font_style) content.style.fontStyle = s.font_style;
    if (s.text_decoration) content.style.textDecoration = s.text_decoration;
    if (s.text_align) content.style.textAlign = s.text_align;
  }

  function openInlineTextEditor(sticker, content, preview) {
    const existing = preview.querySelector('.koopo-stories__inline-text-editor');
    if (existing) existing.remove();

    const editor = el('div', {
      class: 'koopo-stories__inline-text-editor',
      style: 'position:absolute;left:8px;right:8px;bottom:8px;z-index:40;background:rgba(20,20,20,.92);color:#fff;border-radius:10px;padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;'
    });
    const textInput = el('div', {
      contenteditable: 'true',
      style: 'flex:1 1 100%;min-height:34px;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:8px;background:rgba(255,255,255,.08);outline:none;white-space:pre-wrap;word-break:break-word;'
    });
    const insertLineBreak = () => {
      document.execCommand('insertLineBreak');
    };
    textInput.addEventListener('beforeinput', (e) => {
      if (e.inputType === 'insertParagraph') {
        e.preventDefault();
        insertLineBreak();
      }
    });
    textInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        insertLineBreak();
      }
    });
    textInput.textContent = sticker.data.text || '';

    const mk = (tag, attrs = {}) => el(tag, attrs);
    const colorInput = mk('input', { type: 'color', value: (sticker.data.style && sticker.data.style.text_color) || '#ffffff', style: 'width:38px;height:32px;padding:0;border:none;background:none;opacity:0;position:absolute;pointer-events:auto;' });
    const bgInput = mk('input', { type: 'color', value: (sticker.data.style && sticker.data.style.bg_color) || '#000000', style: 'width:38px;height:32px;padding:0;border:none;background:none;opacity:0;position:absolute;pointer-events:auto;' });
    const opacity = mk('input', { type: 'range', min: '0', max: '100', value: String(Math.round(((sticker.data.style && sticker.data.style.bg_opacity != null ? sticker.data.style.bg_opacity : 0.6) * 100))), style: 'width:75px;' });
    const size = mk('input', { type: 'range', min: '14', max: '48', value: String((sticker.data.style && sticker.data.style.font_size) || 18), style: 'width:75px;' });
    const alignCycleBtn = mk('button', { type: 'button', style: 'padding:7px;border:none;background:#fff;color:#111;cursor:pointer;' });
    const alignStates = ['left', 'center', 'right'];
    const alignIconMap = {
      left: 'dashicons-editor-alignleft',
      center: 'dashicons-editor-aligncenter',
      right: 'dashicons-editor-alignright',
    };
    let alignState = (sticker.data.style && sticker.data.style.text_align) || 'center';
    alignCycleBtn.innerHTML = `<span class="dashicons ${alignIconMap[alignState] || alignIconMap.center}" style="font-size:16px;line-height:1.6;"></span>`;

    const mkBtn = (label) => {
      const b = mk('button', { type: 'button', style: 'padding:7px;min-width:30px;border-radius:6px;border:none;background:#fff;color:#111;cursor:pointer;' });
      b.textContent = label;
      return b;
    };
    const bBold = mkBtn('B');
    const bItalic = mkBtn('I');
    const bUnderline = mkBtn('U');
    const doneBtn = mk('button', { type: 'button', style: 'padding:7px;border-radius:6px;border:none;background:#35c759;color:#111;cursor:pointer;font-weight:700;' });
    doneBtn.textContent = 'Done';

    const style = ensureStickerStyle(sticker);
    let colorLabel;
    let bgLabel;
    let isBold = style.font_weight === 'bold' || Number(style.font_weight) >= 600;
    let isItalic = style.font_style === 'italic';
    let isUnderline = style.text_decoration === 'underline';
    const syncState = () => {
      bBold.style.opacity = isBold ? '1' : '.65';
      bItalic.style.opacity = isItalic ? '1' : '.65';
      bUnderline.style.opacity = isUnderline ? '1' : '.65';
    };
    const applyLive = () => {
      sticker.data.text = (textInput.innerText || '').replace(/\u00A0/g, ' ');
      style.text_color = colorInput.value;
      style.bg_color = bgInput.value;
      style.bg_opacity = Math.max(0, Math.min(1, (parseInt(opacity.value, 10) || 0) / 100));
      style.font_size = parseInt(size.value, 10) || 18;
      style.text_align = alignState || 'center';
      style.font_weight = isBold ? 'bold' : 'normal';
      style.font_style = isItalic ? 'italic' : 'normal';
      style.text_decoration = isUnderline ? 'underline' : 'none';
      content.textContent = sticker.data.text || '';
      if (colorLabel) {
        colorLabel.style.color = style.text_color || '#fff';
        colorLabel.style.backgroundColor = 'transparent';
      }
      if (bgLabel) {
        bgLabel.style.color = style.text_color || '#fff';
        bgLabel.style.backgroundColor = style.bg_color || 'transparent';
      }
      applyStickerPresentation(content, sticker);
    };
    syncState();
    applyLive();

    [textInput, colorInput, bgInput, opacity, size].forEach((inp) => inp.addEventListener('input', applyLive));
    alignCycleBtn.onclick = () => {
      const idx = alignStates.indexOf(alignState);
      alignState = alignStates[(idx + 1) % alignStates.length];
      alignCycleBtn.innerHTML = `<span class="dashicons ${alignIconMap[alignState] || alignIconMap.center}" style="font-size:16px;line-height:1.6;"></span>`;
      applyLive();
    };
    bBold.onclick = () => { isBold = !isBold; syncState(); applyLive(); };
    bItalic.onclick = () => { isItalic = !isItalic; syncState(); applyLive(); };
    bUnderline.onclick = () => { isUnderline = !isUnderline; syncState(); applyLive(); };
    doneBtn.onclick = () => editor.remove();

    editor.appendChild(textInput);
    const colorWrap = mk('div', { style: 'position:relative;width:34px;height:30px;border-radius:6px;border:1px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;cursor:pointer;' });
    colorLabel = mk('span', { style: 'font-weight:700;' });
    colorLabel.textContent = 'Aa';
    colorWrap.appendChild(colorLabel);
    colorWrap.appendChild(colorInput);
    const bgWrap = mk('div', { style: 'position:relative;width:34px;height:30px;border-radius:6px;border:1px solid rgba(255, 255, 255, 0.86);display:flex;align-items:center;justify-content:center;cursor:pointer;' });
    bgLabel = mk('span', { style: 'font-weight:700;width:100%;text-align:center;' });
    bgLabel.textContent = 'Aa';
    bgWrap.appendChild(bgLabel);
    bgWrap.appendChild(bgInput);

    editor.appendChild(colorWrap);
    editor.appendChild(bgWrap);
    editor.appendChild(opacity);
    editor.appendChild(size);
    editor.appendChild(alignCycleBtn);
    editor.appendChild(bBold);
    editor.appendChild(bItalic);
    editor.appendChild(bUnderline);
    editor.appendChild(doneBtn);
    preview.appendChild(editor);
    textInput.focus();
  }

  function attachStickerControls(wrapper, content, sticker, pendingStickers, preview, assignStickerCid) {
    const controls = el('div', {
      class: 'koopo-stories__sticker-controls',
      style: 'position:absolute;top:-12px;right:-12px;display:flex;gap:4px;z-index:20;'
    });
    const mkBtn = (iconClass, title) => {
      const b = el('button', {
        type: 'button',
        title,
        style: 'width:22px;height:22px;border:none;border-radius:50%;background:#111;color:#fff;cursor:pointer;padding:0;line-height:22px;font-size:12px;'
      });
      b.innerHTML = `<span class="${iconClass}" style="font-size:12px;line-height:1.8;"></span>`;
      return b;
    };
    const removeBtn = mkBtn('dashicons dashicons-no-alt', 'Remove sticker');
    const editBtn = mkBtn('dashicons dashicons-edit', 'Edit sticker');

    editBtn.onclick = (e) => {
      e.stopPropagation();
      if (sticker.type === 'text') {
        openInlineTextEditor(sticker, content, previewWrap);
        return;
      }
      openStickerModal(sticker.type, pendingStickers, previewWrap, assignStickerCid, {
        existingSticker: sticker,
        onSave: (nextData) => {
          sticker.data = nextData;
          wrapper.remove();
          const updated = createStickerElement(sticker, pendingStickers, previewWrap, assignStickerCid);
          if (updated) {
            previewWrap.appendChild(updated);
            makeDraggable(updated, previewWrap, sticker.position);
          }
        },
      });
    };

    removeBtn.onclick = (e) => {
      e.stopPropagation();
      const idx = pendingStickers.findIndex((x) => x._cid && x._cid === sticker._cid);
      if (idx >= 0) pendingStickers.splice(idx, 1);
      wrapper.remove();
    };
    controls.appendChild(editBtn);
    controls.appendChild(removeBtn);
    wrapper.appendChild(controls);
  }

  function attachResizeHandles(wrapper, content, sticker) {
    const mkHandle = (cls, style) => {
      const h = el('div', { class: cls, style: `position:absolute;width:12px;height:12px;background:#fff;border:1px solid #111;border-radius:2px;z-index:21;${style}` });
      wrapper.appendChild(h);
      return h;
    };

    const hEast = mkHandle('koopo-stories__resize-handle--e', 'right:-8px;top:50%;transform:translateY(-50%);cursor:ew-resize;');
    const hSouth = mkHandle('koopo-stories__resize-handle--s', 'left:50%;bottom:-8px;transform:translateX(-50%);cursor:ns-resize;');
    const hSE = mkHandle('koopo-stories__resize-handle--se', 'right:-8px;bottom:-8px;cursor:nwse-resize;');

    const startResize = (mode, startX, startY) => {
      const s = ensureStickerStyle(sticker);
      if (typeof s.box_w !== 'number') s.box_w = 200;
      if (typeof s.box_h !== 'number') s.box_h = 80;
      const w0 = s.box_w;
      const h0 = s.box_h;

      const onMove = (clientX, clientY) => {
        const dx = clientX - startX;
        const dy = clientY - startY;
        const nextW = Math.max(120, Math.min(420, w0 + dx));
        const nextH = Math.max(40, Math.min(260, h0 + dy));
        if (mode === 'e' || mode === 'se') s.box_w = nextW;
        if (mode === 's' || mode === 'se') s.box_h = nextH;
        if (s.box_w) content.style.width = `${s.box_w}px`;
        if (s.box_h) content.style.height = `${s.box_h}px`;
        delete s.scale_x;
        delete s.scale_y;
        applyStickerPresentation(content, sticker);
      };

      const onPointerMove = (e) => onMove(e.clientX, e.clientY);
      const onPointerUp = () => {
        document.removeEventListener('pointermove', onPointerMove);
        document.removeEventListener('pointerup', onPointerUp);
      };
      document.addEventListener('pointermove', onPointerMove);
      document.addEventListener('pointerup', onPointerUp);
    };

    [ [hEast, 'e'], [hSouth, 's'], [hSE, 'se'] ].forEach(([handle, mode]) => {
      handle.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        e.preventDefault();
        startResize(mode, e.clientX, e.clientY);
      });
    });
  }

  // Global sticker element creator for composer preview
  function createStickerElement(sticker, pendingStickers, preview, assignStickerCid) {
    const wrapper = el('div', {
      class: 'koopo-stories__sticker',
      style: `position:absolute;left:${sticker.position.x}%;top:${sticker.position.y}%;transform:translate(-50%,-50%);z-index:10;`
    });

    let content;

    switch (sticker.type) {
      case 'mention':
        content = el('div', {
          class: 'koopo-stories__sticker-mention',
          style: 'background:rgba(0,0,0,0.7);color:#fff;padding:8px 16px;border-radius:20px;font-size:14px;font-weight:500;cursor:pointer;'
        });
        content.textContent = `@${sticker.data.username}`;
        if (sticker.data.profile_url) {
          content.onclick = () => window.open(sticker.data.profile_url, '_blank');
        }
        break;

      case 'link':
        content = el('div', {
          class: 'koopo-stories__sticker-link',
          style: 'background:rgba(255,255,255,0.95);color:#000;padding:12px 16px;border-radius:12px;font-size:13px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.2);max-width:200px;'
        });
        const linkIcon = el('div', { style: 'font-size:18px;margin-bottom:4px;' });
        linkIcon.innerHTML = '<span class="dashicons dashicons-admin-links"></span>';
        const linkTitle = el('div', { style: 'font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' });
        linkTitle.textContent = sticker.data.title;
        const linkUrl = el('div', { style: 'font-size:11px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' });
        linkUrl.textContent = sticker.data.url;
        content.appendChild(linkIcon);
        content.appendChild(linkTitle);
        content.appendChild(linkUrl);
        content.onclick = () => window.open(sticker.data.url, '_blank');
        break;

      case 'location':
        content = el('div', {
          class: 'koopo-stories__sticker-location',
          style: 'background:rgba(0,0,0,0.7);color:#fff;padding:10px 14px;border-radius:16px;font-size:13px;cursor:pointer;'
        });
        const locIcon = el('span', { style: 'margin-right:6px;font-size:16px;' });
        locIcon.innerHTML = '<span class="dashicons dashicons-location"></span>';
        const locName = el('span', { style: 'font-weight:500;' });
        locName.textContent = sticker.data.name;
        content.appendChild(locIcon);
        content.appendChild(locName);
        if (sticker.data.lat && sticker.data.lng) {
          content.onclick = () => {
            window.open(`https://maps.google.com/?q=${sticker.data.lat},${sticker.data.lng}`, '_blank');
          };
        }
        break;

      case 'poll':
        content = el('div', {
          class: 'koopo-stories__sticker-poll',
          style: 'background:rgba(255,255,255,0.95);color:#000;padding:16px;border-radius:16px;min-width:250px;max-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.3);'
        });
        const pollQuestion = el('div', { style: 'font-weight:600;font-size:15px;margin-bottom:12px;' });
        pollQuestion.textContent = sticker.data.question;
        content.appendChild(pollQuestion);

        if (sticker.data.options && Array.isArray(sticker.data.options)) {
          sticker.data.options.forEach((option) => {
            const optionEl = el('div', {
              style: 'background:#f0f0f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;'
            });
            const optionText = el('span', { style: 'font-size:14px;font-weight:500;' });
            optionText.textContent = option.text;
            optionEl.appendChild(optionText);
            content.appendChild(optionEl);
          });
        }
        break;
      case 'text':
        content = el('div', {
          class: 'koopo-stories__sticker-text',
          style: 'background:rgba(0,0,0,0.6);color:#fff;padding:10px 14px;border-radius:12px;font-size:16px;font-weight:600;max-width:none;text-align:left;'
        });
        content.textContent = sticker.data.text || '';
        break;
      case 'media':
        content = el('div', {
          class: 'koopo-stories__sticker-media',
          style: 'width:112px;height:112px;border-radius:14px;overflow:hidden;box-shadow:0 6px 16px rgba(0,0,0,.25);background:rgba(0,0,0,.08);'
        });
        if (sticker.data && sticker.data.mime === 'application/json') {
          const lottie = el('lottie-player', {
            src: sticker.data.url || '',
            background: 'transparent',
            speed: '1',
            loop: 'true',
            autoplay: 'true',
            style: 'width:100%;height:100%;'
          });
          content.appendChild(lottie);
          loadLottiePlayer().catch(() => {});
        } else {
          const mediaImg = el('img', {
            src: (sticker.data && sticker.data.url) ? sticker.data.url : '',
            alt: (sticker.data && sticker.data.title) ? sticker.data.title : '',
            style: 'width:100%;height:100%;object-fit:cover;display:block;'
          });
          content.appendChild(mediaImg);
        }
        break;
      default:
        return null;
    }

    if (content) {
      applyStickerPresentation(content, sticker);
      wrapper.appendChild(content);
      wrapper.__stickerContent = content;
      wrapper.addEventListener('pointerdown', () => {
        if (preview) {
          preview.querySelectorAll('.koopo-stories__sticker.is-active').forEach((node) => node.classList.remove('is-active'));
        }
        wrapper.classList.add('is-active');
      });
      if (Array.isArray(pendingStickers) && preview) {
        attachStickerControls(wrapper, content, sticker, pendingStickers, preview, assignStickerCid);
        attachResizeHandles(wrapper, content, sticker);
      }
      return wrapper;
    }

    return null;
  }

  window.KoopoStoriesModules = window.KoopoStoriesModules || {};
  window.KoopoStoriesModules.composer = { uploader, openComposer };
})();
