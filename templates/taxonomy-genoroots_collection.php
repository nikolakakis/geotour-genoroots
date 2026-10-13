<?php
/**
 * One collection's page (e.g. /syllogi/venizelos/): the groups explorer,
 * tree and alphabetical list, limited to that collection's people.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$genoroots_term       = get_queried_object();
$genoroots_collection = urldecode( $genoroots_term->slug );
?>

<main class="gnr-archive">
	<header class="gnr-archive__header">
		<h1><?php echo esc_html( $genoroots_term->name ); ?></h1>
		<?php if ( $genoroots_term->description ) : ?>
			<p class="gnr-archive__intro"><?php echo esc_html( $genoroots_term->description ); ?></p>
		<?php endif; ?>
	</header>

	<?php require GENOROOTS_PLUGIN_DIR . 'templates/parts/app.php'; ?>

	<section class="gnr-alpha-list">
		<h2><?php esc_html_e( 'Browse alphabetically', 'geotour-genoroots' ); ?></h2>
		<ul>
			<?php
			$genoroots_people = get_posts( array(
				'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array( GenoRoots_Collection_Taxonomy::tax_clause( $genoroots_collection ) ), // phpcs:ignore
			) );
			$genoroots_page = get_term_link( $genoroots_term );
			foreach ( $genoroots_people as $genoroots_person ) :
				?>
				<li><a href="<?php echo esc_url( add_query_arg( 'person', $genoroots_person->ID, $genoroots_page ) ); ?>"><?php echo esc_html( get_the_title( $genoroots_person ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</section>
</main>

<?php get_footer(); ?>
