(() => {
    // Wait for DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShareButtons);
    } else {
        initShareButtons();
    }

    function initShareButtons() {
        const handler = async (e) => {
            // Check if clicked element or parent is the button (for icon clicks)
            const btn = e.target.closest('.koopo-share-to-story');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();

            if (btn.disabled) return;

            // Ensure Composer loader is available
            if (!window.KoopoStoriesUI || !window.KoopoStoriesUI.ensureComposer) {
                console.error('Stories UI not loaded');
                alert('Stories module not ready yet. Please try again in a moment.');
                return;
            }

            let imgUrl = btn.dataset.img;
            const linkUrl = btn.dataset.link || btn.dataset.activityLink || '';
            const title = btn.dataset.title || 'Shared Activity';
            const activityId = btn.dataset.activityId;

            // Handle auto image detection (e.g. for activity feed)
            if (imgUrl === 'auto') {
                // Find closest activity entry
                let activityEntry = btn.closest('.activity-item') || btn.closest('.bp-activity-entry') || btn.closest('li.activity-item');
                if (!activityEntry && activityId) {
                    activityEntry = document.querySelector(`[data-bp-activity-id="${activityId}"]`) || document.querySelector(`#activity-${activityId}`);
                }
                if (activityEntry) {
                    // Try to find an image in activity content or media
                    // Prioritize activity media
                    const mediaImg = activityEntry.querySelector('.bp-activity-media img, .activity-inner img, .bp-activity-content img');
                    if (mediaImg) {
                        imgUrl = mediaImg.src;
                    }
                }
            }

            if (!imgUrl || imgUrl === 'auto') {
                alert('No image found to share in this post type.');
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
                const response = await fetch(imgUrl);
                if (!response.ok) throw new Error('Failed to load image');
                const blob = await response.blob();

                // Create File object
                const file = new File([blob], 'share-story.jpg', { type: blob.type });

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
