(() => {
  if (!window.KoopoStoriesUI) return;

  const {
    API_BASE,
    t,
    isMobile,
    apiGet,
    apiPost,
    apiRequest,
    el,
    refreshTray,
  } = window.KoopoStoriesUI;

  // Viewer singleton
  const Viewer = (() => {
    let root, barsWrap, headerAvatar, headerName, headerTime, closeBtn, reportBtn, stage, tapPrev, tapNext, headerAvatarLink, viewCount, reactionCount, muteBtn, actionsBtn;
    let bottomBar, reactionBtn, replyBtn;
    let story = null;
    let allStories = [];  // All available stories in the tray
    let currentStoryIndex = 0;  // Index in allStories array
    let itemIndex = 0;
    let raf = null;
    let startTs = 0;
    let duration = 5000;
    let paused = false;
    let isMuted = true;
    let previousAuthorId = null;  // Track previous author for flip animation
    let reactionBurstActive = false;
    let pendingAdvance = false;
    let canManageStory = false;

    function ensure() {
      if (root) return;
      barsWrap = el('div', { class: 'koopo-stories__progress' });
      headerAvatar = el('img', { src: '' });
      headerAvatarLink = el('a', { href: '#', class: 'koopo-stories__avatar-link' }, [headerAvatar]);
      headerName = el('div', { class: 'koopo-stories__who', html: '' });
      headerTime = el('div', { class: 'koopo-stories__who-time', style: 'font-size:11px;opacity:0.7;margin-top:2px;' });

      // Stats container (views and reactions)
      const statsWrap = el('div', { style: 'margin-left:auto;display:flex;gap:12px;align-items:center;' });
      viewCount = el('div', { class: 'koopo-stories__view-count', style: 'font-size:12px;opacity:0.8;cursor:pointer;', html: '' });
      reactionCount = el('div', { class: 'koopo-stories__reaction-count', style: 'font-size:12px;opacity:0.8;', html: '' });
      statsWrap.appendChild(viewCount);
      statsWrap.appendChild(reactionCount);

      muteBtn = el('button', { class: 'koopo-stories__mute', type: 'button', style: 'background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:0 10px;opacity:0.7;', title: 'Toggle sound', 'aria-label': 'Toggle sound' });
      muteBtn.textContent = '🔇';
      reportBtn = el('button', { class: 'koopo-stories__report', type: 'button', style: 'background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:0 10px;opacity:0.7;', title: 'Report this story', 'aria-label': 'Report this story' });
      reportBtn.textContent = '⚠';
      actionsBtn = el('button', { class: 'koopo-stories__actions', type: 'button', style: 'background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:0 8px;opacity:0.8;', title: 'Story options', 'aria-label': 'Story options' });
      actionsBtn.textContent = '. . .';
      closeBtn = el('button', { class: 'koopo-stories__close', type: 'button', 'aria-label': 'Close story viewer' }, []);
      closeBtn.textContent = 'X';
      const whoWrap = el('div', { style: 'display:flex;flex-direction:column;gap:0;' }, [headerName, headerTime]);
      const header = el('div', { class: 'koopo-stories__header' }, [headerAvatarLink, whoWrap, statsWrap, muteBtn, reportBtn, actionsBtn, closeBtn]);

      stage = el('div', { class: 'koopo-stories__stage' });

      // Add loading overlay
      const loadingOverlay = el('div', { class: 'koopo-stories__loader', style: 'display:none;' });
      const spinner = el('div', { class: 'koopo-stories__spinner' });
      loadingOverlay.appendChild(spinner);
      stage.appendChild(loadingOverlay);

      tapPrev = el('div', { class: 'koopo-stories__tap koopo-stories__tap--prev' });
      tapNext = el('div', { class: 'koopo-stories__tap koopo-stories__tap--next' });
      stage.appendChild(tapPrev);
      stage.appendChild(tapNext);

      // Bottom bar with reaction and reply buttons
      reactionBtn = el('button', {
        class: 'koopo-stories__action-btn',
        style: 'background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:8px 16px;',
        'aria-label': 'React to story',
      });
      reactionBtn.textContent = '❤️ React';

      replyBtn = el('button', {
        class: 'koopo-stories__action-btn',
        style: 'background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:8px 16px;',
        'aria-label': 'Reply to story',
      });
      replyBtn.textContent = '💬 Reply';

      bottomBar = el('div', {
        class: 'koopo-stories__viewer-bottom',
      }, [reactionBtn, replyBtn]);

      const top = el('div', { class: 'koopo-stories__viewer-top' }, [barsWrap, header]);
      root = el('div', { class: 'koopo-stories__viewer', role: 'dialog', 'aria-modal': 'true', tabindex: '0' }, [top, stage, bottomBar]);
      document.body.appendChild(root);

      closeBtn.addEventListener('click', close);
      root.addEventListener('click', (e) => {
        if (e.target === root) close();
      });

      // Tap zones for prev/next
      tapPrev.addEventListener('click', (e) => { e.stopPropagation(); prev(); });
      tapNext.addEventListener('click', (e) => { e.stopPropagation(); next(); });

      // Keyboard navigation
      root.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
        } else if (e.key === 'ArrowRight') {
          e.preventDefault();
          next();
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          prev();
        }
      });

      // Mute button
      muteBtn.addEventListener('click', () => {
        isMuted = !isMuted;
        muteBtn.textContent = isMuted ? '🔇' : '🔊';
        const currentVideo = stage.querySelector('video.koopo-stories__media');
        if (currentVideo) {
          currentVideo.muted = isMuted;
        }
      });

      // Actions button
      actionsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        paused = true; // Pause story while menu open
        showStoryActions(story);
      });

      // Report button
      reportBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        reportBtn.classList.add('is-clicked');
        setTimeout(() => reportBtn.classList.remove('is-clicked'), 160);
        paused = true; // Pause story while reporting
        showReportModal(story.story_id, story.author);
      });

      // Reaction button - show reaction picker
      reactionBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        reactionBtn.classList.add('is-clicked');
        setTimeout(() => reactionBtn.classList.remove('is-clicked'), 160);
        paused = true; // Pause story while reacting
        const currentItemId = Viewer.currentItemId?.();
        const currentStoryId = Viewer.currentItemStoryId?.() || story.story_id;
        showReactionPicker(currentStoryId, currentItemId);
      });

      // Reply button - show reply modal
      replyBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        replyBtn.classList.add('is-clicked');
        setTimeout(() => replyBtn.classList.remove('is-clicked'), 160);
        paused = true; // Pause story while replying
        showReplyModal(story.story_id, story.author);
      });

      // Click on view count to show viewers list
      viewCount.addEventListener('click', (e) => {
        e.stopPropagation();
        if (story && story.story_id) {
          paused = true; // Pause story while viewing list
          const currentStoryId = Viewer.currentItemStoryId?.() || story.story_id;
          const currentItemId = Viewer.currentItemId?.();
          showViewerList(currentStoryId, currentItemId);
        }
      });
    }

    function open(s, all = [], idx = 0, startAtLast = false) {
      ensure();
      story = s;
      allStories = all;
      currentStoryIndex = idx;

      const items = story.items || [];
      itemIndex = startAtLast ? Math.max(0, items.length - 1) : 0;

      root.classList.add('is-open');
      root.focus();
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';

      // Update header
      headerAvatar.src = story.author?.avatar || '';
      headerName.innerHTML = story.author?.name || '';
      headerTime.textContent = story.posted_at_human || '';
      headerAvatarLink.href = story.author?.profile_url || '#';

      // Update avatar flip animation if author changed
      if (previousAuthorId && previousAuthorId !== story.author?.id) {
        headerAvatar.classList.add('flip');
        setTimeout(() => headerAvatar.classList.remove('flip'), 600);
      }
      previousAuthorId = story.author?.id;

      canManageStory = !!story?.can_manage || Number(story.author?.id || 0) === Number(window.KoopoStories?.me || 0);

      // Hide reaction/reply buttons for own stories
      const isOwnStory = Number(story.author?.id || 0) === Number(window.KoopoStories?.me || 0);
      if (isOwnStory) {
        reactionBtn.style.display = 'none';
        replyBtn.style.display = 'none';
        reportBtn.style.display = 'none';
      } else {
        reactionBtn.style.display = 'block';
        replyBtn.style.display = 'block';
        reportBtn.style.display = 'block';
      }
      actionsBtn.style.display = canManageStory ? 'block' : 'none';

      buildBars(items.length);
      playItem(itemIndex);
    }

    function close() {
      if (!root) return;
      // Add closing animation
      root.classList.add('is-closing');
      setTimeout(() => {
        root.classList.remove('is-open');
        root.classList.remove('is-closing');
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        cancel();
        story = null;

        // No auto refresh after viewing
      }, 300); // Match CSS transition duration
    }

    function cancel() {
      if (raf) cancelAnimationFrame(raf);
      raf = null;
    }

    function buildBars(n) {
      barsWrap.innerHTML = '';
      for (let i=0;i<n;i++) {
        const fill = el('i');
        const bar = el('div', { class: 'koopo-stories__bar' }, [fill]);
        barsWrap.appendChild(bar);
      }
    }

    function setBar(i, pct) {
      const bar = barsWrap.children[i];
      if (!bar) return;
      const fill = bar.querySelector('i');
      if (fill) fill.style.width = `${Math.max(0, Math.min(100, pct))}%`;
    }

    function currentProgress() {
      if (!duration) return 0;
      return Math.max(0, Math.min(1, (performance.now() - startTs) / duration));
    }

    async function markSeen(itemId) {
      try { await apiPost(`${API_BASE}/items/${itemId}/seen`, null); } catch(e) {}
    }

    function playItem(i) {
      cancel();
      itemIndex = i;
      const items = story.items || [];
      if (!items[itemIndex]) { close(); return; }

      // Fill previous bars
      for (let b=0;b<items.length;b++) setBar(b, b < itemIndex ? 100 : 0);

      const item = items[itemIndex];
      updateAnalyticsForItem(item);

      // Add transition animation
      stage.classList.add('transitioning');
      setTimeout(() => {
        stage.querySelectorAll('.koopo-stories__media').forEach(n => n.remove());
        loadMediaForItem(item);
        stage.classList.remove('transitioning');
      }, 200);
    }

    function updateAnalyticsForItem(item) {
      if (!canManageStory) {
        viewCount.style.display = 'none';
        reactionCount.style.display = 'none';
        return;
      }

      const itemAnalytics = item?.analytics || {};
      const fallbackAnalytics = story.analytics || {};
      const views = itemAnalytics.view_count ?? fallbackAnalytics.view_count ?? 0;
      const reactions = itemAnalytics.reaction_count ?? fallbackAnalytics.reaction_count ?? 0;

      if (views > 0) {
        viewCount.textContent = `👁️ ${views}`;
        viewCount.style.display = 'block';
      } else {
        viewCount.style.display = 'none';
      }

      if (reactions > 0) {
        reactionCount.textContent = `❤️ ${reactions}`;
        reactionCount.style.display = 'block';
      } else {
        reactionCount.style.display = 'none';
      }
    }

    function loadMediaForItem(item) {

      if (item.type === 'video') {
        const vid = document.createElement('video');
        vid.className = 'koopo-stories__media';
        vid.src = item.src;
        vid.playsInline = true;
        vid.muted = isMuted;
        vid.autoplay = true;
        vid.controls = false;
        vid.addEventListener('loadedmetadata', () => {
          duration = (vid.duration && isFinite(vid.duration)) ? vid.duration * 1000 : 8000;
          startTs = performance.now();
          loop();
        });
        vid.addEventListener('ended', () => next());
        stage.appendChild(vid);

        // Show mute button for videos
        muteBtn.style.display = 'block';

        vid.play().catch(()=>{});
      } else {
        const img = document.createElement('img');
        img.className = 'koopo-stories__media';
        img.src = item.src;
        stage.appendChild(img);

        // Hide mute button for images
        muteBtn.style.display = 'none';

        duration = item.duration_ms || 5000;
        startTs = performance.now();
        loop();
      }

      // Render stickers for this item
      renderStickers(item);

      // mark seen once
      if (item.item_id) markSeen(item.item_id);
    }

    // Helper function to resume story after modal closes
    function resumeStory() {
      paused = false;
      startTs = performance.now() - currentProgress() * duration;
      loop();
    }

    function currentItemStoryId() {
      const items = story?.items || [];
      const item = items[itemIndex];
      const storyId = parseInt(item?.story_id || 0, 10);
      return storyId > 0 ? storyId : null;
    }

    function currentItemId() {
      const items = story?.items || [];
      const item = items[itemIndex];
      const itemId = parseInt(item?.item_id || 0, 10);
      return itemId > 0 ? itemId : null;
    }

    function loop() {
      cancel();
      const items = story.items || [];
      const item = items[itemIndex];
      if (!item) return;

      if (!paused) {
        const pct = currentProgress() * 100;
        setBar(itemIndex, pct);
        if (pct >= 100) { next(); return; }
      }
      raf = requestAnimationFrame(loop);
    }

    // Helper function to load all stories for a user (combining multiple stories into one)
    async function loadUserStories(storyData) {
      if (!storyData || !storyData.story_id) return null;

      try {
        // If this user has multiple stories, fetch and combine them
        if (storyData.story_ids && storyData.story_ids.length > 1) {
          const storyPromises = storyData.story_ids.map(sid => apiGet(`${API_BASE}/${sid}`));
          const authorStories = await Promise.all(storyPromises);

          // Combine all items from all stories into one virtual story
          const combinedStory = {
            story_id: storyData.story_id,
            story_ids: storyData.story_ids || [storyData.story_id],
            author: storyData.author,
            items: [],
            privacy: storyData.privacy,
            analytics: {
              view_count: 0,
              reaction_count: 0,
            },
          };

          authorStories.forEach(story => {
            if (story.items && Array.isArray(story.items)) {
              combinedStory.items = combinedStory.items.concat(story.items);
            }
            const storyViews = story.analytics?.view_count || 0;
            const storyReactions = story.analytics?.reaction_count || 0;
            combinedStory.analytics.view_count = Math.max(combinedStory.analytics.view_count, storyViews);
            combinedStory.analytics.reaction_count += storyReactions;
          });

          return combinedStory;
        } else {
          // Single story, just fetch it
          return await apiGet(`${API_BASE}/${storyData.story_id}`);
        }
      } catch(e) {
        console.error('Failed to load user stories:', e);
        return null;
      }
    }

    async function next() {
      if (reactionBurstActive) {
        pendingAdvance = true;
        return;
      }
      const items = story.items || [];
      if (itemIndex + 1 < items.length) {
        // More items in current story
        playItem(itemIndex + 1);
      } else if (allStories.length > 0 && currentStoryIndex + 1 < allStories.length) {
        // Current story finished, load next user's story
        const nextStoryData = allStories[currentStoryIndex + 1];
        const nextStory = await loadUserStories(nextStoryData);

        if (nextStory) {
          open(nextStory, allStories, currentStoryIndex + 1);

          // Update ring to mark as seen
          const bubble = document.querySelector(`.koopo-stories__bubble[data-story-id="${nextStoryData.story_id}"]`);
          if (bubble) {
            bubble.setAttribute('data-seen', '1');
            const badge = bubble.querySelector('.koopo-stories__badge');
            if (badge) badge.remove();
          }
        } else {
          close();
        }
      } else {
        // No more stories
        close();
      }
    }

    async function prev() {
      if (itemIndex - 1 >= 0) {
        // Go to previous item in current story
        playItem(itemIndex - 1);
      } else if (allStories.length > 0 && currentStoryIndex - 1 >= 0) {
        // At first item, load previous user's story
        const prevStoryData = allStories[currentStoryIndex - 1];
        const prevStory = await loadUserStories(prevStoryData);

        if (prevStory) {
          open(prevStory, allStories, currentStoryIndex - 1, true); // true = go to last item
        }
      } else {
        // Already at first item of first story, replay current item
        playItem(0);
      }
    }

    // Skip to next user (skip all remaining items in current user's stories)
    async function skipToNextUser() {
      if (allStories.length > 0 && currentStoryIndex + 1 < allStories.length) {
        const nextStoryData = allStories[currentStoryIndex + 1];
        const nextStory = await loadUserStories(nextStoryData);

        if (nextStory) {
          open(nextStory, allStories, currentStoryIndex + 1);

          // Update ring to mark as seen
          const bubble = document.querySelector(`.koopo-stories__bubble[data-story-id="${nextStoryData.story_id}"]`);
          if (bubble) {
            bubble.setAttribute('data-seen', '1');
            const badge = bubble.querySelector('.koopo-stories__badge');
            if (badge) badge.remove();
          }
        } else {
          close();
        }
      } else {
        close();
      }
    }

    // Skip to previous user (skip all items in current user's stories)
    async function skipToPrevUser() {
      if (allStories.length > 0 && currentStoryIndex - 1 >= 0) {
        const prevStoryData = allStories[currentStoryIndex - 1];
        const prevStory = await loadUserStories(prevStoryData);

        if (prevStory) {
          open(prevStory, allStories, currentStoryIndex - 1);
        }
      }
    }

    function renderStickers(item) {
      // Remove existing stickers
      stage.querySelectorAll('.koopo-stories__sticker').forEach(s => s.remove());

      if (!item.stickers || item.stickers.length === 0) return;

      const stickers = item.stickers;
      stickers.forEach(sticker => {
        const stickerEl = createStickerElement(sticker);
        if (stickerEl) {
          stage.appendChild(stickerEl);
        }
      });
    }

    function launchReactionEffect(emoji) {
      if (!stage) return 0;
      const burst = el('div', { class: 'koopo-stories__reaction-burst' });
      const count = 12;
      for (let i = 0; i < count; i++) {
        const floaty = el('div', { class: 'koopo-stories__reaction-float' });
        floaty.textContent = emoji;
        floaty.style.left = `${10 + Math.random() * 80}%`;
        floaty.style.animationDelay = `${Math.random() * 0.2}s`;
        floaty.style.animationDuration = `${1.1 + Math.random() * 0.6}s`;
        burst.appendChild(floaty);
      }
      stage.appendChild(burst);
      const duration = 2000;
      setTimeout(() => {
        if (burst.parentNode) burst.parentNode.removeChild(burst);
      }, duration);
      return duration;
    }

    function holdForReaction(emoji) {
      paused = true;
      const duration = launchReactionEffect(emoji);
      if (!duration) {
        resumeStory();
        return;
      }
      reactionBurstActive = true;
      setTimeout(() => {
        reactionBurstActive = false;
        if (pendingAdvance) {
          pendingAdvance = false;
          next();
          return;
        }
        resumeStory();
      }, duration);
    }

    // Create sticker element based on type
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
            style: 'background:rgba(0,0,0,0.7);color:#fff;padding:8px 16px;border-radius:20px;font-size:14px;font-weight:500;cursor:pointer border:solid 1px #ffba12;'
          });
          content.textContent = `@${sticker.data.username}`;
          content.onclick = () => {
            if (sticker.data.profile_url) {
              window.open(sticker.data.profile_url, '_blank');
            }
          };
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
        content = createPollSticker(sticker);
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

    // Create interactive poll sticker
    function createPollSticker(sticker) {
      const pollData = sticker.data;

      const pollContainer = el('div', {
        class: 'koopo-stories__sticker-poll',
        style: 'background:rgba(255,255,255,0.95);color:#000;padding:16px;border-radius:16px;min-width:250px;max-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.3);'
      });

      // Question
      const question = el('div', {
        style: 'font-weight:600;font-size:15px;margin-bottom:12px;'
      });
      question.textContent = pollData.question;
      pollContainer.appendChild(question);

      // Calculate total votes
      const totalVotes = pollData.options.reduce((sum, opt) => sum + (opt.votes || 0), 0);

      // Options
      pollData.options.forEach((option, idx) => {
        const votes = option.votes || 0;
        const percentage = totalVotes > 0 ? Math.round((votes / totalVotes) * 100) : 0;

        const optionEl = el('div', {
          class: 'koopo-stories__poll-option',
          style: 'position:relative;background:#f0f0f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;cursor:pointer;overflow:hidden;'
        });

        // Progress bar
        const progressBar = el('div', {
          style: `position:absolute;top:0;left:0;bottom:0;width:${percentage}%;background:rgba(0,123,255,0.2);transition:width 0.3s;z-index:0;border-radius:8px;`
        });
        optionEl.appendChild(progressBar);

        // Option content
        const optionContent = el('div', { style: 'position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center;' });
        const optionText = el('span', { style: 'font-size:14px;font-weight:500;' });
        optionText.textContent = option.text;
        const optionVotes = el('span', { style: 'font-size:12px;opacity:0.7;font-weight:600;' });
        optionVotes.textContent = `${percentage}%`;
        optionContent.appendChild(optionText);
        optionContent.appendChild(optionVotes);
        optionEl.appendChild(optionContent);

        // Vote handler
        optionEl.onclick = async (e) => {
          e.stopPropagation();
          try {
            const fd = new FormData();
            fd.append('option_index', String(idx));
            await apiPost(`${API_BASE.replace('/stories', '')}/stickers/${sticker.id}/vote`, fd);

            // Update votes locally
            pollData.options[idx].votes = (pollData.options[idx].votes || 0) + 1;

            // Re-render poll
            const parent = pollContainer.parentElement;
            if (parent) {
              parent.removeChild(pollContainer);
              const newPoll = createPollSticker(sticker);
              parent.appendChild(newPoll);
            }
          } catch (err) {
            console.error('Failed to vote:', err);
          }
        };

        pollContainer.appendChild(optionEl);
      });

      // Total votes footer
      if (totalVotes > 0) {
        const footer = el('div', {
          style: 'font-size:12px;opacity:0.6;text-align:center;margin-top:8px;'
        });
        footer.textContent = `${totalVotes} vote${totalVotes !== 1 ? 's' : ''}`;
        pollContainer.appendChild(footer);
      }

      return pollContainer;
    }

    return { open, close, resumeStory, currentItemStoryId, currentItemId, launchReactionEffect, holdForReaction };
  })();

  function storyIdsForActions(storyData) {
    const currentStoryId = Viewer.currentItemStoryId?.();
    if (currentStoryId) return [currentStoryId];
    if (Array.isArray(storyData.story_ids) && storyData.story_ids.length > 0) {
      return storyData.story_ids.map(id => parseInt(id, 10)).filter(id => id > 0);
    }
    const id = parseInt(storyData.story_id || 0, 10);
    return id > 0 ? [id] : [];
  }

  function showStoryActions(storyData) {
    const targetIds = storyIdsForActions(storyData);
    const targetStoryId = targetIds[0];
    if (!targetStoryId) {
      Viewer.resumeStory();
      return;
    }
    const overlay = el('div', {
      class: 'koopo-stories__composer',
      style: 'z-index:9999999;background:rgba(0,0,0,0.45);',
      role: 'dialog',
      'aria-modal': 'true',
      tabindex: '-1'
    });

    const panel = el('div', {
      class: 'koopo-stories__composer-panel',
      style: 'max-width:360px;width:100%;'
    });

    const title = el('div', { class: 'koopo-stories__composer-title', html: t('story_settings', 'Story settings') });

    const privacyWrap = el('div', { class: 'koopo-stories__composer-privacy', style: 'border-top:0;' });
    const privacyLabel = el('label', { class: 'koopo-stories__composer-privacy-label' });
    privacyLabel.textContent = t('privacy_label', 'Privacy');
    const privacySelect = el('select', { class: 'koopo-stories__composer-privacy-select' });
    ['public', 'friends', 'close_friends'].forEach((val) => {
      const opt = el('option', { value: val });
      opt.textContent = val === 'public' ? 'Public' : (val === 'friends' ? 'Friends Only' : 'Close Friends');
      if ((storyData.privacy || 'friends') === val) opt.selected = true;
      privacySelect.appendChild(opt);
    });
    privacyWrap.appendChild(privacyLabel);
    privacyWrap.appendChild(privacySelect);

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel', type: 'button' });
    cancelBtn.textContent = t('close', 'Close');
    const saveBtn = el('button', { class: 'koopo-stories__composer-post', type: 'button' });
    saveBtn.textContent = t('save', 'Save');
    actions.appendChild(cancelBtn);
    actions.appendChild(saveBtn);

    const hideWrap = el('div', { style: 'padding:0 14px 14px 14px;border-top:1px solid rgba(255,255,255,.08);' });
    const hideTitle = el('div', { style: 'font-size:13px;font-weight:600;margin:10px 0 6px 0;' });
    hideTitle.textContent = t('hide_users_title', 'Hide from specific users');
    const hideInputWrap = el('div', { style: 'position:relative;' });
    const hideInput = el('input', {
      type: 'text',
      placeholder: t('search_username', 'Search by username'),
      style: 'width:100%;padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:#1a1a1a;color:#fff;'
    });
    const hideInputSpinner = el('div', { class: 'koopo-stories__spinner koopo-stories__spinner--sm koopo-stories__input-spinner' });
    const hideDropdown = el('div', {
      style: 'position:absolute;left:0;right:0;top:38px;background:#111;border:1px solid rgba(255,255,255,.1);border-radius:8px;max-height:200px;overflow:auto;display:none;z-index:5;'
    });
    hideInputWrap.appendChild(hideInput);
    hideInputWrap.appendChild(hideInputSpinner);
    hideInputWrap.appendChild(hideDropdown);
    const hideAddBtn = el('button', {
      type: 'button',
      style: 'margin-top:8px;width:100%;padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:#2a2a2a;color:#fff;font-weight:600;cursor:pointer;'
    });
    hideAddBtn.textContent = t('add_hidden', 'Add to hidden list');
    const hideAddSpinner = el('span', { class: 'koopo-stories__spinner koopo-stories__spinner--sm koopo-stories__btn-spinner' });
    hideAddBtn.appendChild(hideAddSpinner);
    const hiddenList = el('div', { style: 'margin-top:10px;display:flex;flex-direction:column;gap:6px;position:relative;min-height:20px;' });
    const hiddenListSpinner = el('div', { class: 'koopo-stories__spinner koopo-stories__spinner--sm koopo-stories__list-spinner' });
    hiddenList.appendChild(hiddenListSpinner);
    hideWrap.appendChild(hideTitle);
    hideWrap.appendChild(hideInputWrap);
    hideWrap.appendChild(hideAddBtn);
    hideWrap.appendChild(hiddenList);

    const deleteWrap = el('div', { style: 'padding:0 14px 14px 14px;' });
    const deleteBtn = el('button', {
      type: 'button',
      style: 'width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.25);background:transparent;color:#ff6b6b;font-weight:600;cursor:pointer;'
    });
    deleteBtn.textContent = t('delete_story', 'Delete story');
    deleteWrap.appendChild(deleteBtn);

    const archiveWrap = el('div', { style: 'padding:0 14px 14px 14px;' });
    const archiveBtn = el('button', {
      type: 'button',
      style: 'width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.25);background:transparent;color:#fff;font-weight:600;cursor:pointer;'
    });
    const isArchived = !!storyData.is_archived;
    archiveBtn.textContent = isArchived ? t('unarchive_story', 'Unarchive story') : t('archive_story', 'Archive story');
    archiveWrap.appendChild(archiveBtn);

    const closeModal = (resume = true) => {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      if (resume) Viewer.resumeStory();
    };

    cancelBtn.onclick = () => closeModal(true);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal(true);
    });
    overlay.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal(true);
      }
    });

    saveBtn.onclick = async () => {
      const ids = storyIdsForActions(storyData);
      if (!ids.length) return closeModal(true);

      const nextPrivacy = privacySelect.value;
      saveBtn.disabled = true;
      cancelBtn.disabled = true;
      deleteBtn.disabled = true;
      archiveBtn.disabled = true;
      hideAddBtn.disabled = true;
      try {
        for (const id of ids) {
          await apiRequest(`${API_BASE}/${id}`, 'PATCH', { privacy: nextPrivacy });
        }
        storyData.privacy = nextPrivacy;
        closeModal(true);
      } catch (err) {
        console.error('Failed to update privacy:', err);
        alert('Failed to update privacy. Please try again.');
      } finally {
        saveBtn.disabled = false;
        cancelBtn.disabled = false;
        deleteBtn.disabled = false;
        archiveBtn.disabled = false;
        hideAddBtn.disabled = false;
      }
    };

    archiveBtn.onclick = async () => {
      const ids = storyIdsForActions(storyData);
      if (!ids.length) return closeModal(true);

      archiveBtn.disabled = true;
      cancelBtn.disabled = true;
      saveBtn.disabled = true;
      deleteBtn.disabled = true;
      hideAddBtn.disabled = true;
      try {
        const nextArchived = !storyData.is_archived;
        for (const id of ids) {
          await apiRequest(`${API_BASE}/${id}`, 'PATCH', { archive: nextArchived });
        }
        storyData.is_archived = nextArchived;
        archiveBtn.textContent = nextArchived ? 'Unarchive story' : 'Archive story';
        closeModal(true);
      } catch (err) {
        console.error('Failed to update archive:', err);
        alert('Failed to update archive. Please try again.');
      } finally {
        archiveBtn.disabled = false;
        cancelBtn.disabled = false;
        saveBtn.disabled = false;
        deleteBtn.disabled = false;
        hideAddBtn.disabled = false;
      }
    };

    deleteBtn.onclick = async () => {
      const ids = storyIdsForActions(storyData);
      if (!ids.length) return closeModal(true);

      const confirmText = ids.length > 1 ? 'Delete all active stories?' : 'Delete this story?';
      if (!confirm(confirmText)) return;

      saveBtn.disabled = true;
      cancelBtn.disabled = true;
      deleteBtn.disabled = true;
      try {
        for (const id of ids) {
          await apiRequest(`${API_BASE}/${id}`, 'DELETE');
        }
        closeModal(false);
        Viewer.close();
      } catch (err) {
        console.error('Failed to delete story:', err);
        alert('Failed to delete story. Please try again.');
        saveBtn.disabled = false;
        cancelBtn.disabled = false;
        deleteBtn.disabled = false;
        archiveBtn.disabled = false;
        hideAddBtn.disabled = false;
      }
    };

    function renderHiddenUsers(users) {
      hiddenList.innerHTML = '';
      hiddenList.appendChild(hiddenListSpinner);
      hiddenListSpinner.style.display = 'none';
      if (!users.length) {
        const empty = el('div', { style: 'font-size:12px;opacity:0.7;' });
        empty.textContent = t('no_hidden_users', 'No hidden users yet.');
        hiddenList.appendChild(empty);
        return;
      }
      users.forEach(u => {
        const row = el('div', { style: 'display:flex;align-items:center;gap:8px;' });
        const avatar = el('img', { src: u.avatar || '', style: 'width:28px;height:28px;border-radius:999px;object-fit:cover;' });
        const label = el('div', { style: 'font-size:13px;flex:1;' });
        label.textContent = `@${u.username || u.name || u.id}`;
        const removeBtn = el('button', {
          type: 'button',
          style: 'padding:4px 8px;border-radius:6px;border:1px solid rgba(255,255,255,.2);background:#222;color:#fff;font-size:12px;cursor:pointer;'
        });
        removeBtn.textContent = t('remove', 'Remove');
        removeBtn.onclick = async () => {
          removeBtn.disabled = true;
          try {
            hiddenListSpinner.style.display = 'block';
            await apiRequest(`${API_BASE}/${targetStoryId}/hide/${u.id}`, 'DELETE');
            await loadHiddenUsers();
          } catch (err) {
            console.error('Failed to remove hidden user:', err);
            alert(t('remove_hidden_failed', 'Failed to remove user. Please try again.'));
          } finally {
            removeBtn.disabled = false;
          }
        };
        row.appendChild(avatar);
        row.appendChild(label);
        row.appendChild(removeBtn);
        hiddenList.appendChild(row);
      });
    }

    async function loadHiddenUsers() {
      hiddenListSpinner.style.display = 'block';
      try {
        const data = await apiGet(`${API_BASE}/${targetStoryId}/hide`);
        renderHiddenUsers(data.users || []);
      } catch (err) {
        console.error('Failed to load hidden users:', err);
      } finally {
        hiddenListSpinner.style.display = 'none';
      }
    }

    hideInput.oninput = async () => {
      const query = hideInput.value.trim();
      if (query.length < 2) {
        hideDropdown.style.display = 'none';
        hideDropdown.innerHTML = '';
        return;
      }

      hideInputSpinner.style.display = 'block';
      try {
        const resp = await apiGet(`${API_BASE}/search-users?query=${encodeURIComponent(query)}`);
        const users = resp.users || [];
        hideDropdown.innerHTML = '';
        if (!users.length) {
          const empty = el('div', { style: 'padding:8px 10px;font-size:12px;opacity:0.7;' });
          empty.textContent = 'No users found';
          hideDropdown.appendChild(empty);
        } else {
          users.forEach(u => {
            const row = el('div', { style: 'display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;' });
            const avatar = el('img', { src: u.avatar || '', style: 'width:28px;height:28px;border-radius:999px;object-fit:cover;' });
            const label = el('div', { style: 'font-size:13px;' });
            label.textContent = `@${u.username || u.name || u.id}`;
            row.appendChild(avatar);
            row.appendChild(label);
            row.onclick = () => {
              hideInput.value = u.username || '';
              hideInput.dataset.selectedUserId = String(u.id || '');
              hideDropdown.style.display = 'none';
            };
            hideDropdown.appendChild(row);
          });
        }
        hideDropdown.style.display = 'block';
      } catch (err) {
        console.error('Search failed:', err);
      } finally {
        hideInputSpinner.style.display = 'none';
      }
    };

    hideAddBtn.onclick = async () => {
      const selectedUserId = parseInt(hideInput.dataset.selectedUserId || '0', 10);
      if (!selectedUserId) {
        alert(t('select_user_hide', 'Select a user to hide.'));
        return;
      }
      hideAddBtn.disabled = true;
      try {
        hideAddSpinner.style.display = 'inline-block';
        await apiRequest(`${API_BASE}/${targetStoryId}/hide/${selectedUserId}`, 'POST');
        hideInput.value = '';
        hideInput.dataset.selectedUserId = '';
        hideDropdown.style.display = 'none';
        await loadHiddenUsers();
      } catch (err) {
        console.error('Failed to hide user:', err);
        alert(t('hide_user_failed', 'Failed to hide user. Please try again.'));
      } finally {
        hideAddSpinner.style.display = 'none';
        hideAddBtn.disabled = false;
      }
    };

    panel.appendChild(title);
    panel.appendChild(privacyWrap);
    panel.appendChild(actions);
    panel.appendChild(hideWrap);
    panel.appendChild(deleteWrap);
    panel.appendChild(archiveWrap);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.focus();

    loadHiddenUsers();
  }

  function showReactionPicker(storyId, itemId) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = 'React to story';

    const reactions = ['❤️', '😂', '😮', '😢', '👏', '🔥', '💩', '🤦🏽‍♂️', '👿', '🤯', '😘', '😡', '🥰', '😳', '🥶'];
    const maxVisible = isMobile ? 6 : reactions.length;

    const buttonsWrap = el('div', { style: 'display:flex;flex-wrap:wrap;gap:10px;padding:16px;justify-content:center;' });

    const renderPicker = (count) => {
      buttonsWrap.innerHTML = '';
      const visible = reactions.slice(0, count);
      visible.forEach(emoji => {
        const btn = el('button', {
          class: 'koopo-stories__reaction-option',
          style: 'font-size:28px;background:rgba(0, 0, 0, 0.1);border:none;border-radius:12px;padding:8px;cursor:pointer;width:52px;height:52px;display:flex;align-items:center;justify-content:center;'
        });
        btn.textContent = emoji;
        btn.onclick = async () => {
          try {
            const fd = new FormData();
            fd.append('reaction', emoji);
            if (itemId) fd.append('item_id', String(itemId));
            await apiPost(`${API_BASE}/${storyId}/reactions`, fd);
            if (Viewer.holdForReaction) {
              Viewer.holdForReaction(emoji);
            } else if (Viewer.launchReactionEffect) {
              Viewer.launchReactionEffect(emoji);
              Viewer.resumeStory();
            }
            overlay.remove();
          } catch(e) {
            console.error('Failed to react:', e);
          }
        };
        buttonsWrap.appendChild(btn);
      });

      if (isMobile && count < reactions.length) {
        const moreBtn = el('button', {
          style: 'font-size:14px;background:rgba(255,255,255,0.1);border:none;border-radius:12px;padding:8px 12px;cursor:pointer;color:#fff;'
        });
        moreBtn.textContent = '+ more';
        moreBtn.onclick = () => renderPicker(reactions.length);
        buttonsWrap.appendChild(moreBtn);
      }
    };

    renderPicker(maxVisible);

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel' });
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = () => { overlay.remove(); Viewer.resumeStory(); };
    actions.appendChild(cancelBtn);

   // panel.appendChild(title);
    panel.appendChild(buttonsWrap);
    panel.appendChild(actions);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.focus();
  }

  // Show reply modal
  function showReplyModal(storyId, author) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = `Reply to ${author?.name || 'story'}`;

    const textarea = el('textarea', {
      placeholder: 'Write a reply...',
      style: 'width:100%;min-height:100px;border-radius:10px;border:1px solid rgba(255,255,255,0.2);padding:12px;background:#111;color:#fff;resize:vertical;'
    });

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel' });
    cancelBtn.textContent = 'Cancel';
    const sendBtn = el('button', { class: 'koopo-stories__composer-post' });
    sendBtn.textContent = 'Send Reply';

    const status = el('div', { class: 'koopo-stories__composer-status' });
    status.setAttribute('aria-live', 'polite');

    const close = () => {
      overlay.remove();
      Viewer.resumeStory();
    };

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
    });

    sendBtn.addEventListener('click', async () => {
      sendBtn.disabled = true;
      cancelBtn.disabled = true;
      status.textContent = 'Sending...';

      try {
        const fd = new FormData();
        fd.append('message', textarea.value.trim());
        fd.append('is_dm', '1');
        await apiPost(`${API_BASE}/${storyId}/replies`, fd);
        status.textContent = 'Reply sent.';
        setTimeout(close, 1000);
      } catch(e) {
        status.textContent = e.message || 'Failed to send reply';
        sendBtn.disabled = false;
        cancelBtn.disabled = false;
      }
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(sendBtn);

    panel.appendChild(title);
    panel.appendChild(textarea);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.focus();
  }

  function showReportModal(storyId, author) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    const panel = el('div', { class: 'koopo-stories__composer-panel' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = `Report ${author?.name || 'story'}`;

    const selectWrap = el('div', { style: 'padding:12px 0;' });
    const reasonLabel = el('label', { style: 'display:block;font-size:13px;margin-bottom:6px;' });
    reasonLabel.textContent = 'Reason';
    const reasonSelect = el('select', {
      style: 'width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.2);background:#111;color:#fff;'
    });

    ['spam', 'harassment', 'nudity', 'violence', 'other'].forEach(r => {
      const opt = el('option', { value: r });
      opt.textContent = r.charAt(0).toUpperCase() + r.slice(1);
      reasonSelect.appendChild(opt);
    });

    const textarea = el('textarea', {
      placeholder: 'Add details (optional)',
      style: 'width:100%;min-height:80px;border-radius:10px;border:1px solid rgba(255,255,255,0.2);padding:12px;background:#111;color:#fff;resize:vertical;margin-top:10px;'
    });

    selectWrap.appendChild(reasonLabel);
    selectWrap.appendChild(reasonSelect);
    selectWrap.appendChild(textarea);

    const actions = el('div', { class: 'koopo-stories__composer-actions' });
    const cancelBtn = el('button', { class: 'koopo-stories__composer-cancel' });
    cancelBtn.textContent = 'Cancel';
    const submitBtn = el('button', { class: 'koopo-stories__composer-post' });
    submitBtn.textContent = 'Submit Report';

    const status = el('div', { class: 'koopo-stories__composer-status' });
    status.setAttribute('aria-live', 'polite');

    const close = () => overlay.remove();

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
    });

    submitBtn.addEventListener('click', async () => {
      submitBtn.disabled = true;
      cancelBtn.disabled = true;
      status.textContent = 'Submitting report...';

      try {
        const fd = new FormData();
        fd.append('reason', reasonSelect.value);
        fd.append('description', textarea.value.trim());
        await apiPost(`${API_BASE}/${storyId}/report`, fd);
        status.textContent = 'Report submitted. Thank you.';
        setTimeout(close, 1500);
      } catch(e) {
        status.textContent = e.message || 'Failed to submit report';
        submitBtn.disabled = false;
        cancelBtn.disabled = false;
      }
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(submitBtn);

    panel.appendChild(title);
    panel.appendChild(selectWrap);
    panel.appendChild(actions);
    panel.appendChild(status);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.focus();
  }

  // Show viewer list modal
  async function showViewerList(storyId, itemId) {
    const overlay = el('div', { class: 'koopo-stories__composer', style: 'z-index:9999999;' });
    const panel = el('div', { class: 'koopo-stories__composer-panel', style: 'max-height:80vh;overflow:hidden;' });
    const title = el('div', { class: 'koopo-stories__composer-title' });
    title.textContent = 'Viewers';

    const listWrap = el('div', { style: 'max-height:60vh;overflow-y:auto;padding:12px 14px;' });
    const loading = el('div', { class: 'koopo-stories__loader', style: 'position:relative;padding:40px;' });
    const spinner = el('div', { class: 'koopo-stories__spinner' });
    loading.appendChild(spinner);
    listWrap.appendChild(loading);

    const closeBtn = el('button', { class: 'koopo-stories__composer-cancel', style: 'margin:12px 14px;width:calc(100% - 28px);' });
    closeBtn.textContent = 'Close';

    const close = () => {
      overlay.remove();
      Viewer.resumeStory();
    };
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    panel.appendChild(title);
    panel.appendChild(listWrap);
    panel.appendChild(closeBtn);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    // Fetch viewer list
    try {
      const query = itemId ? `?item_id=${encodeURIComponent(itemId)}` : '';
      const resp = await apiGet(`${API_BASE}/${storyId}/viewers${query}`);
      listWrap.innerHTML = '';

      if (!resp.viewers || resp.viewers.length === 0) {
        const empty = el('div', { style: 'text-align:center;padding:20px;opacity:0.6;' });
        empty.textContent = 'No views yet';
        listWrap.appendChild(empty);
        return;
      }

      resp.viewers.forEach(viewer => {
        const row = el('div', { style: 'display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);' });

        const avatar = el('img', {
          src: viewer.avatar,
          style: 'width:40px;height:40px;border-radius:999px;'
        });

        const info = el('div', { style: 'flex:1;' });
        const name = el('div', { style: 'font-weight:500;font-size:14px;' });
        name.textContent = viewer.name;

        const time = el('div', { style: 'font-size:12px;opacity:0.7;margin-top:2px;' });
        const viewDate = new Date(viewer.viewed_at);
        time.textContent = viewDate.toLocaleString();

        info.appendChild(name);
        info.appendChild(time);

        row.appendChild(avatar);
        row.appendChild(info);

        if (viewer.reaction) {
          const reaction = el('div', { class: 'koopo-stories__viewer-reaction' });
          reaction.textContent = viewer.reaction;
          row.appendChild(reaction);
        }

        if (viewer.profile_url) {
          row.style.cursor = 'pointer';
          row.onclick = () => window.open(viewer.profile_url, '_blank');
        }

        listWrap.appendChild(row);
      });

      // Show total count
      if (resp.total_count > resp.viewers.length) {
        const more = el('div', { style: 'text-align:center;padding:12px;opacity:0.6;font-size:13px;' });
        more.textContent = `Showing ${resp.viewers.length} of ${resp.total_count} viewers`;
        listWrap.appendChild(more);
      }
    } catch(e) {
      listWrap.innerHTML = '';
      const error = el('div', { style: 'text-align:center;padding:20px;color:#d63638;' });
      error.textContent = 'Failed to load viewers';
      listWrap.appendChild(error);
    }
  }

  window.KoopoStoriesModules = window.KoopoStoriesModules || {};
  window.KoopoStoriesModules.viewer = Viewer;
})();
