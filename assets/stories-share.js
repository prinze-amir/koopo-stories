(() => {
    // Wait for DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShareButtons);
    } else {
        initShareButtons();
    }

    function initShareButtons() {
        const shareCfg = window.KoopoStoriesShare || {};

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

        const inferMediaKindFromUrl = (url) => {
            const ext = String(url || '').split(/[?#]/)[0].split('.').pop().toLowerCase();
            if (['mp4', 'mov', 'm4v', 'webm', '3gp', '3g2', 'avi', 'mkv'].includes(ext)) return 'video';
            if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'avif'].includes(ext)) return 'image';
            return '';
        };

        const inferMimeFromMedia = (kind, url) => {
            const ext = String(url || '').split(/[?#]/)[0].split('.').pop().toLowerCase();
            if (ext === 'mov') return 'video/quicktime';
            if (ext === 'webm') return 'video/webm';
            if (ext === 'm4v') return 'video/mp4';
            if (ext === 'mp4') return 'video/mp4';
            if (ext === 'png') return 'image/png';
            if (ext === 'webp') return 'image/webp';
            if (ext === 'gif') return 'image/gif';
            return kind === 'video' ? 'video/mp4' : 'image/jpeg';
        };

        const detectMediaFromActivity = (activityEntry) => {
            if (!activityEntry) return { url: '', kind: '' };

            // 1) Videos first so poster thumbnails do not mask actual video media.
            const videoEl = activityEntry.querySelector('.bp-activity-media video, .activity-inner video, .bp-activity-content video');
            if (videoEl) {
                const src = videoEl.currentSrc || videoEl.src || (videoEl.querySelector('source') ? videoEl.querySelector('source').src : '');
                if (src) return { url: src, kind: 'video' };
            }

            // 2) Images
            const mediaImg = activityEntry.querySelector('.bp-activity-media img, .activity-inner img, .bp-activity-content img');
            if (mediaImg && mediaImg.src) {
                return { url: mediaImg.src, kind: 'image' };
            }

            // 3) Background image fallbacks used by some activity cards
            const bgNode = activityEntry.querySelector('[style*="background-image"]');
            if (bgNode && bgNode.style && bgNode.style.backgroundImage) {
                const match = bgNode.style.backgroundImage.match(/url\\((['"]?)(.*?)\\1\\)/i);
                if (match && match[2]) return { url: match[2], kind: 'image' };
            }

            return { url: '', kind: '' };
        };

        const parseActivityIdFromLink = (link) => {
            if (!link) return 0;
            const m = String(link).match(/\/activity\/(?:p\/)?(\d+)(?:\/|$|\?)/i);
            return m ? parseInt(m[1], 10) || 0 : 0;
        };

        const readIdFromNode = (node) => {
            if (!node) return 0;
            const candidates = [
                node.dataset && (node.dataset.bpActivityId || node.dataset.activityId || node.dataset.activityid),
                node.getAttribute && node.getAttribute('data-bp-activity-id'),
                node.getAttribute && node.getAttribute('data-activity-id'),
            ];
            for (const c of candidates) {
                const n = parseInt(c || '', 10);
                if (n > 0) return n;
            }
            const idAttr = node.id || '';
            const idMatch = idAttr.match(/activity[-_](\d+)/i);
            if (idMatch) return parseInt(idMatch[1], 10) || 0;
            return 0;
        };

        const detectActivityId = (btn) => {
            const direct = parseInt(btn.dataset.activityId || btn.dataset.bpActivityId || '', 10);
            if (direct > 0) return direct;

            const contexts = [
                btn.closest('[data-bp-activity-id]'),
                btn.closest('[data-activity-id]'),
                btn.closest('.activity-item'),
                btn.closest('.bp-activity-entry'),
                btn.closest('[id^="activity-"]'),
                document.querySelector('.bb-modal [data-bp-activity-id], .buddypress-wrap [data-bp-activity-id]'),
            ];
            for (const node of contexts) {
                const found = readIdFromNode(node);
                if (found > 0) return found;
            }

            const fromLink = parseActivityIdFromLink(btn.dataset.link || btn.dataset.activityLink || '');
            if (fromLink > 0) return fromLink;

            const canonical = document.querySelector('link[rel="canonical"]');
            return parseActivityIdFromLink(canonical ? canonical.href : '');
        };

        const fetchActivityMedia = async (activityId) => {
            if (!activityId || !shareCfg.ajaxUrl || !shareCfg.nonce) return { url: '', kind: '' };
            const form = new FormData();
            form.append('action', 'koopo_stories_get_activity_media');
            form.append('activity_id', String(activityId));
            form.append('nonce', shareCfg.nonce);
            const res = await fetch(shareCfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: form,
            });
            if (!res.ok) return { url: '', kind: '' };
            const payload = await res.json();
            if (!payload || !payload.success || !payload.data || !payload.data.url) return { url: '', kind: '' };
            return { url: payload.data.url, kind: payload.data.kind || '' };
        };

        const buildActivityProxyUrl = (activityId) => {
            if (!activityId || !shareCfg.ajaxUrl || !shareCfg.nonce) return '';
            const url = new URL(shareCfg.ajaxUrl, window.location.origin);
            url.searchParams.set('action', 'koopo_stories_get_activity_media_file');
            url.searchParams.set('activity_id', String(activityId));
            url.searchParams.set('nonce', shareCfg.nonce);
            return url.toString();
        };

        const hexToRgba = (hex, alpha) => {
            if (!hex || typeof hex !== 'string' || !hex.startsWith('#')) return '';
            const h = hex.replace('#', '');
            const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
            if (full.length !== 6) return '';
            const n = parseInt(full, 16);
            const r = (n >> 16) & 255;
            const g = (n >> 8) & 255;
            const b = n & 255;
            const a = Math.max(0, Math.min(1, Number(alpha)));
            return `rgba(${r}, ${g}, ${b}, ${a})`;
        };

        const applyStickerPresentation = (content, sticker) => {
            const style = (sticker && sticker.data && sticker.data.style) ? sticker.data.style : {};
            const hasBox = style.box_w || style.box_h;
            if (hasBox) {
                if (style.box_w) content.style.width = `${style.box_w}px`;
                if (style.box_h) content.style.height = `${style.box_h}px`;
                content.style.transform = 'none';
                content.style.transformOrigin = 'center';
            } else {
                const scaleX = Math.max(0.5, Math.min(2.5, parseFloat(style.scale_x != null ? style.scale_x : (parseFloat(style.scale) || 1))));
                const scaleY = Math.max(0.5, Math.min(2.5, parseFloat(style.scale_y != null ? style.scale_y : (parseFloat(style.scale) || 1))));
                content.style.transform = `scale(${scaleX}, ${scaleY})`;
                content.style.transformOrigin = 'center';
            }
            if (style.text_color) content.style.color = style.text_color;
            if (style.bg_color) {
                const opacity = (style.bg_opacity != null) ? Number(style.bg_opacity) : 1;
                content.style.backgroundColor = hexToRgba(style.bg_color, opacity);
            }
            if (style.font_size) content.style.fontSize = `${parseInt(style.font_size, 10)}px`;
            if (style.font_family && style.font_family !== 'inherit') content.style.fontFamily = style.font_family;
            if (style.font_weight) content.style.fontWeight = style.font_weight;
            if (style.font_style) content.style.fontStyle = style.font_style;
            if (style.text_decoration) content.style.textDecoration = style.text_decoration;
            if (style.text_align) content.style.textAlign = style.text_align;
        };

        const createStickerNode = (sticker) => {
            if (!sticker || !sticker.type) return null;
            const pos = sticker.position || { x: 50, y: 50 };
            const wrapper = document.createElement('div');
            wrapper.className = 'koopo-stories__sticker';
            wrapper.style.position = 'absolute';
            wrapper.style.left = `${pos.x}%`;
            wrapper.style.top = `${pos.y}%`;
            wrapper.style.transform = 'translate(-50%, -50%)';
            wrapper.style.zIndex = '10';

            let content = null;
            switch (sticker.type) {
                case 'mention': {
                    content = document.createElement('div');
                    content.className = 'koopo-stories__sticker-mention';
                    content.style.cssText = 'background:rgba(0,0,0,0.7);color:#fff;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:500;border:1px solid #ffba12;';
                    content.textContent = `@${sticker.data && sticker.data.username ? sticker.data.username : ''}`;
                    break;
                }
                case 'link': {
                    content = document.createElement('div');
                    content.className = 'koopo-stories__sticker-link';
                    content.style.cssText = 'background:rgba(255,255,255,0.95);color:#000;padding:10px 12px;border-radius:12px;font-size:12px;max-width:180px;';
                    const title = document.createElement('div');
                    title.style.cssText = 'font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                    title.textContent = (sticker.data && sticker.data.title) ? sticker.data.title : '';
                    const url = document.createElement('div');
                    url.style.cssText = 'font-size:11px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                    url.textContent = (sticker.data && sticker.data.url) ? sticker.data.url : '';
                    content.appendChild(title);
                    content.appendChild(url);
                    break;
                }
                case 'location': {
                    content = document.createElement('div');
                    content.className = 'koopo-stories__sticker-location';
                    content.style.cssText = 'background:rgba(0,0,0,0.7);color:#fff;padding:8px 12px;border-radius:16px;font-size:12px;';
                    content.textContent = (sticker.data && sticker.data.name) ? sticker.data.name : '';
                    break;
                }
                case 'text': {
                    content = document.createElement('div');
                    content.className = 'koopo-stories__sticker-text';
                    content.style.cssText = 'background:rgba(0,0,0,0.6);color:#fff;padding:8px 10px;border-radius:10px;font-size:14px;font-weight:600;max-width:220px;text-align:center;white-space:pre-wrap;';
                    content.textContent = (sticker.data && sticker.data.text) ? sticker.data.text : '';
                    break;
                }
                case 'media': {
                    content = document.createElement('div');
                    content.className = 'koopo-stories__sticker-media';
                    content.style.cssText = 'width:96px;height:96px;border-radius:12px;overflow:hidden;box-shadow:0 6px 16px rgba(0,0,0,.25);background:rgba(0,0,0,.08);';
                    const url = sticker.data && sticker.data.url ? sticker.data.url : '';
                    if (sticker.data && sticker.data.mime === 'application/json') {
                        const lottie = document.createElement('lottie-player');
                        lottie.setAttribute('src', url);
                        lottie.setAttribute('background', 'transparent');
                        lottie.setAttribute('speed', '1');
                        lottie.setAttribute('loop', 'true');
                        lottie.setAttribute('autoplay', 'true');
                        lottie.style.width = '100%';
                        lottie.style.height = '100%';
                        content.appendChild(lottie);
                        if (!(window.customElements && window.customElements.get('lottie-player'))) {
                            const existing = document.getElementById('koopo-lottie-player-script');
                            if (!existing) {
                                const script = document.createElement('script');
                                script.id = 'koopo-lottie-player-script';
                                script.src = 'https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js';
                                script.async = true;
                                document.head.appendChild(script);
                            }
                        }
                    } else {
                        const img = document.createElement('img');
                        img.src = url;
                        img.alt = '';
                        img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
                        content.appendChild(img);
                    }
                    break;
                }
                case 'poll': {
                    content = document.createElement('div');
                    content.className = 'koopo-stories__sticker-poll';
                    content.style.cssText = 'background:rgba(255,255,255,0.95);color:#000;padding:12px;border-radius:12px;min-width:200px;max-width:240px;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
                    const question = document.createElement('div');
                    question.style.cssText = 'font-weight:600;font-size:13px;margin-bottom:8px;';
                    question.textContent = (sticker.data && sticker.data.question) ? sticker.data.question : '';
                    content.appendChild(question);
                    const options = (sticker.data && Array.isArray(sticker.data.options)) ? sticker.data.options : [];
                    options.forEach((opt) => {
                        const row = document.createElement('div');
                        row.style.cssText = 'padding:6px 8px;border-radius:10px;background:#f5f5f5;margin-bottom:6px;font-size:12px;';
                        row.textContent = opt.text || '';
                        content.appendChild(row);
                    });
                    break;
                }
                default:
                    return null;
            }

            if (content) {
                applyStickerPresentation(content, sticker);
                wrapper.appendChild(content);
                return wrapper;
            }
            return null;
        };

        const renderSharedStoryStickers = (root = document) => {
            const nodes = root.querySelectorAll ? root.querySelectorAll('.koopo-story-share-media[data-stickers]') : [];
            nodes.forEach((wrap) => {
                if (wrap.dataset.stickersRendered === '1') return;
                const raw = wrap.dataset.stickers || '';
                if (!raw) return;
                let stickers = [];
                try {
                    stickers = JSON.parse(raw);
                } catch (e) {
                    stickers = [];
                }
                if (!Array.isArray(stickers) || !stickers.length) return;
                stickers.forEach((sticker) => {
                    const node = createStickerNode(sticker);
                    if (node) wrap.appendChild(node);
                });
                wrap.dataset.stickersRendered = '1';
            });
        };

        const observeSharedStoryStickers = () => {
            if (!window.MutationObserver) return;
            const observer = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    for (const node of mutation.addedNodes) {
                        if (!(node instanceof HTMLElement)) continue;
                        if (node.matches && node.matches('.koopo-story-share-media[data-stickers]')) {
                            renderSharedStoryStickers(node.parentNode || node);
                        } else if (node.querySelector && node.querySelector('.koopo-story-share-media[data-stickers]')) {
                            renderSharedStoryStickers(node);
                        }
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
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

            const activityId = detectActivityId(btn);
            let mediaUrl = btn.dataset.img;
            let mediaKind = '';
            const linkUrl = btn.dataset.link || btn.dataset.activityLink || '';
            const title = btn.dataset.title || 'Shared Activity';

            // Handle auto image detection (e.g. for activity feed)
            if (mediaUrl === 'auto') {
                if (activityId) {
                    const serverDetected = await fetchActivityMedia(activityId);
                    mediaUrl = serverDetected.url;
                    mediaKind = serverDetected.kind;
                } else {
                    const activityEntry = btn.closest('.activity-item') || btn.closest('.bp-activity-entry') || btn.closest('li.activity-item');
                    const detected = detectMediaFromActivity(activityEntry);
                    mediaUrl = detected.url;
                    mediaKind = detected.kind;
                }
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

                // Fetch media blob (prefer authenticated server proxy for BuddyBoss activity media)
                const proxyUrl = buildActivityProxyUrl(activityId);
                let response = null;
                if (proxyUrl) {
                    response = await fetch(proxyUrl, { credentials: 'same-origin' });
                }
                if (!response || !response.ok) {
                    response = await fetch(mediaUrl, { credentials: 'same-origin' });
                }
                if (!response.ok) throw new Error('Failed to load media');
                const blob = await response.blob();

                // Create File object
                mediaKind = mediaKind || inferMediaKindFromUrl(mediaUrl) || inferMediaKindFromUrl(response.url);
                const blobMime = blob.type && blob.type !== 'application/octet-stream' ? blob.type : '';
                const mime = blobMime || inferMimeFromMedia(mediaKind, mediaUrl || response.url);
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
                composer.openComposer(file, { stickers, mediaKind, url: mediaUrl || response.url });

            } catch (err) {
                console.error('Share failed:', err);
                alert('Could not prepare story. ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        };

        renderSharedStoryStickers(document);
        observeSharedStoryStickers();

        document.addEventListener('pointerup', handler, true);
        document.addEventListener('click', handler, true);
        document.body.addEventListener('click', handler);
    }

})();
