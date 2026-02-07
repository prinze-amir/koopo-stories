(() => {
  if (!window.KoopoCloseFriends) return;

  const API_BASE = window.KoopoCloseFriends.restUrl;
  const HIDE_ALL_BASE = window.KoopoCloseFriends.hideAllUrl;
  const SEARCH_URL = window.KoopoCloseFriends.searchUrl;
  const NONCE = window.KoopoCloseFriends.nonce;

  const headers = () => ({
    'X-WP-Nonce': NONCE,
  });

  async function addFriend(friendId) {
    const res = await fetch(`${API_BASE}/${friendId}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers(),
    });

    if (!res.ok) {
      throw new Error('Failed to add friend');
    }

    return res.json();
  }

  async function removeFriend(friendId) {
    const res = await fetch(`${API_BASE}/${friendId}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: headers(),
    });

    if (!res.ok) {
      throw new Error('Failed to remove friend');
    }

    return res.json();
  }

  async function fetchHideAll() {
    const res = await fetch(`${HIDE_ALL_BASE}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: headers(),
    });
    if (!res.ok) throw new Error('Failed to load hidden users');
    return res.json();
  }

  async function addHideAll(userId) {
    const res = await fetch(`${HIDE_ALL_BASE}/${userId}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers(),
    });
    if (!res.ok) throw new Error('Failed to hide user');
    return res.json();
  }

  async function removeHideAll(userId) {
    const res = await fetch(`${HIDE_ALL_BASE}/${userId}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: headers(),
    });
    if (!res.ok) throw new Error('Failed to unhide user');
    return res.json();
  }

  async function searchUsers(query) {
    const url = `${SEARCH_URL}?query=${encodeURIComponent(query)}`;
    const res = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: headers(),
    });
    if (!res.ok) throw new Error('Search failed');
    return res.json();
  }

  function renderHideAllList(container, users) {
    const list = container.querySelector('.koopo-hide-all-list');
    list.innerHTML = '';
    if (!users.length) {
      const empty = document.createElement('div');
      empty.textContent = 'No hidden users yet.';
      list.appendChild(empty);
      return;
    }
    users.forEach((u) => {
      const row = document.createElement('div');
      row.className = 'koopo-hide-all-row';
      const avatar = document.createElement('img');
      avatar.src = u.avatar || '';
      avatar.alt = u.name || u.username || '';
      avatar.className = 'koopo-hide-all-avatar';
      const name = document.createElement('div');
      name.className = 'koopo-hide-all-name';
      name.textContent = u.name || u.username || 'User';
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'koopo-hide-all-remove';
      removeBtn.textContent = 'Remove';
      removeBtn.addEventListener('click', async () => {
        removeBtn.disabled = true;
        try {
          await removeHideAll(u.id);
          const data = await fetchHideAll();
          renderHideAllList(container, data.users || []);
        } catch (err) {
          alert('Failed to remove user.');
        } finally {
          removeBtn.disabled = false;
        }
      });
      row.appendChild(avatar);
      row.appendChild(name);
      row.appendChild(removeBtn);
      list.appendChild(row);
    });
  }

  async function initHideAllManagers() {
    const managers = document.querySelectorAll('.koopo-hide-all-manager');
    if (!managers.length) return;

    for (const container of managers) {
      const input = container.querySelector('.koopo-hide-all-input');
      const dropdown = container.querySelector('.koopo-hide-all-dropdown');
      const addBtn = container.querySelector('.koopo-hide-all-add');

      const updateList = async () => {
        try {
          const data = await fetchHideAll();
          renderHideAllList(container, data.users || []);
        } catch (err) {
          // ignore
        }
      };

      input.addEventListener('input', async () => {
        const query = input.value.trim();
        if (query.length < 2) {
          dropdown.style.display = 'none';
          dropdown.innerHTML = '';
          return;
        }
        try {
          const data = await searchUsers(query);
          const users = data.users || [];
          dropdown.innerHTML = '';
          if (!users.length) {
            const empty = document.createElement('div');
            empty.className = 'koopo-hide-all-empty';
            empty.textContent = 'No users found';
            dropdown.appendChild(empty);
          } else {
            users.forEach((u) => {
              const row = document.createElement('div');
              row.className = 'koopo-hide-all-suggestion';
              row.textContent = u.username ? `@${u.username}` : (u.name || 'User');
              row.addEventListener('click', () => {
                input.value = u.username || '';
                input.dataset.selectedUserId = String(u.id || '');
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
              });
              dropdown.appendChild(row);
            });
          }
          dropdown.style.display = 'block';
        } catch (err) {
          dropdown.style.display = 'none';
        }
      });

      addBtn.addEventListener('click', async () => {
        const selected = parseInt(input.dataset.selectedUserId || '0', 10);
        if (!selected) return;
        addBtn.disabled = true;
        try {
          await addHideAll(selected);
          input.value = '';
          input.dataset.selectedUserId = '';
          dropdown.style.display = 'none';
          dropdown.innerHTML = '';
          await updateList();
        } catch (err) {
          alert('Failed to hide user.');
        } finally {
          addBtn.disabled = false;
        }
      });

      await updateList();
    }
  }

  // Handle toggle button clicks
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.koopo-close-friend-toggle');
    if (!btn) return;

    e.preventDefault();

    if (btn.disabled || btn.classList.contains('is-loading')) return;

    const friendId = btn.getAttribute('data-friend-id');
    const action = btn.getAttribute('data-action');

    if (!friendId || !action) return;

    // Set loading state
    btn.classList.add('is-loading');
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = action === 'add' ? 'Adding...' : 'Removing...';

    try {
      if (action === 'add') {
        await addFriend(friendId);
        // Update UI to reflect added state
        btn.classList.add('is-close');
        btn.setAttribute('data-action', 'remove');
        btn.textContent = 'Remove';

        // Update count
        updateCount(1);
      } else {
        await removeFriend(friendId);
        // Update UI to reflect removed state
        btn.classList.remove('is-close');
        btn.setAttribute('data-action', 'add');
        btn.textContent = 'Add';

        // Update count
        updateCount(-1);
      }
    } catch (err) {
      console.error('Close friends error:', err);
      alert('Failed to update close friends list. Please try again.');
      btn.textContent = originalText;
    } finally {
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }
  });

  function updateCount(delta) {
    const countEl = document.querySelector('.koopo-close-friends-count strong');
    if (countEl) {
      const current = parseInt(countEl.textContent) || 0;
      countEl.textContent = Math.max(0, current + delta);
    }
  }

  initHideAllManagers();
})();
