<?php
/**
 * Template: Single Resource
 * Minimal viewer + download for the Resource CPT.
 */

get_header();

while ( have_posts() ) :
	the_post();

	$att_id  = (int) get_post_meta( get_the_ID(), '_aco_primary_attachment_id', true );
	$pdf_url = $att_id ? wp_get_attachment_url( $att_id ) : '';
	?>
	<main id="primary" class="site-main">
		<article <?php post_class(); ?> style="max-width:900px;margin:0 auto;padding:2rem 1rem;">
			<header class="entry-header">
				<?php the_title( '<h1 class="entry-title" style="margin-bottom:1rem;">', '</h1>' ); ?>
			</header>

			<div class="entry-content">
				<?php if ( $pdf_url ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( $pdf_url ); ?>" download>
							<?php esc_html_e( 'Download PDF', 'fpm-aco-resource-engine' ); ?>
						</a>
					</p>

					<object data="<?php echo esc_url( $pdf_url ); ?>" type="application/pdf" width="100%" height="800">
						<iframe src="<?php echo esc_url( $pdf_url ); ?>" width="100%" height="800" style="border:0;"></iframe>
					</object>
				<?php else : ?>
					<p><?php esc_html_e( 'File not available.', 'fpm-aco-resource-engine' ); ?></p>
				<?php endif; ?>

				<?php the_content(); ?>
			</div>
		</article>
	</main>
	<?php
endwhile;

get_footer();
