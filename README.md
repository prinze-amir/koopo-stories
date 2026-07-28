## Koopo Stories: 17-Phase Development Roadmap

Here is the **official project roadmap** for the Stories feature, aligned to commits and enhanced with social engagement features.

---

## 📊 Current Status (Verified March 1, 2026)

| Phase | Status | Completion |
|-------|--------|------------|
| **Phase 0-13** | ✅ Complete | 100% |
| **Phase 14** | ✅ Mostly complete | 75% |
| **Phase 15** | ✅ Mostly complete | 75% |
| **Phase 16** | 🔄 In progress | 55% |
| **Phase 17** | 🔄 In progress | 45% |

**Overall Progress:** ~82% (core product complete, release hardening in progress)

**Latest Verified Baseline:** local codebase audit on March 1, 2026

**Next Up:** Final E2E QA on running WordPress/BuddyBoss, then Phase 16-17 hardening closure

---

### **Phase 0 – Foundation & Stability** ✅ **COMPLETE**

1. Plugin bootstrap stability
2. Admin menu architecture
3. Widget rendering correctness
4. Load order safety
5. No stray output / fatals

👉 **Commits:** 001–003
✅ Almost complete

---

### **Phase 1 – Stories Core Data Model** ✅ **COMPLETE**

6. Story CPT (`koopo_story`)
7. Story Item CPT (`koopo_story_item`)
8. Meta schema + expiration model
9. Ownership + permissions rules

👉 **Commits:** 001–003
✅ All CPTs, meta, and permissions implemented

---

### **Phase 2 – Seen / View Tracking** ✅ **COMPLETE**

10. Custom DB table (`story_views`)
11. Insert + lookup logic
12. Unseen detection logic

👉 **Commits:** 001–003
✅ Custom table with view tracking complete

---

### **Phase 3 – REST API (API-First)** ✅ **COMPLETE**

13. Feed endpoint
14. Story detail endpoint
15. Seen endpoint
16. Upload endpoint
17. Permission callbacks

👉 **Commits:** 001–003
✅ All 4 REST endpoints implemented

---

### **Phase 4 – Web UI: Stories Tray** ✅ **COMPLETE**

18. Tray renderer
19. Unseen ring logic
20. Ordering (unseen / recent)
21. Widget + shortcode integration

👉 **Commits:** 001–003
✅ Tray, widget, and shortcode complete

---

### **Phase 5 – Web UI: Viewer** ✅ **COMPLETE**

22. Fullscreen modal
23. Progress bars
24. Tap / swipe navigation
25. Autoplay rules

👉 **Commits:** 001–003
✅ Fullscreen viewer with navigation complete

---

### **Phase 6 – Uploader UX** ✅ **COMPLETE**

26. Preview before upload
27. Rate limiting
28. Validation
29. Error handling

👉 **Commits:** 001–003
✅ Upload composer with preview complete

---

### **Phase 7 – Cleanup & Expiration** ✅ **COMPLETE**

30. Cron cleanup
31. Manual admin cleanup
32. Orphan detection

👉 **Commits:** 001–003
✅ Automated cron cleanup implemented

---

### **Phase 8 – Widget Enhancements** ✅ **COMPLETE**

33. Layout modes
34. Per-widget overrides
35. Sidebar UX polish

👉 **Commits:** 001–003
✅ Horizontal/vertical layouts complete

---

### **Phase 9 – User Privacy & Granular Controls** ✅ **COMPLETE**

36. Per-story privacy settings (public, friends only, close friends) ✅
37. Story archive (save stories beyond 24h for logged-in user) ✅
38. Hide story from specific users ✅
39. Close friends list integration ✅

👉 **Commit:** 005
✅ Privacy controls implemented
- Privacy selector in upload UI (3 levels: public, friends, close friends)
- Database-backed close friends lists
- Privacy-aware permissions system
- REST API for close friends management
- User-facing close friends manager UI (shortcode)
- Privacy indicators on story bubbles

---

### **Phase 10 – Engagement: Reactions & Replies** ✅ **COMPLETE**

40. Like/reaction system (emoji picker) ✅
41. Story replies (DM or comment system) ✅
42. Reaction counts display ✅
43. Reply notifications (BuddyBoss notifications integration) ✅

