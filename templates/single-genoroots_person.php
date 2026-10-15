<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$person_id      = get_the_ID();
$name           = get_the_title( $person_id );
$name_latin     = get_field( 'name_latin', $person_id );
$nickname       = (string) get_field( 'nickname', $person_id );
$father_name    = (string) get_field( 'father_name', $person_id );
$birth_date     = GenoRoots_Dates::format( get_field( 'birth_date_raw', $person_id ) );
$birth_place    = get_field( 'birth_place', $person_id );
$birth_place_en = get_field( 'birth_place_en', $person_id );
$death_date     = GenoRoots_Dates::format( get_field( 'death_date_raw', $person_id ) );
$death_place    = get_field( 'death_place', $person_id );
$death_place_en = get_field( 'death_place_en', $person_id );
$notes          = get_field( 'notes', $person_id ); // formatted (paragraphs)
$photos_url     = get_field( 'photo_archive_url', $person_id );
$photo_count    = (int) get_field( 'piwigo_photo_count', $person_id );
$related        = GenoRoots_Related::of_person( $person_id );
$burial_place   = get_field( 'burial_place', $person_id );
$period         = get_field( 'period', $person_id );
$sources        = get_field( 'sources', $person_id ); // formatted (line breaks)

// How sure the links are (family "Certainty"): the birth family for the
// parents, the least certain marriage for the spouses.
$certainty_label = static function ( $status ) {
	switch ( $status ) {
		case 'probable':
			return __( 'probable', 'geotour-genoroots' );
		case 'claimed':
			return __( 'claimed by family tradition', 'geotour-genoroots' );
	}
	return '';
};
$birth_family   = (int) get_post_meta( $person_id, '_famc_family_id', true );
$parents_note   = $birth_family ? $certainty_label( get_field( 'status', $birth_family ) ) : '';
$spouses_note   = '';
foreach ( array_map( 'intval', (array) get_post_meta( $person_id, '_fams_family_id' ) ) as $marriage ) {
	$spouses_note = $spouses_note ?: $certainty_label( get_field( 'status', $marriage ) );
}

$parents  = GenoRoots_Relatives::get_parents( $person_id );
$spouses  = GenoRoots_Relatives::get_spouses( $person_id );
$children = GenoRoots_Relatives::get_children( $person_id );

$relative_group = static function ( $title, array $ids, $note = '' ) {
	if ( ! $ids ) {
		return;
	}
	?>
	<div class="gnr-person__relatives-group">
		<h2><?php echo esc_html( $title ); ?></h2>
		<?php if ( $note ) : ?>
			<p class="gnr-person__certainty"><?php echo esc_html( $note ); ?></p>
		<?php endif; ?>
		<ul>
			<?php foreach ( $ids as $id ) : ?>
				<li><a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
};
?>

