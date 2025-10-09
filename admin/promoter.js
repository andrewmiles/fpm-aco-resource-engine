/**
 * ACO Resource Engine - Promote to Resource UI
 *
 * Displays a toast notification after a PDF is uploaded, offering to promote it
 * to a canonical Resource post type.
 */
(function (wp) {
  const { apiFetch } = wp;
  const { select, subscribe } = wp.data;
  const { createNotice } = wp.notices;
  const { __ } = wp.i18n;

  // Ensure we don't act on the same attachment multiple times per session.
  const processedAttachments = new Set();

  function handleNewAttachment(attachment) {
    // Check permissions passed from PHP.
    if (!window.acoPromoterData || !window.acoPromoterData.can_create_resource) {
      return;
    }

    if (processedAttachments.has(attachment.id)) {
      return;
    }

    // Only act on PDFs.
    if (!attachment.mime || attachment.mime !== 'application/pdf') {
      return;
    }

    processedAttachments.add(attachment.id);

    const noticeId = `aco-promote-${attachment.id}`;

    const promoteAction = {
      label: __('Promote to Resource', 'fpm-aco-resource-engine'),
      callback: () => {
        wp.data.dispatch('core/notices').removeNotice(noticeId);
        const busyNotice = createNotice('info', __('Promoting...', 'fpm-aco-resource-engine'), {
          isDismissible: false,
        });

        // If we are in an editor context, get the post ID for link rewriting.
        const editor = select('core/editor');
        const sourcePostId = editor ? editor.getCurrentPostId() : null;

        apiFetch({
          path: '/aco/v1/promote',
          method: 'POST',
          data: {
            attachmentId: attachment.id,
            sourcePostId: sourcePostId,
          },
        })
          .then((response) => {
            wp.data.dispatch('core/notices').removeNotice(busyNotice.id);

            let message = response.message;
            if (response.editLink) {
              message += ` <a href="${response.editLink}" target="_blank" rel="noopener noreferrer">${__('Edit the new Resource.', 'fpm-aco-resource-engine')}</a>`;
            }

            createNotice('success', message, {
              isDismissible: true,
              type: 'snackbar',
              explicitDismiss: true,
            });
          })
          .catch((error) => {
            wp.data.dispatch('core/notices').removeNotice(busyNotice.id);
            const errorMessage = error.message || __('An unknown error occurred.', 'fpm-aco-resource-engine');
            createNotice('error', errorMessage, { isDismissible: true });
          });
      },
    };

    createNotice('info', __('This PDF can be promoted to the Resource Library.', 'fpm-aco-resource-engine'), {
      id: noticeId,
      isDismissible: true,
      type: 'snackbar',
      actions: [promoteAction],
    });
  }

  // Hook into the media library data store.
  let previousAttachments = [];
  subscribe(() => {
    // This targets newly uploaded items appearing in the main library view.
    const newAttachments = select('core').getMediaItems({ per_page: 5, orderby: 'date', order: 'desc' }) || [];

    // --- DEBUGGING LINE ---
    console.log('Upload detector fired. Found attachments:', newAttachments.map(att => att.id));
    // --- END DEBUGGING ---

    if (newAttachments.length > 0 && newAttachments !== previousAttachments) {
      const latestAttachment = newAttachments[0];
      const isAlreadyKnown = previousAttachments.some(att => att.id === latestAttachment.id);
      if (!isAlreadyKnown) {
        handleNewAttachment(latestAttachment);
      }
    }
    previousAttachments = newAttachments;
  });

})(window.wp);
