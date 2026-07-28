# Share to Story Feature (Status + Plan)

## Verification Date
March 1, 2026

## Goal
Let admins choose which post types show a **Share to Story** button, and allow users to share eligible content to a story and to activity with good media quality.

## Current Status (Verified in code)

### Implemented
- Share button on single posts (`includes/stories/class-stories-share.php`).
- Share button in BuddyBoss activity meta/dropdowns (Nouveau + fallback hooks).
- Admin setting UI for enabled post types (`includes/admin/class-stories-admin.php`, `field_share_enabled_types()`).
- Share script registration/enqueue (`assets/stories-share.js`).
- Activity media detection path with attachment/meta/content fallbacks.
- Authenticated media proxy endpoint for activity share media retrieval.
- Story-to-activity publishing endpoint (`koopo_stories_share_story_activity`).
- Profile navigation for **Stories > Archive** and **Settings > Stories** (`includes/stories/class-stories-profile.php`).

### Recently Improved
- Story-to-activity preview media now defaults to higher quality (`large`) via `koopo_stories_activity_media_size` filter.
- Legacy activity cards now recompute media references from current attachment sizes to avoid stale low-res snapshots.

### Remaining / Optional
- Sharing non-image posts with generated preview cards (currently image/video-first behavior).
- Better UX messaging while story share media is being fetched in edge network cases.
- Add explicit automated E2E coverage for activity modal/media-quality regressions.

## Recommended Next Steps
1. Run live E2E flow once local WordPress/BuddyBoss is running:
   - Share post/activity to story.
   - Post story to activity.
   - Verify image and video preview quality in activity feed cards.
2. Add a lightweight regression smoke script that validates share-to-activity metadata (`koopo_story_snapshot`, `koopo_story_thumb`, `koopo_story_media_type`) after posting.
3. Optionally expose the `koopo_stories_activity_media_size` choice in admin settings for non-code tuning.

## Files Reviewed
- `includes/stories/class-stories-share.php`
- `assets/stories-share.js`
- `includes/admin/class-stories-admin.php`
- `includes/stories/class-stories-profile.php`