👉 **Commits:** 005, 006
✅ Full engagement implementation (backend + frontend)
- Reactions database with 7 emoji types
- Replies database with DM/public modes
- 6 REST API endpoints for reactions & replies
- BuddyBoss notifications integration
- Privacy-aware reply visibility
- **Frontend UI**: Emoji picker modal, reply textarea modal
- **UX**: Reaction and reply buttons in story viewer
- Buttons hidden for own stories

---

### **Phase 11 – Interactive Features** ✅ **COMPLETE**

44. ✅ Stickers database with position tracking
45. ✅ Mention sticker (@username validation)
46. ✅ Link sticker (URL + title)
47. ✅ Location sticker (name, coordinates, address)
48. ✅ Poll sticker (question + up to 4 options)
49. ✅ Poll voting system with real-time counts
50. ✅ REST API endpoints for sticker CRUD
51. ✅ Sticker UI in story composer
52. ✅ Sticker display in story viewer
53. ✅ @mention autocomplete with BuddyBoss integration

👉 **Commits:** 008-009
✅ **Full implementation complete**

**Backend:**
- `koopo_story_stickers` table with position tracking
- `koopo_story_poll_votes` table for voting
- Support for 4 sticker types: mention, link, location, poll
- REST endpoints: add sticker, delete sticker, vote on poll
- Type-specific validation and sanitization

**Frontend:**
- Sticker toolbar in story composer with 4 sticker buttons
- Modal forms for adding each sticker type
- Draggable sticker positioning in composer preview
- Real-time sticker rendering in story viewer
- Interactive poll voting with live vote counts
- @mention autocomplete with avatar suggestions from BuddyBoss
- Click handlers: profile links for mentions, external links, Google Maps for locations

---

### **Phase 12 – Analytics & Insights** ✅ **COMPLETE**

48. View counts per story ✅
49. Viewer list ("Seen by" feature) ✅
50. Per-user "seen" state tracking ✅
51. Story insights dashboard (who viewed, when) ✅

👉 **Commit:** 006
✅ Full analytics implementation
- View counts integrated in get_story endpoint
- `/stories/{id}/viewers` REST endpoint for viewer list
- `/stories/{id}/analytics` REST endpoint for comprehensive insights
- Viewer list UI modal with avatars and timestamps
- View count badge in story viewer (author-only)
- Analytics include views, reactions, and replies
- Privacy-aware (only story author can see analytics)

---

### **Phase 13 – Moderation** ✅ **COMPLETE**

52. Reporting ✅
53. Admin review dashboard ✅
54. Auto-hide thresholds ✅
55. Flagged content queue ✅

👉 **Commit:** 007
✅ Full moderation system implemented
- Reports database with status tracking
- User reporting UI with 7 report reasons
- ⚠ Report button in story viewer
- REST API for reporting and moderation
✅ Admin moderation dashboard with stats
✅ Auto-hide stories after threshold (configurable, default: 5)
- Dismiss or delete reported stories
- Audit trail with reviewer tracking
- Can't report own stories
- One report per user per story

---

### **Phase 14 – Performance** ✅ **MOSTLY COMPLETE**

56. Caching (transients for feeds) ✅  
57. Query optimization ✅  
58. Lazy loading for media ✅  
59. CDN integration for attachments ⏸️ (infra/environment dependent)

**Status notes:**
- Feed and archive use transient caching.
- Feed query path uses preloaded item IDs and batched unseen lookups.
- Frontend tray/archive thumbnails use lazy loading.

---

### **Phase 15 – React Native Readiness** ✅ **MOSTLY COMPLETE**

60. Auth abstraction 🔄 (WordPress session/nonce model in place; app token abstraction not formalized here)  
61. Mobile-friendly payloads ✅  
62. API versioning ✅  
63. Push notification hooks ✅

**Status notes:**
- `compact=1` / `mobile=1` response shaping is implemented for feed/story/archive.
- `api_version` is included in API responses.
- Story/reaction/reply hooks are exposed for integrations.

---

### **Phase 16 – Final Polish** 🔄 **IN PROGRESS**

64. Accessibility (ARIA labels, keyboard nav) 🔄  
65. Animations & transitions ✅  
66. Edge cases & error handling 🔄  
67. Internationalization (i18n) 🔄

**Status notes:**
- Core UX animations and transitions are present.
- Accessibility and i18n coverage exist but are not fully comprehensive across all interaction paths.

---

### **Phase 17 – Hardening & Release** 🔄 **IN PROGRESS**

68. Security review 🔄  
69. Back-compat testing 🔄  
70. Release notes ✅  
71. Documentation 🔄

**Progress:** release notes are present, but QA/back-compat closure and doc synchronization are still pending.

