<?php
/**
 * Default template for single Resource posts.
 * Copy to your theme to override.
 *
 * @package ACO_Resource_Engine
 */

get_header();
?>

<main id="primary" class="site-main">
<?php while ( have_posts() ) : the_post();
    $attachment_id = (int) get_post_meta( get_the_ID(), '_aco_primary_attachment_id', true );
    $download_url  = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
    $summary       = (string) get_post_meta( get_the_ID(), '_aco_summary', true );
    $doc_date      = (string) get_post_meta( get_the_ID(), '_aco_document_date', true );
?>
    <article id="post-<?php the_ID(); ?>" <?php post_class('aco-resource'); ?>>
        <header class="entry-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php if ( $doc_date ) : ?>
                <p class="aco-doc-date"><strong><?php esc_html_e('Document date', 'fpm-aco-resource-engine'); ?>:</strong> <?php echo esc_html( $doc_date ); ?></p>
            <?php endif; ?>
        </header>

        <?php if ( $summary ) : ?>
            <div class="aco-summary">
                <p><?php echo esc_html( $summary ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( $download_url ) : ?>
            <p class="aco-download">
                <a class="button aco-download-button" href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Download PDF', 'fpm-aco-resource-engine' ); ?>
                </a>
            </p>
        <?php endif; ?>

        <div class="entry-content">
            <?php the_content(); ?>
        </div>

        <footer class="entry-footer">
            <?php
                $types = get_the_term_list( get_the_ID(), 'resource_type', '<span class="aco-type"><strong>' . esc_html__( 'Type', 'fpm-aco-resource-engine' ) . ':</strong> ', ', ', '</span>' );
                $tags  = get_the_term_list( get_the_ID(), 'universal_tag', '<span class="aco-tags"><strong>' . esc_html__( 'Tags', 'fpm-aco-resource-engine' ) . ':</strong> ', ', ', '</span>' );
            ?>
            <div class="aco-tax-meta">
                <?php if ( $types ) { echo wp_kses_post( $types ); } ?>
                <?php if ( $tags )  { echo wp_kses_post( $tags );  } ?>
            </div>
        </footer>
    </article>
<?php endwhile; ?>
</main>

<?php get_footer(); ?>
