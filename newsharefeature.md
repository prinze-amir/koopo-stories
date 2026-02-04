# Share to Story Feature (Status + Plan)

## Goal
Let admins choose which post types show a **"Share to Story"** button, and allow users to share eligible content to a story.

## Current Status (Verified in code)

### Implemented
- **Share button on single posts**
  - `includes/stories/class-stories-share.php` appends a button to singular posts if the post type is allowed and an image exists.
- **Share button in BuddyBoss activity**
  - Button added to activity meta and dropdown options.
  - Auto-detects an image inside the activity entry (`data-img="auto"`).
- **Share logic**
  - Uses option `koopo_stories_share_enabled_types` with default types:
    - `post`, `product`, `gd_event`, `gd_place`, `attachment`
  - Applies filter: `koopo_stories_share_post_types`.
- **Frontend behavior**
  - `assets/stories-share.js` loads the image, creates a `File`, and opens the composer with a link sticker.
- **Share script registration**
  - `includes/stories/class-stories-share.php` registers and enqueues `assets/stories-share.js`.
- **Share class loaded**
  - `includes/stories/class-stories-module.php` requires `class-stories-share.php`.

### Missing / Incomplete
- **Admin settings UI**
  - The setting `koopo_stories_share_enabled_types` is registered, **but no settings field is rendered**.
  - No `field_share_enabled_types()` implementation or `add_settings_field()` entry exists.
  - **Result:** Admins cannot configure post types from the UI (only via DB/options).
- **Profile “Stories” tab + Settings sub-tab**
  - `includes/admin/class-stories-profile.php` does not exist.
  - No BuddyBoss navigation registration for:
    - Profile > Stories
    - Settings > Stories (close friends/settings/archives)
  - **Result:** The documentation mentions these tabs, but they are not implemented.

## What’s Needed (Next Steps)

### 1) Admin Settings Field (Required)
Add a “Share to Story” post type selector to Stories Settings:
- Add `add_settings_field()` in `includes/admin/class-stories-admin.php`
- Implement `field_share_enabled_types()` to list all public post types as checkboxes

### 2) Profile & Settings Tabs (Not Implemented)
If you still want these UX sections:
- Create `includes/admin/class-stories-profile.php`
- Register BuddyBoss nav items:
  - Profile main tab: **Stories** (owner-only)
  - Sub-tab: **Archive**
  - Settings sub-tab: **Stories**
- Render the archive component and close friends/settings UI

### 3) Optional Enhancements
- Allow sharing non-image posts via a generated preview image or fallback artwork.
- Add a loading state or disabled state while the share image is being fetched.

## Files Reviewed
- `includes/stories/class-stories-share.php`
- `assets/stories-share.js`
- `includes/admin/class-stories-admin.php`
- `includes/stories/class-stories-module.php`
