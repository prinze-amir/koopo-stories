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

  function openComposer(file) {
    // Simple preview + confirm composer (MVP+)
    const overlay = el('div', { class: 'koopo-stories__composer', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title', html: 'Post a story' });

    const preview = el('div', {
      class: 'koopo-stories__composer-preview',
      style: 'position:relative;'
    });
    const url = URL.createObjectURL(file);

    let mediaEl;
    if ((file.type || '').startsWith('video/')) {
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
    preview.appendChild(mediaEl);

    // Sticker toolbar
    const stickerToolbar = el('div', {
      class: 'koopo-stories__composer-toolbar',
      style: 'display:flex;gap:8px;padding:12px;background:rgba(0,0,0,0.05);border-radius:8px;margin:12px 0;'
    });

    const stickerButtons = [
      { icon: '@', label: 'Mention', type: 'mention' },
      { icon: '🔗', label: 'Link', type: 'link' },
      { icon: '📍', label: 'Location', type: 'location' },
      { icon: '📊', label: 'Poll', type: 'poll' },
      { icon: 'Aa', label: 'Text', type: 'text' }
    ];

    // Array to store stickers to be added
    const pendingStickers = [];

    stickerButtons.forEach(btn => {
      const button = el('button', {
        class: 'koopo-stories__composer-sticker-btn',
        type: 'button',
        style: 'flex:1;padding:8px;background:#fff;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-size:12px;display:flex;flex-direction:column;align-items:center;gap:4px;transition:background 0.2s;'
      });

      const icon = el('span', { style: 'font-size:20px;' });
      icon.textContent = btn.icon;
      const label = el('span', { style: 'font-size:11px;color:#666;' });
      label.textContent = btn.label;

      button.appendChild(icon);
      button.appendChild(label);

      button.onclick = () => openStickerModal(btn.type, pendingStickers, preview);
      button.onmouseover = () => { button.style.background = '#f0f0f0'; };
      button.onmouseout = () => { button.style.background = '#fff'; };

      stickerToolbar.appendChild(button);
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
      try { URL.revokeObjectURL(url); } catch(e) {}
      overlay.remove();
    }

    cancelBtn.addEventListener('click', close);
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
      fd.append('file', file);
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

        status.textContent = 'Posted!';
        // Refresh all trays/widgets on page
        document.querySelectorAll('.koopo-stories').forEach(c => refreshTray(c));
        setTimeout(close, 400);
      } catch(e) {
        status.textContent = e.message || 'Upload failed';
        cancelBtn.disabled = false;
        postBtn.disabled = false;
      }
    });

    panel.appendChild(title);
    panel.appendChild(preview);
    panel.appendChild(stickerToolbar);
    panel.appendChild(privacyWrap);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.focus();
  }

  // Open modal to add a sticker
  function openStickerModal(type, pendingStickers, preview) {
    const modalOverlay = el('div', {
      class: 'koopo-stories__composer',
      style: 'z-index:9999999;'
    });

    const modalPanel = el('div', {
      class: 'koopo-stories__composer-panel koopo-stories__composer-panel--modal',
      style: 'max-width:400px;'
    });

    const modalTitle = el('div', { class: 'koopo-stories__composer-title' });
    modalTitle.textContent = `Add ${type.charAt(0).toUpperCase() + type.slice(1)}`;

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
      const textInput = el('textarea', {
        placeholder: 'Write a caption...',
        style: 'width:100%;min-height:80px;border-radius:8px;border:1px solid #ddd;padding:10px;'
      });
      form.appendChild(textInput);
      stickerData.getData = () => {
        return {
          text: textInput.value.trim()
        };
      };
    }

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel', type: 'button' });
    cancelBtn.textContent = 'Cancel';
    const addBtn = el('button', { class: 'koopo-stories__composer-post', type: 'button' });
    addBtn.textContent = 'Add Sticker';

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

      // Add sticker to pending list
      const sticker = {
        type,
        data: stickerData,
        position: { x: 50, y: 50 } // Default center position
      };

      pendingStickers.push(sticker);

      // Show preview of sticker on the media
      const stickerPreview = createStickerElement(sticker);
      if (stickerPreview) {
        stickerPreview.style.cursor = 'move';
        preview.appendChild(stickerPreview);

        // Make sticker draggable within preview
        makeDraggable(stickerPreview, preview, sticker.position);
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
        if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
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
      if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
      startDrag(e.clientX, e.clientY);
      e.preventDefault();
    };

    document.onmousemove = (e) => {
      if (!isDragging) return;
      updatePosition(e.clientX, e.clientY);
    };

    document.onmouseup = endDrag;
  }

  // Global sticker element creator for composer preview
  function createStickerElement(sticker) {
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
        linkIcon.textContent = '🔗';
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
        locIcon.textContent = '📍';
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
          style: 'background:rgba(0,0,0,0.6);color:#fff;padding:10px 14px;border-radius:12px;font-size:16px;font-weight:600;max-width:240px;text-align:center;'
        });
        content.textContent = sticker.data.text || '';
        break;

      default:
        return null;
    }

    if (content) {
      wrapper.appendChild(content);
      return wrapper;
    }

    return null;
  }

  window.KoopoStoriesModules = window.KoopoStoriesModules || {};
  window.KoopoStoriesModules.composer = { uploader, openComposer };
})();
