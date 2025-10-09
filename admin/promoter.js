/** admin/promoter.js — Drop-in replacement
 * ACO Resource Engine — Promote to Resource UI
 * - Robust upload detection via apiFetch middleware (covers block editor uploads, drag/drop, File block “Upload”)
 * - Fallback hook for media frame insertions via wp.media.editor.send.attachment
 * - A11y announcements (speak: true), safe notice actions (no HTML injection)
 * - Correct notice ID handling; prevents duplicate initialisation; hardened PDF checks
 */
(function (wp) {
  if (!wp) return;

  const { apiFetch, data, i18n } = wp;
  const { __ } = i18n || { __: (s) => s };
  const { select, dispatch } = data || {};

  // Permission gate from PHP
  if (!window.acoPromoterData || window.acoPromoterData.can_create_resource !== '1') return;

  // Prevent double-mounting
  if (window.__acoPromoterMounted) return;
  window.__acoPromoterMounted = true;

  const notices = dispatch && dispatch('core/notices');
  const processed = new Set();

  function isPdf(obj) {
    const mime = (obj && (obj.mime || obj.mime_type || obj.type)) || '';
    return mime === 'application/pdf';
  }

  function getSourcePostId() {
    try {
      const editor = select && select('core/editor');
      return editor && typeof editor.getCurrentPostId === 'function' ? editor.getCurrentPostId() : null;
    } catch (e) { return null; }
  }

  function showInfoToast(id, actions = []) {
    return notices.createNotice('info',
      __('This PDF can be promoted to the Resource Library.', 'fpm-aco-resource-engine'),
      { id, type: 'snackbar', isDismissible: true, explicitDismiss: true, speak: true, actions }
    );
  }

  function promote(attachmentId) {
    const busyId = notices.createNotice('info', __('Promoting...', 'fpm-aco-resource-engine'), { isDismissible: false, speak: true });
    const sourcePostId = getSourcePostId();

    apiFetch({
      path: '/aco/v1/promote',
      method: 'POST',
      data: { attachmentId, sourcePostId }
    })
      .then((response) => {
        notices.removeNotice(busyId);
        const actions = [];
        if (response && response.editLink) {
          actions.push({ label: __('Edit the new Resource', 'fpm-aco-resource-engine'), url: response.editLink });
        }
        const msg = (response && response.message) || __('Promoted to Resource.', 'fpm-aco-resource-engine');
        notices.createNotice('success', msg, { type: 'snackbar', isDismissible: true, explicitDismiss: true, speak: true, actions });
      })
      .catch((error) => {
        notices.removeNotice(busyId);
        const msg = (error && error.message) || __('An unknown error occurred.', 'fpm-aco-resource-engine');
        notices.createNotice('error', msg, { isDismissible: true, speak: true });
      });
  }

  function handleNewAttachment(attachment) {
    if (!attachment || !attachment.id) return;
    if (!isPdf(attachment)) return;
    if (processed.has(attachment.id)) return;
    processed.add(attachment.id);

    const noticeId = `aco-promote-${attachment.id}`;
    const action = {
      label: __('Promote to Resource', 'fpm-aco-resource-engine'),
      onClick: () => {
        notices.removeNotice(noticeId);
        promote(attachment.id);
      }
    };
    showInfoToast(noticeId, [action]);
  }

  // -------- Robust media-create detection via apiFetch middleware --------
  function isCreateMediaRequest(options) {
    const method = String(options && options.method || 'GET').toUpperCase();
    if (method !== 'POST') return false;

    const path = String((options && options.path) || '');
    const url  = String((options && options.url) || '');
    const target = url || path;

    // Match both pretty and plain permalink forms:
    //  - /wp/v2/media
    //  - https://site.tld/wp-json/wp/v2/media
    //  - ?rest_route=/wp/v2/media
    const rePretty = /\/wp\/v2\/media(?:\/|\?|$)/;
    const rePlain  = /(?:^|[?&])rest_route=\/wp\/v2\/media(?:\/|&|$)/;
    return rePretty.test(target) || rePlain.test(target);
  }

  if (apiFetch && typeof apiFetch.use === 'function' && !window.__acoPromoterApiPatched) {
    window.__acoPromoterApiPatched = true;
    apiFetch.use((options, next) => {
      const shouldWatch = isCreateMediaRequest(options);
      if (window.acoPromoterData && window.acoPromoterData.debug && shouldWatch) {
        console.info('[ACO Promote] Intercepting media create:', options);
      }
      return next(options).then((result) => {
        try {
          if (shouldWatch && result && isPdf({ mime_type: result.mime_type })) {
            handleNewAttachment({ id: result.id, mime_type: result.mime_type });
          }
        } catch (e) { /* no-op */ }
        return result;
      });
    });
  }

  // -------- Fallback: library insertion via media frame --------
  try {
    if (wp.media && wp.media.editor && wp.media.editor.send && !wp.media.editor.__acoPromoterPatched) {
      wp.media.editor.__acoPromoterPatched = true;
      const originalSend = wp.media.editor.send.attachment;
      wp.media.editor.send.attachment = function (props, attachment) {
        try { if (attachment && isPdf(attachment)) handleNewAttachment(attachment); } catch (e) {}
        return originalSend.apply(this, arguments);
      };
    }
  } catch (e) { /* media frame not present; safe to ignore */ }

  // Optional dev logging
  if (window.acoPromoterData && window.acoPromoterData.debug) {
    console.info('[ACO Promote] Mounted');
  }
})(window.wp);
