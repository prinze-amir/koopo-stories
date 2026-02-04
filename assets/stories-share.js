(() => {
    // Wait for DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShareButtons);
    } else {
        initShareButtons();
    }

    function initShareButtons() {
        const inferExtFromMime = (mime) => {
            const map = {
                'image/jpeg': 'jpg',
                'image/jpg': 'jpg',
                'image/png': 'png',
                'image/webp': 'webp',
                'image/gif': 'gif',
                'video/mp4': 'mp4',
                'video/webm': 'webm',
                'video/quicktime': 'mov',
            };
            return map[mime] || 'bin';
        };

        const detectMediaFromActivity = (activityEntry) => {
            if (!activityEntry) return { url: '', kind: '' };

            // 1) Images first
            const mediaImg = activityEntry.querySelector('.bp-activity-media img, .activity-inner img, .bp-activity-content img');
            if (mediaImg && mediaImg.src) {
                return { url: mediaImg.src, kind: 'image' };
            }

            // 2) Videos (BuddyBoss video posts / GIF-as-video)
            const videoEl = activityEntry.querySelector('.bp-activity-media video, .activity-inner video, .bp-activity-content video');
            if (videoEl) {
                const src = videoEl.currentSrc || videoEl.src || (videoEl.querySelector('source') ? videoEl.querySelector('source').src : '');
                if (src) return { url: src, kind: 'video' };
            }

            // 3) Background image fallbacks used by some activity cards
            const bgNode = activityEntry.querySelector('[style*="background-image"]');
            if (bgNode && bgNode.style && bgNode.style.backgroundImage) {
                const match = bgNode.style.backgroundImage.match(/url\\((['"]?)(.*?)\\1\\)/i);
                if (match && match[2]) return { url: match[2], kind: 'image' };
            }

            return { url: '', kind: '' };
        };

        const handler = async (e) => {
            // Check if clicked element or parent is the button (for icon clicks)
            const btn = e.target.closest('.koopo-share-to-story');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();

            if (btn.disabled) return;

            const waitForStoriesUI = async (timeoutMs = 2500) => {
                const start = Date.now();
                while (Date.now() - start < timeoutMs) {
                    if (window.KoopoStoriesUI && window.KoopoStoriesUI.ensureComposer) {
                        return true;
                    }
                    await new Promise(r => setTimeout(r, 50));
                }
                return false;
            };
            if (!(await waitForStoriesUI())) {
                console.error('Stories UI not loaded');
                alert('Stories module not ready yet. Please try again in a moment.');
                return;
            }

            let mediaUrl = btn.dataset.img;
            let mediaKind = '';
            const linkUrl = btn.dataset.link || btn.dataset.activityLink || '';
            const title = btn.dataset.title || 'Shared Activity';
            const activityId = btn.dataset.activityId;

            // Handle auto image detection (e.g. for activity feed)
            if (mediaUrl === 'auto') {
                // Find closest activity entry
                let activityEntry = btn.closest('.activity-item') || btn.closest('.bp-activity-entry') || btn.closest('li.activity-item');
                if (!activityEntry && activityId) {
                    activityEntry = document.querySelector(`[data-bp-activity-id="${activityId}"]`) || document.querySelector(`#activity-${activityId}`);
                }
                const detected = detectMediaFromActivity(activityEntry);
                mediaUrl = detected.url;
                mediaKind = detected.kind;
            }

            if (!mediaUrl || mediaUrl === 'auto') {
                alert('No media found to share in this post.');
                return;
            }

            btn.disabled = true;
            // Store icon if present (to restore later if needed, though usually we just leave it disabled or reload)
            const originalText = btn.innerHTML; // Use innerHTML to keep icon
            btn.textContent = 'Loading...';

            try {
                // Load composer module
                const composer = await window.KoopoStoriesUI.ensureComposer();

                // Fetch image to blob
                const response = await fetch(mediaUrl);
                if (!response.ok) throw new Error('Failed to load media');
                const blob = await response.blob();

                // Create File object
                const mime = blob.type || (mediaKind === 'video' ? 'video/mp4' : 'image/jpeg');
                const ext = inferExtFromMime(mime);
                const file = new File([blob], `share-story.${ext}`, { type: mime });

                // Prepare stickers
                const stickers = [];

                // Add Link Sticker
                if (linkUrl) {
                    stickers.push({
                        type: 'link',
                        data: {
                            title: title || 'Shared Content',
                            url: linkUrl
                        },
                        position: { x: 50, y: 80 } // Center bottom
                    });
                }

                // Open Composer
                composer.openComposer(file, { stickers });

            } catch (err) {
                console.error('Share failed:', err);
                alert('Could not prepare story. ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        };

        document.addEventListener('pointerup', handler, true);
        document.addEventListener('click', handler, true);
        document.body.addEventListener('click', handler);
    }

})();
