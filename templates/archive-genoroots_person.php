<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

// Two or more collections: each has its own page, so list them here instead
// of mixing unrelated families in one explorer. With none or one, /mele/ is
// the explorer itself (as on Kamilari).
$genoroots_collections = GenoRoots_Collection_Taxonomy::listed();
if ( count( $genoroots_collections ) >= 2 ) :
	?>
	<main class="gnr-archive">
		<header class="gnr-archive__header">
			<h1><?php esc_html_e( 'Family Trees', 'geotour-genoroots' ); ?></h1>
		</header>
		<ul class="gnr-collections">
			<?php foreach ( $genoroots_collections as $genoroots_term ) : ?>
				<li class="gnr-collection">
					<a class="gnr-collection__link" href="<?php echo esc_url( get_term_link( $genoroots_term ) ); ?>">
						<span class="gnr-collection__name"><?php echo esc_html( $genoroots_term->name ); ?></span>
						<?php if ( $genoroots_term->description ) : ?>
							<span class="gnr-collection__description"><?php echo esc_html( $genoroots_term->description ); ?></span>
						<?php endif; ?>
						<span class="gnr-collection__count">
							<?php
							$genoroots_count = GenoRoots_Collection_Taxonomy::people_count( $genoroots_term );
							/* translators: %d: number of people */
							echo esc_html( sprintf( _n( '%d person', '%d people', $genoroots_count, 'geotour-genoroots' ), $genoroots_count ) );
							?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</main>
	<?php
	get_footer();
	return;
endif;
?>

<main class="gnr-archive">
	<header class="gnr-archive__header">
		<h1><?php esc_html_e( 'Family Tree', 'geotour-genoroots' ); ?></h1>
	</header>

	<?php require GENOROOTS_PLUGIN_DIR . 'templates/parts/app.php'; ?>

	<section class="gnr-alpha-list">
		<h2><?php esc_html_e( 'Browse alphabetically', 'geotour-genoroots' ); ?></h2>
		<ul>
			<?php
			$all_people = get_posts( array(
				'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
			foreach ( $all_people as $person ) :
				?>
				<li><a href="<?php echo esc_url( add_query_arg( 'person', $person->ID, get_post_type_archive_link( GenoRoots_Person_CPT::POST_TYPE ) ) ); ?>"><?php echo esc_html( get_the_title( $person ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</section>
</main>

<?php get_footer(); ?>
