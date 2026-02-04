(() => {
  const cfg = window.KoopoStoriesSuperSocializer || {};
  const enableStandard = !!cfg.enableStandard;
  const enableFloating = !!cfg.enableFloating;

  if (!enableStandard && !enableFloating) {
    return;
  }

  const getMeta = (selector) => {
    const el = document.querySelector(selector);
    return el ? el.getAttribute('content') : '';
  };

  const getShareImage = (container) => {
    if (container && container.closest('.activity-item, .bp-activity-entry')) {
      return 'auto';
    }
    return (
      getMeta('meta[property="og:image"]') ||
      getMeta('meta[property="og:image:secure_url"]') ||
      getMeta('meta[name="twitter:image"]') ||
      ''
    );
  };

  const getShareTitle = () =>
    getMeta('meta[property="og:title"]') ||
    getMeta('meta[name="twitter:title"]') ||
    document.title ||
    'Shared Content';

  const getShareUrl = (container) =>
    (container && container.dataset && container.dataset.superSocializerHref) ||
    window.location.href;

  const buildButton = (container) => {
    const a = document.createElement('a');
    a.href = 'javascript:void(0)';
    a.className = 'koopo-share-to-story the_champ_button_koopo_story';
    a.setAttribute('aria-label', 'Share to Story');
    a.dataset.link = getShareUrl(container);
    a.dataset.title = getShareTitle();
    a.dataset.type = 'super_socializer';

    const img = getShareImage(container);
    if (img) {
      a.dataset.img = img;
    }

    const span = document.createElement('span');
    span.className = 'the_champ_svg koopo-ss-share-icon';
    if (cfg.iconUrl) {
      span.style.backgroundImage = `url(${cfg.iconUrl})`;
    }
    span.style.backgroundRepeat = 'no-repeat';
    span.style.backgroundPosition = 'center';
    span.style.backgroundSize = 'contain';
    span.style.display = 'inline-block';

    const ref = container ? container.querySelector('.the_champ_svg') : null;
    if (ref) {
      const styles = window.getComputedStyle(ref);
      span.style.width = styles.width;
      span.style.height = styles.height;
      span.style.borderRadius = styles.borderRadius;
    }

    a.appendChild(span);
    return a;
  };

  const injectInto = (container) => {
    if (!container) return;
    const list = container.querySelector('.the_champ_sharing_ul');
    if (!list) return;
    if (list.querySelector('.koopo-share-to-story')) return;

    const btn = buildButton(container);
    list.insertBefore(btn, list.firstChild);
  };

  const scan = () => {
    if (enableStandard) {
      document
        .querySelectorAll('.the_champ_horizontal_sharing')
        .forEach(injectInto);
    }
    if (enableFloating) {
      document
        .querySelectorAll('.the_champ_vertical_sharing')
        .forEach(injectInto);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }

  const observer = new MutationObserver(() => scan());
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
