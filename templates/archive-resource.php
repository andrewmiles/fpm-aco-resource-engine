<?php
/**
 * Fallback archive template for the "resource" post type.
 * Renders facets (Type, Year, Tags), sorting, and a clean list layout.
 *
 * To override in a theme: copy to your theme as archive-resource.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$current_type = isset( $_GET['type'] ) ? (string) wp_unslash( $_GET['type'] ) : '';
$current_tag  = isset( $_GET['tag'] )  ? (string) wp_unslash( $_GET['tag'] )  : '';
$current_year = isset( $_GET['year'] ) ? (string) wp_unslash( $_GET['year'] ) : '';
$current_sort = isset( $_GET['sort'] ) ? sanitize_key( (string) $_GET['sort'] ) : 'newest';
$current_s    = isset( $_GET['s'] )    ? (string) wp_unslash( $_GET['s'] )    : '';

$types = get_terms(
	[
		'taxonomy'   => 'resource_type',
		'hide_empty' => true,
	]
);
$tags  = get_terms(
	[
		'taxonomy'   => 'universal_tag',
		'hide_empty' => true,
		'number'     => 30,
	]
);

$years = function_exists( 'aco_re_get_available_resource_years' )
	? aco_re_get_available_resource_years()
	: [];
?>
<style>
.aco-archive { max-width: var(--wp--style--global--content-size, 1100px); margin: 2rem auto; padding: 0 1rem; }
.aco-filters { display: grid; grid-template-columns: 1fr; gap: .75rem; margin-bottom: 1.25rem; }
@media (min-width: 640px) { .aco-filters { grid-template-columns: 2fr repeat(3, 1fr) 1fr; align-items: end; } }
.aco-filters .field { display: flex; flex-direction: column; gap: .25rem; }
.aco-filters label { font-weight: 600; font-size: .9rem; }
.aco-filters select, .aco-filters input[type="search"] { width: 100%; padding: .5rem .6rem; }
.aco-filters .actions { display: flex; gap: .5rem; }
.aco-filters .actions button { padding: .55rem .9rem; cursor: pointer; }
.aco-tagcloud { display: flex; flex-wrap: wrap; gap: .5rem; margin: .25rem 0 1rem; }
.aco-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .5rem; border:1px solid #ddd; border-radius: 999px; text-decoration:none; font-size:.85rem; }
.aco-chip.is-active { background:#f1f5f9; border-color:#cbd5e1; }
.aco-results { display:grid; grid-template-columns: 1fr; gap: 1rem; }
@media (min-width: 700px) { .aco-results { grid-template-columns: 1fr 1fr; } }
.aco-card { border: 1px solid #e5e7eb; border-radius: .5rem; padding: 1rem; background: #fff; }
.aco-card h3 { margin: 0 0 .5rem; font-size: 1.1rem; }
.aco-meta { display:flex; flex-wrap: wrap; gap:.5rem; margin:.5rem 0 .75rem; }
.aco-meta .pill { font-size:.78rem; padding:.2rem .45rem; border-radius:999px; background:#f8fafc; border:1px solid #e2e8f0; }
.aco-card .actions { margin-top:.75rem; display:flex; gap:.5rem; flex-wrap: wrap; }
.aco-empty { padding:2rem; text-align:center; border:1px dashed #e5e7eb; border-radius:.5rem; background:#fafafa; }
</style>

<main class="aco-archive" id="primary">
	<header class="page-header">
		<h1 class="page-title"><?php echo esc_html( post_type_archive_title( '', false ) ?: __( 'Resources', 'fpm-aco-resource-engine' ) ); ?></h1>
	</header>

	<form class="aco-filters" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'resource' ) ); ?>">
		<div class="field">
			<label for="aco_q"><?php esc_html_e( 'Search', 'fpm-aco-resource-engine' ); ?></label>
			<input type="search" id="aco_q" name="s" value="<?php echo esc_attr( $current_s ); ?>" placeholder="<?php esc_attr_e( 'Search resources…', 'fpm-aco-resource-engine' ); ?>" />
		</div>
		<div class="field">
			<label for="aco_type"><?php esc_html_e( 'Type', 'fpm-aco-resource-engine' ); ?></label>
			<select id="aco_type" name="type">
				<option value=""><?php esc_html_e( 'All types', 'fpm-aco-resource-engine' ); ?></option>
				<?php if ( ! empty( $types ) && ! is_wp_error( $types ) ) : ?>
					<?php foreach ( $types as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $current_type, $t->slug ); ?>>
							<?php echo esc_html( $t->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
		</div>
		<div class="field">
			<label for="aco_year"><?php esc_html_e( 'Year', 'fpm-aco-resource-engine' ); ?></label>
			<select id="aco_year" name="year">
				<option value=""><?php esc_html_e( 'All years', 'fpm-aco-resource-engine' ); ?></option>
				<?php foreach ( $years as $y ) : ?>
					<option value="<?php echo esc_attr( $y ); ?>" <?php selected( $current_year, $y ); ?>><?php echo esc_html( $y ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="field">
			<label for="aco_sort"><?php esc_html_e( 'Sort', 'fpm-aco-resource-engine' ); ?></label>
			<select id="aco_sort" name="sort">
				<option value="newest" <?php selected( $current_sort, 'newest' ); ?>><?php esc_html_e( 'Newest', 'fpm-aco-resource-engine' ); ?></option>
				<option value="oldest" <?php selected( $current_sort, 'oldest' ); ?>><?php esc_html_e( 'Oldest', 'fpm-aco-resource-engine' ); ?></option>
				<option value="title"  <?php selected( $current_sort, 'title' );  ?>><?php esc_html_e( 'Title A–Z', 'fpm-aco-resource-engine' ); ?></option>
			</select>
		</div>
		<div class="actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'fpm-aco-resource-engine' ); ?></button>
			<a class="button" href="<?php echo esc_url( get_post_type_archive_link( 'resource' ) ); ?>"><?php esc_html_e( 'Reset', 'fpm-aco-resource-engine' ); ?></a>
		</div>
	</form>

	<?php if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) : ?>
		<div class="aco-tagcloud" aria-label="<?php esc_attr_e( 'Filter by tag', 'fpm-aco-resource-engine' ); ?>">
			<?php
			$active = array_filter( array_map( 'sanitize_title', explode( ',', $current_tag ) ) );
			foreach ( $tags as $tg ) :
				$is        = in_array( $tg->slug, $active, true );
				$new_active = $is
					? array_values( array_diff( $active, [ $tg->slug ] ) )
					: array_values( array_unique( array_filter( array_merge( $active, [ $tg->slug ] ) ) ) );
				$link = add_query_arg(
					[
						'tag' => implode( ',', $new_active ),
					],
					remove_query_arg( 'paged' )
				);
				?>
				<a class="aco-chip <?php echo $is ? 'is-active' : ''; ?>"
				   href="<?php echo esc_url( $link ); ?>"
				   aria-pressed="<?php echo $is ? 'true' : 'false'; ?>">
					<span>#</span><?php echo esc_html( $tg->name ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="aco-results">
			<?php
			while ( have_posts() ) :
				the_post();
				$rid    = get_the_ID();
				$sum    = (string) get_post_meta( $rid, '_aco_summary', true );
				$doc    = (string) get_post_meta( $rid, '_aco_document_date', true );
				$typesT = wp_get_post_terms( $rid, 'resource_type', [ 'fields' => 'names' ] );
				$tagsT  = wp_get_post_terms( $rid, 'universal_tag', [ 'fields' => 'names' ] );
				$att_id = (int) get_post_meta( $rid, '_aco_primary_attachment_id', true );
				$dl     = $att_id ? wp_get_attachment_url( $att_id ) : '';
				?>
				<article <?php post_class( 'aco-card' ); ?>>
					<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

					<div class="aco-meta">
						<?php if ( $doc ) : ?>
							<span class="pill"><?php echo esc_html( $doc ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $typesT ) && ! is_wp_error( $typesT ) ) : ?>
							<?php foreach ( (array) $typesT as $label ) : ?>
								<span class="pill"><?php echo esc_html( $label ); ?></span>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<div class="entry-summary">
						<?php
						if ( '' !== $sum ) {
							echo wp_kses_post( wpautop( esc_html( $sum ) ) );
						} else {
							the_excerpt();
						}
						?>
					</div>

					<div class="aco-meta">
						<?php if ( ! empty( $tagsT ) && ! is_wp_error( $tagsT ) ) : ?>
							<?php foreach ( (array) $tagsT as $label ) : ?>
								<span class="pill">#<?php echo esc_html( $label ); ?></span>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<div class="actions">
						<a class="button" href="<?php the_permalink(); ?>"><?php esc_html_e( 'View details', 'fpm-aco-resource-engine' ); ?></a>
						<?php if ( $dl ) : ?>
							<a class="button" href="<?php echo esc_url( $dl ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download PDF', 'fpm-aco-resource-engine' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endwhile; ?>
		</div>

		<nav class="navigation pagination" role="navigation">
			<?php
			the_posts_pagination(
				[
					'mid_size'  => 1,
					'prev_text' => __( 'Previous', 'fpm-aco-resource-engine' ),
					'next_text' => __( 'Next', 'fpm-aco-resource-engine' ),
				]
			);
			?>
		</nav>
	<?php else : ?>
		<div class="aco-empty">
			<p><?php esc_html_e( 'No resources matched your filters. Try clearing one or more filters above.', 'fpm-aco-resource-engine' ); ?></p>
		</div>
	<?php endif; ?>
</main>

<?php get_footer(); ?>
