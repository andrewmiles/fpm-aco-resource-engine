(function () {
  if (!window.wp || !wp.data || !wp.domReady || !wp.apiFetch) return;

  wp.domReady(function () {
    const { select, subscribe, dispatch } = wp.data;
    const notices = dispatch("core/notices");
    const promptedFor = new Set();

    // --- Snooze (per post + attachment), persisted across sessions for this browser ---
    const snoozeKey = (postId, attachmentId) =>
      `aco-promote-snooze:${postId}:${attachmentId}`;
    const isSnoozed = (postId, attachmentId) => {
      try {
        return !!localStorage.getItem(snoozeKey(postId, attachmentId));
      } catch (e) {
        return false;
      }
    };
    const snooze = (postId, attachmentId) => {
      try {
        localStorage.setItem(snoozeKey(postId, attachmentId), "1");
      } catch (e) {
        /* no-op if storage unavailable */
      }
    };

    function isPdfFileBlock(block) {
      if (!block || block.name !== "core/file") return false;
      const href = block.attributes && block.attributes.href;
      return typeof href === "string" && /\.pdf(\?|$)/i.test(href);
    }

    function findNewPdfBlock() {
      const blocks = select("core/block-editor").getBlocks() || [];
      for (const b of blocks) {
        if (!isPdfFileBlock(b)) continue;
        const href = (b.attributes && b.attributes.href) || "";
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
      const postId = select("core/editor").getCurrentPostId();
      if (!postId || !attachmentId) return;

      if (isSnoozed(postId, attachmentId)) return;

      const noticeId = "aco-promote-" + attachmentId;

      const handlePromote = async () => {
        try {
          const res = await wp.apiFetch({
            path: "/aco/v1/promote",
            method: "POST",
            data: { postId, attachmentId },
          });
          notices.createNotice("success", "Promoted to the Resource Library.", {
            isDismissible: true,
            context: "aco-promote",
            actions:
              res && res.resourcePermalink
                ? [{ label: "View Resource", url: res.resourcePermalink }]
                : [],
          });
        } catch (e) {
          notices.createNotice(
            "error",
            "Could not promote this PDF. Please try again.",
            {
              isDismissible: true,
              context: "aco-promote",
            }
          );
        } finally {
          dispatch("core/notices").removeNotice(noticeId);
        }
      };

      // Clear, non-jargony copy + reasons; “error” gives a light red background.
      const message = [
        "Add this PDF to the Resource Library?",
        "",
        "Promoting will:",
        "• create a single library item with a stable link,",
        "• make it easier to find in site search, and",
        "• keep tags consistent using the approved list.",
        "",
        "If this PDF is only relevant to this post, choose 'Keep in this post' or close this message.",
        "Choose 'Don't ask again' to hide this prompt for this PDF in this post.",
      ].join("\n");

      dispatch("core/notices").createNotice(
        "warning", // amber background; change to 'error' for red
        message,
        {
          id: noticeId,
          isDismissible: true, // closing (X) == Keep in this post
          context: "aco-promote",
          speak: true,
          actions: [
            { label: "Promote to Resource", onClick: handlePromote },
            {
              label: "Keep in this post",
              onClick: () => dispatch("core/notices").removeNotice(noticeId),
            },
            {
              label: "Don't ask again",
              onClick: () => {
                snooze(postId, attachmentId);
                dispatch("core/notices").removeNotice(noticeId);
              },
            },
          ],
        }
      );
    });
  });
})();
