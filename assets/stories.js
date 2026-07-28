(() => {
  if (!window.KoopoStories) return;

  const API_BASE = window.KoopoStories.restUrl; // .../koopo/v1/stories
  const NONCE = window.KoopoStories.nonce;
  const i18n = window.KoopoStoriesI18n || {};
  const t = (key, fallback) => (i18n && i18n[key]) ? i18n[key] : fallback;
  const isMobile = (window.matchMedia && window.matchMedia('(max-width: 768px)').matches)
    || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
  let archiveObserver = null;
  let archiveFallbackBound = false;

  function withCompact(url) {
    if (!isMobile) return url;
    try {
      const u = new URL(url, window.location.origin);
      if (!u.searchParams.has('compact')) {
        u.searchParams.set('compact', '1');
      }
      // Always include stickers even in compact mode
      if (!u.searchParams.has('include_stickers')) {
        u.searchParams.set('include_stickers', '1');
      }
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  const headers = () => ({
    'X-WP-Nonce': NONCE,
  });

  function decodeStoryText(value) {
    const textarea = document.createElement('textarea');
    let decoded = String(value || '').replace(/&;amp;?/gi, '&amp;');
    // Some saved display names have been encoded more than once.
    for (let i = 0; i < 2 && /&(?:#\d+|#x[\da-f]+|[a-z]+);/i.test(decoded); i += 1) {
      textarea.innerHTML = decoded;
      const next = textarea.value;
      if (next === decoded) break;
      decoded = next;
    }
    return decoded;
  }

  async function apiGet(url) {
    const res = await fetch(withCompact(url), { credentials: 'same-origin', headers: headers() });
    if (!res.ok) throw new Error('Request failed');
    return res.json();
  }

  async function apiGetFull(url) {
    const res = await fetch(url, { credentials: 'same-origin', headers: headers() });
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
      try { const j = await res.json(); msg = j.message || j.error || msg; } catch (e) { }
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
      try { const j = await res.json(); msg = j.message || j.error || msg; } catch (e) { }
      throw new Error(msg);
    }
    return res.json();
  }

  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
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

  function getArchiveSentinel(container) {
    let sentinel = container.querySelector('.koopo-stories__archive-sentinel');
    if (!sentinel) {
      sentinel = el('div', {
        class: 'koopo-stories__archive-sentinel',
        'aria-hidden': 'true',
      });
      sentinel.style.cssText = 'display:block;width:100%;height:1px;';
      container.appendChild(sentinel);
    }
    return sentinel;
  }

  function loadNextArchivePage(container) {
    if (!container || container.getAttribute('data-archive') !== '1') return;
    const isLoading = container.dataset.archiveLoading === '1';
    const hasMore = container.dataset.archiveHasMore !== '0';
    if (isLoading || !hasMore) return;

    const nextPage = parseInt(container.dataset.archivePage || '1', 10) + 1;
    container.dataset.archiveLoading = '1';
    syncArchiveInfiniteState(container);
    loadArchiveTray(container, { append: true, page: nextPage });
  }

  function syncArchiveInfiniteState(container) {
    if (!container || container.getAttribute('data-archive') !== '1') return;
    const sentinel = getArchiveSentinel(container);
    const isLoading = container.dataset.archiveLoading === '1';
    const hasMore = container.dataset.archiveHasMore !== '0';

    sentinel.hidden = !hasMore;
    if (archiveObserver) {
      archiveObserver.unobserve(sentinel);
      if (hasMore && !isLoading) {
        archiveObserver.observe(sentinel);
      }
    }
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

  const storyCache = new Map();
  function fetchStoryCached(storyId) {
    const key = String(storyId || '');
    if (!key) return Promise.reject(new Error('Missing story id'));
    const cached = storyCache.get(key);
    if (cached) {
      return cached instanceof Promise ? cached : Promise.resolve(cached);
    }
    const promise = apiGet(`${API_BASE}/${key}`)
      .then((data) => {
        storyCache.set(key, data);
        return data;
      })
      .catch((err) => {
        storyCache.delete(key);
        throw err;
      });
    storyCache.set(key, promise);
    return promise;
  }

  function fetchStoryFull(storyId) {
    const key = `full_${String(storyId || '')}`;
    if (!storyId) return Promise.reject(new Error('Missing story id'));
    const cached = storyCache.get(key);
    if (cached) return cached instanceof Promise ? cached : Promise.resolve(cached);
    const url = `${API_BASE}/${storyId}?compact=0&include_stickers=1`;
    const promise = apiGetFull(url)
      .then((data) => {
        storyCache.set(key, data);
        return data;
      })
      .catch((err) => {
        storyCache.delete(key);
        throw err;
      });
    storyCache.set(key, promise);
    return promise;
  }

  function prefetchStoryData(storyData) {
    if (!storyData) return;
    if (Array.isArray(storyData.story_ids) && storyData.story_ids.length > 1) {
      storyData.story_ids.forEach((sid) => fetchStoryCached(sid).catch(() => { }));
    } else if (storyData.story_id) {
      fetchStoryCached(storyData.story_id).catch(() => { });
    }
  }

  function prefetchAdjacentStories(list, index) {
    if (!Array.isArray(list) || list.length === 0) return;
    const next = list[index + 1];
    const prev = list[index - 1];
    if (next) prefetchStoryData(next);
    if (prev) prefetchStoryData(prev);
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
    const startUnseenItemId = (clickedStoryData && clickedStoryData.has_unseen && clickedStoryData.first_unseen_item_id)
      ? clickedStoryData.first_unseen_item_id
      : null;
    const viewer = await ensureViewer();

    if (clickedStoryData && clickedStoryData.story_ids && clickedStoryData.story_ids.length > 1) {
      try {
        const storyPromises = clickedStoryData.story_ids.map(sid => fetchStoryCached(sid));
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
        viewer.open(
          combinedStory,
          allStoriesInTray,
          clickedIndex >= 0 ? clickedIndex : 0,
          false,
          startUnseenItemId || null
        );
        prefetchAdjacentStories(allStoriesInTray, clickedIndex);
        return true;
      } catch (err) {
        console.error('Failed to load author stories:', err);
        showToast('Story content unavailable.');
      }
    }

    try {
      const story = await fetchStoryCached(storyId);
      if (!story.items || story.items.length === 0) {
        throw new Error('Story content unavailable.');
      }
      viewer.open(
        story,
        allStoriesInTray,
        clickedIndex >= 0 ? clickedIndex : 0,
        false,
        startUnseenItemId || null
      );
      prefetchAdjacentStories(allStoriesInTray, clickedIndex);
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
      syncArchiveInfiniteState(container);
      return loadArchiveTray(container, { page: 1 }).catch(() => { });
    }
    return loadTray(container).catch(() => { });
  }

  let openedFromUrl = false;

  async function openStoryFromUrl(container) {
    if (openedFromUrl || container.dataset.koopoStoryOpened === '1') return;
    const params = new URLSearchParams(window.location.search || '');
    const storyId = params.get('koopo_story');
    if (!storyId) return;
    openedFromUrl = true;
    container.dataset.koopoStoryOpened = '1';
    try {
      const viewer = await ensureViewer();
      if (viewer.openLoading) viewer.openLoading();
      const story = await fetchStoryFull(storyId);
      if (!story.items || story.items.length === 0) throw new Error('Story content unavailable.');
      viewer.open(story, [], 0, false, story.story_id || storyId);
    } catch (err) {
      console.error('Failed to open story from URL:', err);
      showToast('Story content unavailable.');
    }
  }

  async function openStoryFromQuery() {
    if (openedFromUrl) return;
    const params = new URLSearchParams(window.location.search || '');
    const storyId = params.get('koopo_story');
    if (!storyId) return;
    openedFromUrl = true;
    try {
      const viewer = await ensureViewer();
      if (viewer.openLoading) viewer.openLoading();
      const story = await fetchStoryFull(storyId);
      if (!story.items || story.items.length === 0) throw new Error('Story content unavailable.');
      viewer.open(story, [], 0, false, story.story_id || storyId);
    } catch (err) {
      console.error('Failed to open story from URL:', err);
      showToast('Story content unavailable.');
    }
  }

  async function loadArchiveTray(container, opts = {}) {
    const limit = container.getAttribute('data-limit') || '20';
    const append = opts.append === true;
    const page = opts.page || 1;

    let loadToken = '';
    if (!append) {
      loadToken = setLoading(container, true);
      container._storiesList = [];
      delete container.dataset.archiveGroup;
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
        syncArchiveInfiniteState(container);
        setLoading(container, false);
        return;
      }

      container._storiesList = (container._storiesList || []).concat(stories);
      stories.forEach(s => {
        const group = archiveGroupForDate(s.created_at);
        if (group && container.dataset.archiveGroup !== group.key) {
          container.dataset.archiveGroup = group.key;
          const header = el('div', { class: 'koopo-stories__archive-group' });
          header.textContent = group.label;
          content.appendChild(header);
        }
        content.appendChild(archiveCard(s, container));
      });

      container.dataset.archiveHasMore = hasMore ? '1' : '0';
      container.dataset.archivePage = String(page);
      syncArchiveInfiniteState(container);
      if (!append) waitForContent(container, loadToken);
    } catch (err) {
      console.error('Failed to load archived stories:', err);
      if (!append) {
        content.innerHTML = `<div style="padding:20px;text-align:center;color:#999;">${t('archive_load_failed', 'Failed to load archived stories')}</div>`;
        setLoading(container, false);
      }
    } finally {
      container.dataset.archiveLoading = '0';
      syncArchiveInfiniteState(container);
      if (!append) waitForContent(container, loadToken);
    }
  }

  function archiveGroupForDate(dateStr) {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return null;
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    const label = d.toLocaleString(undefined, { month: 'long', year: 'numeric' });
    return { key, label };
  }

  function archiveCard(s, container) {
    const card = el('div', {
      class: 'koopo-stories__archive-card',
      'data-story-id': String(s.story_id || 0),
      'data-item-id': String(s.item_id || 0),
    });
    const cover = el('div', { class: 'koopo-stories__archive-cover' });
    const itemType = s.item_type || 'image';
    const thumbSrc = s.cover_thumb || '';
    const mediaSrc = s.item_src || s.cover_thumb || '';
    if (itemType === 'video') {
      const video = el('video', {
        src: mediaSrc,
        poster: thumbSrc || '',
        muted: true,
        playsinline: true,
        preload: 'metadata',
      });
      cover.appendChild(video);
    } else {
      const isGif = typeof mediaSrc === 'string' && mediaSrc.toLowerCase().includes('.gif');
      const img = el('img', { src: (isGif ? mediaSrc : (thumbSrc || mediaSrc)) || s.author?.avatar || '' });
      img.loading = 'lazy';
      img.decoding = 'async';
      cover.appendChild(img);
    }

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
    views.innerHTML = `<span class="dashicons dashicons-visibility"></span> ${viewCount}`;
    meta.appendChild(title);
    meta.appendChild(date);
    meta.appendChild(views);

    card.appendChild(cover);
    card.appendChild(meta);

    card.addEventListener('click', async () => {
      const storyId = s.story_id;
      const itemId = s.item_id;
      if (!storyId) return;

      const loadingOverlay = el('div', { class: 'koopo-stories__click-loader with-overlay' });
      const spinner = el('div', { class: 'koopo-stories__spinner' });
      loadingOverlay.appendChild(spinner);
      document.body.appendChild(loadingOverlay);

      try {
        const story = await apiGet(`${API_BASE}/${storyId}`);
        const storiesList = container?._storiesList || [];
        const clickedIndex = storiesList.findIndex(st => {
          if (itemId && st.item_id) return String(st.item_id) === String(itemId);
          return String(st.story_id) === String(storyId);
        });
        const viewer = await ensureViewer();
        if (!story.items || story.items.length === 0) {
          showToast('Story content unavailable.');
          return;
        }
        viewer.open(story, storiesList, clickedIndex >= 0 ? clickedIndex : 0, false, itemId || storyId);
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
    name.textContent = isUploader ? 'Your Story' : decodeStoryText(s.author?.name || 'Story');

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
      b.addEventListener('pointerenter', () => prefetchStoryData(s), { passive: true });
      b.addEventListener('touchstart', () => prefetchStoryData(s), { passive: true });
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
          b.setAttribute('data-seen', '1');
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
      const list = [s].concat((container && Array.isArray(container._storiesList)) ? container._storiesList : []);
      const loadingOverlay = el('div', { class: 'koopo-stories__click-loader with-overlay' });
      const spinner = el('div', { class: 'koopo-stories__spinner' });
      loadingOverlay.appendChild(spinner);
      document.body.appendChild(loadingOverlay);
      try {
        await openStoryFromTray(s.story_id, container, list);
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
    nodes.forEach(n => {
      openStoryFromUrl(n);
      refreshTray(n);
    });
    if (nodes.length === 0) {
      openStoryFromQuery();
    }
    // Intercept story links in activity cards to open viewer without redirect.
    document.addEventListener('click', async (e) => {
      const link = e.target.closest('a.koopo-story-open, a[href*="koopo_story="]');
      if (!link) return;
      const params = new URLSearchParams((link.href && link.href.split('?')[1]) || '');
      const storyId = link.dataset.koopoStory || params.get('koopo_story');
      const itemId = link.dataset.koopoItem || params.get('koopo_story_item');
      if (!storyId) return;
      e.preventDefault();
      try {
        const viewer = await ensureViewer();
        if (viewer.openLoading) viewer.openLoading();
        const story = await fetchStoryFull(storyId);
        if (!story.items || story.items.length === 0) throw new Error('Story content unavailable.');
        const stub = {
          story_id: story.story_id || parseInt(storyId, 10),
          story_ids: story.story_ids || [story.story_id || parseInt(storyId, 10)],
          author: story.author,
          cover_thumb: story.items?.[0]?.thumb || story.items?.[0]?.src || '',
          has_unseen: false,
          items_count: story.items?.length || 0,
          unseen_count: 0,
          privacy: story.privacy || 'friends',
        };
        viewer.open(story, [stub], 0, false, itemId || story.story_id || storyId);
      } catch (err) {
        console.error('Failed to open story from link:', err);
        showToast('Story content unavailable.');
      }
    });
    initArchiveInfiniteScroll();
  }

  function initArchiveInfiniteScroll() {
    const archives = document.querySelectorAll('.koopo-stories[data-archive="1"]');
    if (archives.length === 0) return;

    if ('IntersectionObserver' in window) {
      if (!archiveObserver) {
        archiveObserver = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const container = entry.target.closest('.koopo-stories[data-archive="1"]');
            if (!container) return;
            loadNextArchivePage(container);
          });
        }, {
          root: null,
          rootMargin: '300px 0px',
          threshold: 0,
        });
      }

      archives.forEach(container => syncArchiveInfiniteState(container));
      return;
    }

    if (archiveFallbackBound) return;
    const onScroll = () => {
      const nodes = document.querySelectorAll('.koopo-stories[data-archive="1"]');
      nodes.forEach(container => {
        const rect = container.getBoundingClientRect();
        const nearBottom = rect.bottom - window.innerHeight < 200;
        if (!nearBottom) return;
        loadNextArchivePage(container);
      });
    };

    archiveFallbackBound = true;
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    onScroll();
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
    ensureComposer,
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
