(function () {
  if (!window.wp || !wp.data || !wp.domReady || !wp.apiFetch) return;

  wp.domReady(function () {
    const { select, subscribe, dispatch } = wp.data;
    const notices = dispatch('core/notices');
    const promptedFor = new Set();

    function isPdfFileBlock(block) {
      if (!block || block.name !== 'core/file') return false;
      const href = block.attributes && block.attributes.href;
      return typeof href === 'string' && /\.pdf(\?|$)/i.test(href);
    }

    function findNewPdfBlock() {
      const blocks = select('core/block-editor').getBlocks() || [];
      for (const b of blocks) {
        if (!isPdfFileBlock(b)) continue;
        const href = (b.attributes && b.attributes.href) || '';
        const id = (b.attributes && b.attributes.id) || 0;
        const key = id ? `id:${id}` : `href:${href}`;
        if (!promptedFor.has(key)) {
          promptedFor.add(key);
          return { block: b, href, id };
        }
      }
      return null;
    }

    subscribe(function () {
      const found = findNewPdfBlock();
      if (!found) return;

      const { id: attachmentId } = found;
      const postId = select('core/editor').getCurrentPostId();
      if (!postId || !attachmentId) return;

      const noticeId = 'aco-promote-' + attachmentId;

      const handlePromote = async () => {
        try {
          const res = await wp.apiFetch({
            path: '/aco/v1/promote',
            method: 'POST',
            data: { postId, attachmentId }
          });
          notices.createNotice('success', 'Promoted to Resource.', {
            isDismissible: true,
            actions: res && res.resourcePermalink
              ? [{ label: 'View Resource', url: res.resourcePermalink }]
              : []
          });
        } catch (e) {
          notices.createNotice('error', 'Could not promote this PDF. Please try again.', {
            isDismissible: true
          });
        } finally {
          dispatch('core/notices').removeNotice(noticeId);
        }
      };

      dispatch('core/notices').createNotice(
        'info',
        'Add to Resource Library?\nThis PDF can be promoted to a canonical Resource so it’s searchable and reusable.',
        {
          id: noticeId,
          isDismissible: true,
          actions: [
            { label: 'Promote to Resource', onClick: handlePromote },
            { label: 'Keep in this post', onClick: () => dispatch('core/notices').removeNotice(noticeId) }
          ]
        }
      );
    });
  });
})();