---

## 🧾 Release Notes (v1.0 candidate)

- Privacy controls: public/friends/close friends with per-story hide list.
- Close friends UI + REST management.
- Reactions, replies, reporting, and moderation queue.
- Stickers: mentions, links, locations, polls + voting.
- Story analytics: views, viewers list, reaction counts.
- Story settings: privacy edit, delete, archive/unarchive.
- Archive tray + infinite scroll.
- Mobile optimizations: compact payloads + lazy loaded thumbnails.
- Security: upload validation, rate limits, and visibility checks.

---

## 📚 Documentation Notes

- Shortcodes:
  - `[koopo_stories_widget]` for trays (friends/following/all).
  - `[koopo_stories_archive]` for archived stories.
  - `[koopo_close_friends_manager]` for close friends UI.
- REST:
  - Feed: `/wp-json/koopo/v1/stories`
  - Story: `/wp-json/koopo/v1/stories/{id}`
  - Archive: `/wp-json/koopo/v1/stories/archive`
  - Add sticker: `POST /wp-json/koopo/v1/stories/{story_id}/items/{item_id}/stickers`
  - Sticker providers config (mobile/web): `GET /wp-json/koopo/v1/stories/stickers/providers`
  - `compact=1` for mobile payloads
- Admin tools:
  - Back-compat tools in Stories Settings (privacy migration + orphan cleanup).
  - Rate-limit settings (reactions/replies/reports).

---

## ✅ Full Release Checklist

**Security & Permissions**
- Verify REST endpoints enforce `must_be_logged_in` or `can_moderate` as appropriate.
- Confirm story visibility checks for reactions, replies, reports, poll votes.
- Validate upload limits + allowed MIME types in production.

**Back-Compat**
- Run privacy migration (`connections` → `friends`) if legacy data exists.
- Run orphan cleanup for story items with missing attachments.
- Verify legacy stories load without `media_type` set.

**Performance**
- Confirm feed cache invalidation on create/update/delete/hide/seen.
- Validate query load on feed (no per-story item queries).
- Confirm lazy loading works for tray + archive thumbs.

**UX / QA**
- Story viewer navigation (tap/hold, next/prev, skip users).
- Reactions and replies on desktop + mobile.
- Sticker drag on mobile and sticker render in viewer.
- Archive tray infinite scroll and empty state.
- Story settings (privacy edit, hide list, archive, delete).

**Moderation**
- Reporting UI submits + moderation queue actions work.
- Auto-hide threshold behaves correctly.

**Release Prep**
- Bump plugin version (`koopo-stories.php`) if needed.
- Rebuild/minify assets if your build pipeline requires it.
- Update any environment-specific settings.

---

## Process change (important)

Starting **Commit 004**, every commit will include:

* ✅ **Phase number(s)** in the commit notes
* ✅ **Which checklist items were completed**
* ✅ **Which phase is next**

Example:
```
Commit 004: Phase 0 enhancements
- Added BuddyBoss profile URL linking
- Fixed current user avatar display
Phase 0 complete, moving to Phase 9
```

---

## 📋 Feature Comparison: Planned vs. Industry Standard

| Feature | Instagram | Facebook | Koopo Stories (Planned) |
|---------|-----------|----------|-------------------------|
| **Core Features** |
| 24h auto-expire | ✅ | ✅ | ✅ Complete |
| Image/Video upload | ✅ | ✅ | ✅ Complete |
| Fullscreen viewer | ✅ | ✅ | ✅ Complete |
| Progress bars | ✅ | ✅ | ✅ Complete |
| **Privacy** |
| Public/Friends toggle | ✅ | ✅ | ✅ Complete |
| Close friends list | ✅ | ✅ | ✅ Complete |
| Hide from specific users | ✅ | ✅ | ✅ Complete |
| Story archive | ✅ | ✅ | ✅ Complete (owner archive + archive endpoint) |
| **Engagement** |
| Reactions/Likes | ✅ | ✅ | ✅ Complete |
| DM replies | ✅ | ✅ | ✅ Complete |
| View counts | ✅ | ✅ | ✅ Complete |
| Viewer list | ✅ | ✅ | ✅ Complete |
| **Interactive** |
| Mentions | ✅ | ✅ | ✅ Complete |
| Link stickers | ✅ | ✅ | ✅ Complete |
| Location tags | ✅ | ✅ | ✅ Complete |
| Polls | ✅ | ✅ | ✅ Complete|
| **Platform** |
| Web support | ✅ | ✅ | ✅ Complete |
| Mobile app API | ✅ | ✅ | 🔄 Mostly ready (final contract QA pending) |
| Push notifications | ✅ | ✅ | ✅ Hooks available |

