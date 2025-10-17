<?php
/**
 * Default single template for the Resource post type.
 *
 * @package FPM_ACO_Resource_Engine
 */

get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();

		$attachment_id = (int) get_post_meta( get_the_ID(), '_aco_primary_attachment_id', true );
		$file_url      = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		?>
		<main id="primary" class="site-main aco-resource-single">
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<header class="entry-header">
					<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
					<?php if ( $file_url ) : ?>
						<p class="aco-resource-download-wrapper">
							<a class="button aco-resource-download" href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download PDF', 'fpm-aco-resource-engine' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</header>

				<div class="entry-content">
					<?php the_content(); ?>

					<?php if ( $file_url ) : ?>
						<div class="aco-resource-viewer" style="margin-top:2rem;">
							<iframe
								src="<?php echo esc_url( $file_url ); ?>#view=FitH"
								width="100%"
								height="800"
								style="border:0;"
								title="<?php echo esc_attr( get_the_title() ); ?>"
							></iframe>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'This resource does not have a downloadable file attached yet.', 'fpm-aco-resource-engine' ); ?></p>
					<?php endif; ?>
				</div>
			</article>
		</main>
		<?php
	endwhile;
endif;

get_footer();