<main class="gnr-person">
	<header class="gnr-person__header">
		<?php if ( has_post_thumbnail( $person_id ) ) : ?>
			<?php echo get_the_post_thumbnail( $person_id, 'medium', array( 'class' => 'gnr-person__portrait', 'alt' => $name ) ); ?>
		<?php endif; ?>
		<div class="gnr-person__heading">
			<h1 class="gnr-person__name"><?php echo esc_html( $name ); ?></h1>
			<?php if ( $nickname || $father_name ) : ?>
				<p class="gnr-person__also">
					<?php
					/* translators: %s: a nickname, shown in quotes */
					echo esc_html( implode( ' · ', array_filter( array( $nickname ? sprintf( _x( '“%s”', 'nickname', 'geotour-genoroots' ), $nickname ) : '', $father_name ) ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $name_latin ) : ?>
				<p class="gnr-person__name-latin"><?php echo esc_html( $name_latin ); ?></p>
			<?php endif; ?>
			<?php if ( $photos_url ) : ?>
				<a class="gnr-person__photos" href="<?php echo esc_url( $photos_url ); ?>" target="_blank" rel="noopener">
					<?php
					echo esc_html( $photo_count
						/* translators: %d: number of photos */
						? sprintf( __( 'Photos in the archive (%d)', 'geotour-genoroots' ), $photo_count )
						: __( 'Photos in the archive', 'geotour-genoroots' ) );
					?>
					<span aria-hidden="true">↗</span>
				</a>
			<?php endif; ?>
			<?php if ( $related ) : ?>
				<a class="gnr-person__related" href="<?php echo esc_url( $related['url'] ); ?>">
					<?php echo esc_html( $related['title'] ); ?> <span aria-hidden="true">→</span>
				</a>
			<?php endif; ?>
			<?php $edit_url = GenoRoots_Field_Editor::edit_url(); ?>
			<?php if ( $edit_url ) : ?>
				<a class="gnr-person__related gnr-person__edit" href="<?php echo esc_url( $edit_url . $person_id ); ?>">
					<span aria-hidden="true">✎</span> <?php esc_html_e( 'Edit', 'geotour-genoroots' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( $birth_date || $birth_place || $death_date || $death_place || $burial_place || $period ) : ?>
		<dl class="gnr-person__facts">
			<?php if ( $birth_date || $birth_place ) : ?>
				<div class="gnr-person__fact">
					<dt><?php esc_html_e( 'Born', 'geotour-genoroots' ); ?></dt>
					<dd>
						<?php echo esc_html( trim( $birth_date . ( $birth_place ? ' — ' . $birth_place : '' ) ) ); ?>
						<?php if ( $birth_place_en ) : ?><span class="gnr-person__fact-en">(<?php echo esc_html( $birth_place_en ); ?>)</span><?php endif; ?>
					</dd>
				</div>
			<?php endif; ?>
			<?php if ( $death_date || $death_place ) : ?>
				<div class="gnr-person__fact">
					<dt><?php esc_html_e( 'Died', 'geotour-genoroots' ); ?></dt>
					<dd>
						<?php echo esc_html( trim( $death_date . ( $death_place ? ' — ' . $death_place : '' ) ) ); ?>
						<?php if ( $death_place_en ) : ?><span class="gnr-person__fact-en">(<?php echo esc_html( $death_place_en ); ?>)</span><?php endif; ?>
					</dd>
				</div>
			<?php endif; ?>
			<?php if ( $burial_place ) : ?>
				<div class="gnr-person__fact">
					<dt><?php esc_html_e( 'Buried', 'geotour-genoroots' ); ?></dt>
					<dd><?php echo esc_html( $burial_place ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( $period ) : ?>
				<div class="gnr-person__fact">
					<dt><?php esc_html_e( 'Period', 'geotour-genoroots' ); ?></dt>
					<dd><?php echo esc_html( $period ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
	<?php endif; ?>

	<?php if ( $notes ) : ?>
		<section class="gnr-person__notes">
			<?php echo wp_kses_post( $notes ); ?>
		</section>
	<?php endif; ?>

	<?php if ( $sources ) : ?>
		<section class="gnr-person__sources">
			<h2><?php esc_html_e( 'Sources', 'geotour-genoroots' ); ?></h2>
			<p><?php echo wp_kses( $sources, array( 'br' => array() ) ); ?></p>
		</section>
	<?php endif; ?>

	<?php if ( $parents || $spouses || $children ) : ?>
		<section class="gnr-person__relatives">
			<?php
			$relative_group( __( 'Parents', 'geotour-genoroots' ), $parents, $parents_note );
			$relative_group( __( 'Spouse', 'geotour-genoroots' ), $spouses, $spouses_note );
			$relative_group( __( 'Children', 'geotour-genoroots' ), $children );
			?>
		</section>
	<?php endif; ?>

	<section class="gnr-person__widget">
		<h2><?php esc_html_e( 'Family tree', 'geotour-genoroots' ); ?></h2>
		<div class="gnr-focus-chart-wrap">
			<div
				class="gnr-focus-chart"
				data-person-id="<?php echo esc_attr( $person_id ); ?>"
				data-up="1"
				data-down="1"
			></div>
		</div>
	</section>
</main>

<?php get_footer(); ?>
