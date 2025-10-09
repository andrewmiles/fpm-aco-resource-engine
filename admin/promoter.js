/** admin/promoter.js — Drop-in replacement
 *
 * ACO Resource Engine — Promote to Resource UI
 * - Robust upload detection via apiFetch middleware (covers block editor uploads, drag/drop, File block “Upload”)
 * - Fallback hook for media frame insertions via wp.media.editor.send.attachment
 * - A11y announcements (speak: true), safe notice actions (no HTML injection)
 * - Correct notice ID handling; prevents duplicate initialisation; hardened PDF checks
 */
(function (wp) {
  if (!wp) return;

  const { apiFetch, element, i18n, data } = wp;
  const { createElement: el } = element || { createElement: null };
  const { __ } = i18n || { __: (s) => s };
  const { select, dispatch } = data || {};

  // Permission gate from PHP (capability check)
  if (!window.acoPromoterData || !window.acoPromoterData.can_create_resource) {
    return;
  }

  // Prevent double-mounting across navigations/hot reloads
  if (window.__acoPromoterMounted) {
    return;
  }
  window.__acoPromoterMounted = true;

  const notices = dispatch && dispatch('core/notices');
  const processedAttachments = new Set();

  function isPdf(attachment) {
    const mime =
      (attachment && (attachment.mime || attachment.mime_type || attachment.type)) || '';
    return mime === 'application/pdf';
  }

  function getSourcePostId() {
    try {
      const editorStore = select && select('core/editor');
      if (editorStore && typeof editorStore.getCurrentPostId === 'function') {
        return editorStore.getCurrentPostId();
      }
    } catch (e) {}
    return null;
  }

  function showInfoToast(id, actions = []) {
    return notices.createNotice(
      'info',
      __('This PDF can be promoted to the Resource Library.', 'fpm-aco-resource-engine'),
      {
        id,
        type: 'snackbar',
        isDismissible: true,
        explicitDismiss: true,
        speak: true,
        actions, // [{ label, url } or { label, onClick }]
      }
    );
  }

  function handleNewAttachment(attachment) {
    if (!attachment || !attachment.id) return;
    if (!isPdf(attachment)) return;

    if (processedAttachments.has(attachment.id)) {
      return;
    }
    processedAttachments.add(attachment.id);

    const noticeId = `aco-promote-${attachment.id}`;

    const promoteAction = {
      label: __('Promote to Resource', 'fpm-aco-resource-engine'),
      onClick: () => {
        // Dismiss the info toast if still visible
        notices.removeNotice(noticeId);

        // Busy (non-dismissable) notice
        const busyId = notices.createNotice(
          'info',
          __('Promoting...', 'fpm-aco-resource-engine'),
          { isDismissible: false, speak: true }
        );

        const sourcePostId = getSourcePostId();

        apiFetch({
          path: '/aco/v1/promote',
          method: 'POST',
          data: {
            attachmentId: attachment.id,
            sourcePostId: sourcePostId,
          },
        })
          .then((response) => {
            notices.removeNotice(busyId);

            const actions = [];
            if (response && response.editLink) {
              actions.push({
                label: __('Edit the new Resource', 'fpm-aco-resource-engine'),
                url: response.editLink,
              });
            }

            const message =
              (response && response.message) ||
              __('Promoted to Resource.', 'fpm-aco-resource-engine');

            notices.createNotice('success', message, {
              type: 'snackbar',
              isDismissible: true,
              explicitDismiss: true,
              speak: true,
              actions,
            });
          })
          .catch((error) => {
            notices.removeNotice(busyId);
            const errorMessage =
              (error && error.message) ||
              __('An unknown error occurred.', 'fpm-aco-resource-engine');
            notices.createNotice('error', errorMessage, {
              isDismissible: true,
              speak: true,
            });
          });
      },
    };

    // Initial toast with the Promote CTA
    showInfoToast(noticeId, [promoteAction]);
  }

  // -------- Detection layer 1: apiFetch middleware (covers all editor uploads) --------
  if (apiFetch && typeof apiFetch.use === 'function' && !window.__acoPromoterApiFetchPatched) {
    window.__acoPromoterApiFetchPatched = true;

    apiFetch.use((options, next) => {
      const isCreateMedia =
        options &&
        typeof options.path === 'string' &&
        options.path.indexOf('/wp/v2/media') === 0 &&
        String(options.method || 'GET').toUpperCase() === 'POST';

      return next(options).then((result) => {
        try {
          // If a media item was created and it's a PDF, trigger the toast
          if (isCreateMedia && result && isPdf({ mime_type: result.mime_type })) {
            handleNewAttachment({ id: result.id, mime_type: result.mime_type });
          }
        } catch (e) {
          // no-op
        }
        return result;
      });
    });
  }

  // -------- Detection layer 2 (fallback): media frame insertion hook --------
  try {
    if (
      wp.media &&
      wp.media.editor &&
      wp.media.editor.send &&
      !wp.media.editor.__acoPromoterPatched
    ) {
      wp.media.editor.__acoPromoterPatched = true;

      const originalSend = wp.media.editor.send.attachment;
      wp.media.editor.send.attachment = function (props, attachment) {
        try {
          if (attachment && isPdf(attachment)) {
            handleNewAttachment(attachment);
          }
        } catch (e) {
          // no-op
        }
        return originalSend.apply(this, arguments);
      };
    }
  } catch (e) {
    // media frame not present; safe to ignore
  }

  // Optional dev logging, guarded by a flag
  if (window.acoPromoterData && window.acoPromoterData.debug) {
    const log = (...args) => (console && console.log ? console.log(...args) : null);
    log('[ACO Promote] Mounted');
  }
})(window.wp);
