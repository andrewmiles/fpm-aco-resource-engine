(function (wp) {
	if (
		!wp ||
		!wp.plugins ||
		!wp.editPost ||
		!wp.components ||
		!wp.data ||
		!wp.element ||
		!wp.apiFetch
	) {
		return;
	}

	const { registerPlugin } = wp.plugins;
	const { PluginMoreMenuItem } = wp.editPost;
	const { __ } = wp.i18n || ((s) => s);
	const { select, dispatch } = wp.data;
	const { createElement: el, useState } = wp.element;

	function extractAttachmentIdFromBlock(block) {
		if (!block || !block.attributes) {
			return 0;
		}
		const attrs = block.attributes;
		if (Number.isFinite(attrs.id)) {
			return parseInt(attrs.id, 10) || 0;
		}
		if (attrs.href && typeof attrs.href === 'string') {
			const match = attrs.href.match(/[?&]attachment_id=(\d+)/);
			if (match) {
				return parseInt(match[1], 10) || 0;
			}
		}
		return 0;
	}

	const PromoteMenuItem = () => {
		const [busy, setBusy] = useState(false);

		const doPromote = async () => {
			try {
				setBusy(true);
				const editorSel = select('core/editor');
				const blockSel = select('core/block-editor');

				const postId = editorSel.getCurrentPostId();
				const content = editorSel.getEditedPostContent();

				const selectedId =
					blockSel.getSelectedBlockClientId &&
					blockSel.getSelectedBlockClientId();
				const block = selectedId ? blockSel.getBlock(selectedId) : null;

				if (!block || block.name !== 'core/file') {
					dispatch('core/notices').createNotice(
						'error',
						__(
							'Select a File block linked to a PDF before running Promote.',
							'fpm-aco-resource-engine'
						),
						{ isDismissible: true }
					);
					setBusy(false);
					return;
				}

				const attachmentId = extractAttachmentIdFromBlock(block);
				if (!attachmentId) {
					dispatch('core/notices').createNotice(
						'error',
						__(
							'Could not resolve the attachment ID from the selected File block.',
							'fpm-aco-resource-engine'
						),
						{ isDismissible: true }
					);
					setBusy(false);
					return;
				}

				const res = await wp.apiFetch({
					path: '/aco/v1/promote',
					method: 'POST',
					data: {
						postId,
						attachmentId,
						postContent: content,
					},
				});

				if (res && res.patchedContent) {
					dispatch('core/editor').editPost({ content: res.patchedContent });
				}

				const link = res && res.link ? res.link : null;
				dispatch('core/notices').createNotice(
					'success',
					link
						? __(
								'Promoted to Resource. Link updated to the canonical Resource page.',
								'fpm-aco-resource-engine'
						  )
						: __('Promoted to Resource.', 'fpm-aco-resource-engine'),
					{
						isDismissible: true,
						actions: link
							? [
									{
										label: __('Open Resource', 'fpm-aco-resource-engine'),
										url: link,
									},
							  ]
							: [],
					}
				);
			} catch (e) {
				const msg =
					(e && e.message) ||
					__('Promote failed. Check console.', 'fpm-aco-resource-engine');
				dispatch('core/notices').createNotice('error', msg, {
					isDismissible: true,
				});
				// eslint-disable-next-line no-console
				console.error('[ACO Promote] Error:', e);
			} finally {
				setBusy(false);
			}
		};

		return el(
			PluginMoreMenuItem,
			{
				icon: busy ? 'update' : 'yes',
				onClick: doPromote,
				disabled: busy,
			},
			busy
				? __('Promoting…', 'fpm-aco-resource-engine')
				: __('Promote selected PDF to Resource', 'fpm-aco-resource-engine')
		);
	};

	registerPlugin('aco-promote', { render: PromoteMenuItem });
})(window.wp);