---

## 🎯 Development Priorities

### **Immediate (Current Sprint)**
1. Run full browser + API E2E validation once the local WordPress/BuddyBoss stack is running.
2. Validate mobile-app posting contract against stories feed/detail/seen paths.
3. Regression-test story-to-activity media quality (image and video cards).

### **Short-term**
4. Finish Phase 16 accessibility and i18n coverage.
5. Complete Phase 17 back-compat and security closure checklist.
6. Finalize release documentation to match verified implementation state.

---

## 📝 Notes

- As of March 1, 2026, core user-facing product goals are functionally complete; remaining work is concentrated in hardening, QA depth, and release consistency.
- Engagement features (Phase 10) should come before analytics
- Performance optimization (Phase 14) can run parallel with feature development
- All phases maintain backward compatibility with existing stories

---

## 📡 API Notes (Phase 15)

- `api_version`: All feed/story/archive responses include `api_version` (current: `1.1`).
- `compact=1` or `mobile=1`: Optional query param to return a lighter payload.
  - Feed: omits `author.profile_url`.
  - Story detail: omits `author.profile_url`, `analytics.reactions`, and item `thumb`.
  - Archive: omits `author.profile_url`.
- Stickers API (mobile-ready):
  - Add sticker: `POST /wp-json/koopo/v1/stories/{story_id}/items/{item_id}/stickers`
  - Delete sticker: `DELETE /wp-json/koopo/v1/stickers/{sticker_id}`
  - Poll vote: `POST /wp-json/koopo/v1/stickers/{sticker_id}/vote`
  - Providers config: `GET /wp-json/koopo/v1/stories/stickers/providers`
  - Accepted sticker `type` values for add endpoint include `mention`, `link`, `location`, `poll`, `text`, `media`, plus mobile aliases `gif`, `giphy`, `tenor`, `lottie` (aliased to `media`).
- Push notification hooks (for external integrations):
  - `koopo_stories_story_created` (story_id, item_id, user_id)
  - `koopo_stories_reaction_added` (story_id, user_id, reaction, item_id)
  - `koopo_stories_reply_added` (story_id, user_id, reply_id, item_id, is_dm)

---

## ⚡ Performance / Structure Improvements (Recommended)

### Findings (Ordered by Severity)

- **High**: Duplicate per‑request logic (upload limits, duration, max items) is copy‑pasted across endpoints. Extract shared helpers to avoid divergence. `includes/stories/rest/class-stories-rest.php`
- **High**: Feed building still does per‑story meta calls (privacy, expires, cover thumb, author profile URL). Batch meta/authors or reduce default payload. `includes/stories/rest/class-stories-rest.php`
- **Medium**: Frontend logic is monolithic and mixes UI/data/interaction. Split into viewer/composer/settings/archive modules. `assets/stories.js`
- **Medium**: CSS is monolithic with leftover rules; split by component or at least regroup sections. `assets/stories.css`
- **Low**: Cache key doesn’t include all potential query params; use a centralized key builder. `includes/stories/rest/class-stories-rest.php`
- **Low**: `compact=1` applied to all GET requests on mobile; consider per‑call opt‑in for future endpoints. `assets/stories.js`

### Performance Improvements (Ideas)

- Batch story meta and author lookups in feed.
- Keep tray payload minimal; fetch full item details only on viewer open.
- Use `fields => 'ids'` where possible for leaner queries.
- Cache unseen counts per user/story group with short TTL.
- Load stickers lazily or via a dedicated endpoint for heavy stories.

### Refactoring / Structure Suggestions

- **Split REST controllers**:
  - `class-stories-rest-feed.php` (feed + archive)
  - `class-stories-rest-story.php` (get/update/delete/create)
  - `class-stories-rest-engagement.php` (reactions/replies/report)
  - `class-stories-rest-stickers.php` (stickers + poll vote)
- **Shared helpers**: move privacy normalization, rate limit, cache key, upload guard into `class-stories-utils.php`.
- **Frontend modularization**: split `assets/stories.js` into `viewer.js`, `composer.js`, `settings.js`, `archive.js`, `api.js`.
- **CSS organization**: group by component or split into viewer/composer/archive files; remove orphaned rules.
