(() => {
  if (!window.KoopoStories) return;

  const API_BASE = window.KoopoStories.restUrl; // .../koopo/v1/stories
  const NONCE = window.KoopoStories.nonce;
  const i18n = window.KoopoStoriesI18n || {};
  const t = (key, fallback) => (i18n && i18n[key]) ? i18n[key] : fallback;
  const isMobile = (window.matchMedia && window.matchMedia('(max-width: 768px)').matches)
    || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');

  function withCompact(url) {
    if (!isMobile) return url;
    try {
      const u = new URL(url, window.location.origin);
      if (!u.searchParams.has('compact')) {
        u.searchParams.set('compact', '1');
      }
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  const headers = () => ({
    'X-WP-Nonce': NONCE,
  });

  async function apiGet(url) {
    const res = await fetch(withCompact(url), { credentials: 'same-origin', headers: headers() });
    if (!res.ok) throw new Error('Request failed');
    return res.json();
  }

  async function apiPost(url, body) {
    const isFormData = body instanceof FormData;
    const fetchHeaders = isFormData ? headers() : { ...headers(), 'Content-Type': 'application/json' };
    const fetchBody = isFormData ? body : JSON.stringify(body);

    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: fetchHeaders,
      body: fetchBody,
    });
    if (!res.ok) {
      let msg = 'Request failed';
      try { const j = await res.json(); msg = j.message || j.error || msg; } catch(e){}
      throw new Error(msg);
    }
    return res.json();
  }

  async function apiRequest(url, method, body = null) {
    const isFormData = body instanceof FormData;
    const fetchHeaders = isFormData ? headers() : { ...headers(), 'Content-Type': 'application/json' };
    const fetchBody = body ? (isFormData ? body : JSON.stringify(body)) : undefined;

    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: fetchHeaders,
      body: fetchBody,
    });
    if (!res.ok) {
      let msg = 'Request failed';
      try { const j = await res.json(); msg = j.message || j.error || msg; } catch(e){}
      throw new Error(msg);
    }
    return res.json();
  }

  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k,v]) => {
      if (k === 'class') node.className = v;
      else if (k.startsWith('data-')) node.setAttribute(k, v);
      else if (k === 'html') node.innerHTML = v;
      else node.setAttribute(k, v);
    });
    children.forEach(c => node.appendChild(c));
    return node;
  }

  function setLoading(container, isLoading) {
    if (isLoading) {
      const token = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      container.dataset.loadingToken = token;
      if (!container.querySelector('.koopo-stories__loader')) {
        container.innerHTML = '<div class="koopo-stories__loader"><div class="koopo-stories__spinner"></div></div>';
      }
      container.classList.add('is-loading');
      return token;
    }

    container.classList.remove('is-loading');
    delete container.dataset.loadingToken;
    const loader = container.querySelector('.koopo-stories__loader');
    if (loader) {
      loader.classList.add('is-hiding');
      setTimeout(() => {
        if (loader.parentNode) loader.parentNode.removeChild(loader);
      }, 200);
    }
  }

  function waitForContent(container, token, timeoutMs = 10000) {
    const start = performance.now();
    const tick = () => {
      if (!document.body.contains(container)) return;
      if (token && container.dataset.loadingToken !== token) return;
      const hasItems = container.querySelector('.koopo-stories__bubble, .koopo-stories__archive-card');
      if (hasItems) {
        setLoading(container, false);
        return;
      }
      if (timeoutMs && performance.now() - start > timeoutMs) {
        setLoading(container, false);
        return;
      }
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  function getTrayContent(container) {
    let content = container.querySelector('.koopo-stories__content');
    if (!content) {
      content = el('div', { class: 'koopo-stories__content' });
      container.appendChild(content);
    }
    return content;
  }

  function showToast(message) {
    const toast = el('div', { class: 'koopo-stories__toast' });
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.add('is-hiding'); }, 1600);
    setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 1900);
  }

  function renderSkeletonTray(container, count = 6) {
    const loader = container.querySelector('.koopo-stories__loader');
    if (loader) loader.remove();
    const content = getTrayContent(container);
    content.innerHTML = '';

    const myStoryData = {
      story_id: 0,
      author: {
        id: window.KoopoStories.me,
        name: 'Your Story',
        avatar: window.KoopoStories.meAvatar || ''
      },
      cover_thumb: '',
      has_unseen: false,
      items_count: 0,
      unseen_count: 0,
      privacy: 'friends',
    };

    const myBubble = myStoryBubble(myStoryData, false, container);
    content.appendChild(myBubble);

    for (let i = 0; i < count; i += 1) {
      const bubbleEl = el('div', { class: 'koopo-stories__bubble koopo-stories__bubble--skeleton' });
      const avatar = el('div', { class: 'koopo-stories__avatar' });
      const name = el('div', { class: 'koopo-stories__name' });
      bubbleEl.appendChild(avatar);
      bubbleEl.appendChild(name);
      content.appendChild(bubbleEl);
    }
  }

  const scriptCache = {};
  function loadScriptOnce(src) {
    if (!src) return Promise.reject(new Error('Missing script URL'));
    if (scriptCache[src]) return scriptCache[src];

    scriptCache[src] = new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[data-koopo-src="${src}"]`);
      if (existing) {
        if (existing.dataset.loaded === '1') return resolve();
        existing.addEventListener('load', resolve, { once: true });
        existing.addEventListener('error', () => reject(new Error('Failed to load script')), { once: true });
        return;
      }

      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.dataset.koopoSrc = src;
      s.onload = () => {
        s.dataset.loaded = '1';
        resolve();
      };
      s.onerror = () => reject(new Error('Failed to load script'));
      document.head.appendChild(s);
    });

    return scriptCache[src];
  }

  function ensureModule(name, srcKey) {
    const modules = window.KoopoStoriesModules || {};
    if (modules[name]) return Promise.resolve(modules[name]);
    const src = window.KoopoStories ? window.KoopoStories[srcKey] : '';
    return loadScriptOnce(src).then(() => {
      const loaded = window.KoopoStoriesModules && window.KoopoStoriesModules[name];
      if (!loaded) throw new Error(`Module ${name} failed to load`);
      return loaded;
    });
  }

  function ensureViewer() {
    return ensureModule('viewer', 'viewerSrc');
  }

  function ensureComposer() {
    return ensureModule('composer', 'composerSrc');
  }

  async function openStoryFromTray(storyId, container, listOverride = null) {
    const allStoriesInTray = listOverride || container?._storiesList || [];
    const clickedStoryData = allStoriesInTray.find(st => String(st.story_id) === String(storyId));
    const clickedIndex = allStoriesInTray.findIndex(st => String(st.story_id) === String(storyId));
    const viewer = await ensureViewer();

    if (clickedStoryData && clickedStoryData.story_ids && clickedStoryData.story_ids.length > 1) {
      try {
        const storyPromises = clickedStoryData.story_ids.map(sid => apiGet(`${API_BASE}/${sid}`));
        const authorStories = await Promise.all(storyPromises);

        const combinedStory = {
          story_id: clickedStoryData.story_id,
          story_ids: clickedStoryData.story_ids || [clickedStoryData.story_id],
          author: clickedStoryData.author,
          items: [],
          privacy: clickedStoryData.privacy,
          can_manage: false,
          posted_at_human: '',
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
          if (story.can_manage) combinedStory.can_manage = true;
          if (!combinedStory.posted_at_human && story.posted_at_human) {
            combinedStory.posted_at_human = story.posted_at_human;
          }
        });

        combinedStory.items.sort((a, b) => {
          return new Date(a.created_at) - new Date(b.created_at);
        });

        if (!combinedStory.items || combinedStory.items.length === 0) {
          throw new Error('Story content unavailable.');
        }
        viewer.open(combinedStory, allStoriesInTray, clickedIndex >= 0 ? clickedIndex : 0);
        return true;
      } catch (err) {
        console.error('Failed to load author stories:', err);
        showToast('Story content unavailable.');
      }
    }

    try {
      const story = await apiGet(`${API_BASE}/${storyId}`);
      if (!story.items || story.items.length === 0) {
        throw new Error('Story content unavailable.');
      }
      viewer.open(story, allStoriesInTray, clickedIndex >= 0 ? clickedIndex : 0);
      return true;
    } catch (err) {
      console.error('Failed to load story:', err);
      showToast('Story content unavailable.');
      return false;
    }
  }

  async function loadTray(container) {
    const limit = container.getAttribute('data-limit') || '20';
    const scope = container.getAttribute('data-scope') || 'friends';

    const order = container.getAttribute('data-order') || 'unseen_first';
    const showUploader = (container.getAttribute('data-show-uploader') || '1') === '1';
    const showUnseenBadge = (container.getAttribute('data-show-unseen-badge') || '1') === '1';
    const excludeMe = container.getAttribute('data-exclude-me') || '0';

    // Show skeleton tray immediately
    renderSkeletonTray(container, 6);
    const content = getTrayContent(container);

    try {
      const mineResp = await apiGet(`${API_BASE}?limit=${encodeURIComponent(limit)}&order=${encodeURIComponent(order)}&only_me=1`);
      const myStories = mineResp.stories || [];
      const data = await apiGet(`${API_BASE}?limit=${encodeURIComponent(limit)}&scope=${encodeURIComponent(scope)}&order=${encodeURIComponent(order)}&exclude_me=1`);
      const stories = data.stories || [];
      content.innerHTML = '';

      // "Your story" bubble (current user only)
      const myStoryData = myStories[0] || {
        story_id: 0,
        author: { id: window.KoopoStories.me, name: 'Your Story', avatar: window.KoopoStories.meAvatar || '' },
        cover_thumb: '',
        has_unseen: false,
        items_count: 0,
        unseen_count: 0,
        privacy: 'friends',
      };
      container._myStoriesList = myStories;
      const myBubble = myStoryBubble(myStoryData, showUnseenBadge, container);
      content.appendChild(myBubble);

      // Store stories list on container for later access
      container._storiesList = stories;
      stories.forEach(s => content.appendChild(bubble(s, false, showUnseenBadge)));
      openStoryFromUrl(container);
      // Auto-refresh removed to avoid reloading after viewing stories.
    } catch (err) {
      console.error('Failed to load stories:', err);
      content.innerHTML = '<div style="padding:20px;text-align:center;color:#999;">Failed to load stories</div>';
    }
  }

  function refreshTray(container) {
    if (container.getAttribute('data-archive') === '1') {
      container.dataset.archivePage = '1';
      container.dataset.archiveHasMore = '1';
      container.dataset.archiveLoading = '1';
      return loadArchiveTray(container, { page: 1 }).catch(()=>{});
    }
    return loadTray(container).catch(()=>{});
  }

  function openStoryFromUrl(container) {
    if (container.dataset.koopoStoryOpened === '1') return;
    const params = new URLSearchParams(window.location.search || '');
    const storyId = params.get('koopo_story');
    if (!storyId) return;
    container.dataset.koopoStoryOpened = '1';
    openStoryFromTray(storyId, container);
  }

  async function loadArchiveTray(container, opts = {}) {
    const limit = container.getAttribute('data-limit') || '20';
    const append = opts.append === true;
    const page = opts.page || 1;

    let loadToken = '';
    if (!append) {
      loadToken = setLoading(container, true);
      container._storiesList = [];
    }
    const content = getTrayContent(container);

    try {
      const data = await apiGet(`${API_BASE}/archive?limit=${encodeURIComponent(limit)}&page=${encodeURIComponent(page)}`);
      const stories = data.stories || [];
      const hasMore = !!data.has_more;

      if (!append) {
        content.innerHTML = '';
      }

      if (!append && stories.length === 0) {
        content.innerHTML = `<div style="padding:20px;text-align:center;color:#999;">${t('archive_empty', 'Archive empty')}</div>`;
        container.dataset.archiveHasMore = '0';
        container.dataset.archiveLoading = '0';
        container.dataset.archivePage = '1';
        setLoading(container, false);
        return;
      }

      container._storiesList = (container._storiesList || []).concat(stories);
      stories.forEach(s => content.appendChild(archiveCard(s, container)));

      container.dataset.archiveHasMore = hasMore ? '1' : '0';
      container.dataset.archivePage = String(page);
      if (!append) waitForContent(container, loadToken);
    } catch (err) {
      console.error('Failed to load archived stories:', err);
      if (!append) {
        content.innerHTML = `<div style="padding:20px;text-align:center;color:#999;">${t('archive_load_failed', 'Failed to load archived stories')}</div>`;
        setLoading(container, false);
      }
    } finally {
      container.dataset.archiveLoading = '0';
      if (!append) waitForContent(container, loadToken);
    }
  }

  function archiveCard(s, container) {
    const card = el('div', { class: 'koopo-stories__archive-card', 'data-story-id': String(s.story_id || 0) });
    const cover = el('div', { class: 'koopo-stories__archive-cover' });
    const img = el('img', { src: s.cover_thumb || s.author?.avatar || '' });
    img.loading = 'lazy';
    img.decoding = 'async';
    cover.appendChild(img);

    const meta = el('div', { class: 'koopo-stories__archive-meta' });
    const title = el('div', { class: 'koopo-stories__archive-title' });
    title.textContent = s.author?.name ? `Story by ${s.author.name}` : 'Archived story';
    const date = el('div', { class: 'koopo-stories__archive-date' });
    if (s.created_at) {
      const d = new Date(s.created_at);
      date.textContent = isNaN(d.getTime()) ? '' : d.toLocaleDateString();
    }
    const views = el('div', { class: 'koopo-stories__archive-views' });
    const viewCount = typeof s.view_count === 'number' ? s.view_count : 0;
    views.textContent = `👀 ${viewCount}`;
    meta.appendChild(title);
    meta.appendChild(date);
    meta.appendChild(views);

    card.appendChild(cover);
    card.appendChild(meta);

    card.addEventListener('click', async () => {
      const storyId = s.story_id;
      if (!storyId) return;

      const loadingOverlay = el('div', { class: 'koopo-stories__click-loader with-overlay' });
      const spinner = el('div', { class: 'koopo-stories__spinner' });
      loadingOverlay.appendChild(spinner);
      document.body.appendChild(loadingOverlay);

      try {
        const story = await apiGet(`${API_BASE}/${storyId}`);
        const storiesList = container?._storiesList || [];
        const clickedIndex = storiesList.findIndex(st => String(st.story_id) === String(storyId));
        const viewer = await ensureViewer();
        if (!story.items || story.items.length === 0) {
          showToast('Story content unavailable.');
          return;
        }
        viewer.open(story, storiesList, clickedIndex >= 0 ? clickedIndex : 0);
      } finally {
        loadingOverlay.remove();
      }
    });

    return card;
  }

  function bubble(s, isUploader, showUnseenBadge) {
    const seen = s.has_unseen ? '0' : '1';
    const b = el('div', { class: 'koopo-stories__bubble', 'data-story-id': String(s.story_id || 0), 'data-seen': seen });
    const avatar = el('div', { class: 'koopo-stories__avatar' });
    const ring = el('div', { class: 'koopo-stories__ring' });
    const img = el('img', { src: s.author?.avatar || s.cover_thumb || '' });
    img.loading = 'lazy';
    img.decoding = 'async';
    avatar.appendChild(ring);
    avatar.appendChild(img);

    const name = el('div', { class: 'koopo-stories__name' });
    name.textContent = isUploader ? 'Your Story' : (s.author?.name || 'Story');

    // Show badge with unseen count if enabled
    if (!isUploader && showUnseenBadge && (s.unseen_count || 0) > 0) {
      const badge = el('div', { class: 'koopo-stories__badge' });
      badge.textContent = String(s.unseen_count);
      avatar.appendChild(badge);
    }

    // Privacy indicator for own stories
    if (isUploader === false && s.author?.id === window.KoopoStories.me && s.privacy) {
      const privacyIcon = el('div', { class: 'koopo-stories__privacy-icon' });
      if (s.privacy === 'close_friends') {
        privacyIcon.innerHTML = '&#128274;'; // lock icon
        privacyIcon.title = 'Close Friends';
      } else if (s.privacy === 'friends') {
        privacyIcon.innerHTML = '&#128100;'; // silhouette icon
        privacyIcon.title = 'Friends Only';
      } else if (s.privacy === 'public') {
        privacyIcon.innerHTML = '&#127758;'; // globe icon
        privacyIcon.title = 'Public';
      }
      avatar.appendChild(privacyIcon);
    }

    b.appendChild(avatar);
    b.appendChild(name);

    if (isUploader) {
      b.addEventListener('click', () => {
        ensureComposer()
          .then(mod => mod.uploader())
          .catch(err => console.error('Failed to load composer:', err));
      });
    } else {
      b.addEventListener('click', async () => {
        const storyId = b.getAttribute('data-story-id');
        if (!storyId) return;

        // Show instant loading overlay
        const loadingOverlay = el('div', { class: 'koopo-stories__click-loader with-overlay' });
        const spinner = el('div', { class: 'koopo-stories__spinner' });
        loadingOverlay.appendChild(spinner);
        document.body.appendChild(loadingOverlay);

        try {
          const container = b.closest('.koopo-stories');
          await openStoryFromTray(storyId, container);

          // update ring locally
          b.setAttribute('data-seen','1');
          const badge = b.querySelector('.koopo-stories__badge');
          if (badge) badge.remove();
        } catch (err) {
          console.error('Failed to open story:', err);
          showToast('Story content unavailable.');
        } finally {
          // Remove loading overlay
          loadingOverlay.remove();
        }
      });
    }
    return b;
  }

  function myStoryBubble(s, showUnseenBadge, container) {
    const b = el('div', { class: 'koopo-stories__bubble koopo-stories__bubble--me', 'data-story-id': String(s.story_id || 0), 'data-seen': s.has_unseen ? '0' : '1' });
    const avatar = el('div', { class: 'koopo-stories__avatar' });
    const ring = el('div', { class: 'koopo-stories__ring' });
    const img = el('img', { src: s.author?.avatar || s.cover_thumb || window.KoopoStories.meAvatar || '' });
    img.loading = 'lazy';
    img.decoding = 'async';
    avatar.appendChild(ring);
    avatar.appendChild(img);

    const name = el('div', { class: 'koopo-stories__name' });
    name.textContent = 'Your Story';

    if (showUnseenBadge && (s.unseen_count || 0) > 0) {
      const badge = el('div', { class: 'koopo-stories__badge' });
      badge.textContent = String(s.unseen_count);
      avatar.appendChild(badge);
    }

    const plusBtn = el('button', { class: 'koopo-stories__plus', type: 'button', 'aria-label': 'Add story' });
    plusBtn.textContent = '+';

    b.appendChild(avatar);
    b.appendChild(name);
    b.appendChild(plusBtn);

    plusBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      ensureComposer()
        .then(mod => mod.uploader())
        .catch(err => console.error('Failed to load composer:', err));
    });

    b.addEventListener('click', async () => {
      if (!s.story_id) return;
      const loadingOverlay = el('div', { class: 'koopo-stories__click-loader with-overlay' });
      const spinner = el('div', { class: 'koopo-stories__spinner' });
      loadingOverlay.appendChild(spinner);
      document.body.appendChild(loadingOverlay);
      try {
        await openStoryFromTray(s.story_id, container, container?._myStoriesList || []);
        b.setAttribute('data-seen', '1');
        const badge = b.querySelector('.koopo-stories__badge');
        if (badge) badge.remove();
      } catch (err) {
        console.error('Failed to open story:', err);
        showToast('Story content unavailable.');
      } finally {
        loadingOverlay.remove();
      }
    });

    return b;
  }

  function init() {
    const nodes = document.querySelectorAll('.koopo-stories');
    nodes.forEach(n => refreshTray(n));
    initArchiveInfiniteScroll();
  }

  function initArchiveInfiniteScroll() {
    const onScroll = () => {
      const archives = document.querySelectorAll('.koopo-stories[data-archive="1"]');
      archives.forEach(container => {
        const isLoading = container.dataset.archiveLoading === '1';
        const hasMore = container.dataset.archiveHasMore !== '0';
        if (isLoading || !hasMore) return;

        const rect = container.getBoundingClientRect();
        const nearBottom = rect.bottom - window.innerHeight < 200;
        if (!nearBottom) return;

        const nextPage = parseInt(container.dataset.archivePage || '1', 10) + 1;
        container.dataset.archiveLoading = '1';
        loadArchiveTray(container, { append: true, page: nextPage });
      });
    };

    window.addEventListener('scroll', onScroll, { passive: true });
  }

  window.KoopoStoriesUI = {
    API_BASE,
    NONCE,
    t,
    isMobile,
    apiGet,
    apiPost,
    apiRequest,
    el,
    refreshTray,
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
