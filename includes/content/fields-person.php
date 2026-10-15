<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Person fields, laid out for editing: short rows, translated labels.
 * Registered on acf/init so the labels are translated (the text domain
 * loads on init). The last four are filled in by imports and the Piwigo
 * sync; they are hidden here and shown read-only in the "Person details"
 * side box instead.
 */
add_action( 'acf/init', function () {

	$genoroots_row = function ( $key, $label, $name, $width, $extra = array() ) {
		return array_merge( array(
			'key'     => 'field_genoroots_person_' . $key,
			'label'   => $label,
			'name'    => $name,
			'type'    => 'text',
			'wrapper' => array( 'width' => $width ),
		), $extra );
	};
	$genoroots_section = function ( $key, $label ) {
		return array(
			'key'          => 'field_genoroots_person_section_' . $key,
			'label'        => $label,
			'name'         => '',
			'type'         => 'accordion',
			'open'         => 1,
			'multi_expand' => 1,
			'endpoint'     => 0,
		);
	};
	$genoroots_hidden = array( 'class' => 'gnr-acf-hidden' );

	acf_add_local_field_group( array(
		'key'      => 'group_genoroots_person',
		'title'    => __( 'Details', 'geotour-genoroots' ),
		'position' => 'acf_after_title',
		'fields'   => array(
			array(
				'key'           => 'field_genoroots_person_sex',
				'label'         => __( 'Sex', 'geotour-genoroots' ),
				'name'          => 'sex',
				'type'          => 'select',
				'choices'       => array(
					'M' => __( 'Male', 'geotour-genoroots' ),
					'F' => __( 'Female', 'geotour-genoroots' ),
					'U' => __( 'Unknown', 'geotour-genoroots' ),
				),
				'default_value' => 'U',
				'return_format' => 'value',
				'wrapper'       => array( 'width' => 25 ),
			),
			$genoroots_row( 'name_latin', __( 'Name in Latin characters', 'geotour-genoroots' ), 'name_latin', 75, array(
				'placeholder' => __( 'e.g. Manolis Charkiotakis — for the English version', 'geotour-genoroots' ),
			) ),
			$genoroots_row( 'father_name', __( 'Father’s name', 'geotour-genoroots' ), 'father_name', 50, array(
				'instructions' => __( 'As it is said in the village, e.g. “του Γεωργίου”. Tells apart people with the same name.', 'geotour-genoroots' ),
			) ),
			$genoroots_row( 'nickname', __( 'Nickname', 'geotour-genoroots' ), 'nickname', 50, array(
				'instructions' => __( 'The person’s or family’s nickname.', 'geotour-genoroots' ),
			) ),

			$genoroots_section( 'birth', __( 'Birth', 'geotour-genoroots' ) ),
			$genoroots_row( 'birth_date_raw', __( 'Date', 'geotour-genoroots' ), 'birth_date_raw', 30, array(
				'placeholder' => __( 'e.g. 1908 or December 1936', 'geotour-genoroots' ),
			) ),
			$genoroots_row( 'birth_place', __( 'Place', 'geotour-genoroots' ), 'birth_place', 35 ),
			$genoroots_row( 'birth_place_en', __( 'Place (English)', 'geotour-genoroots' ), 'birth_place_en', 35 ),

			$genoroots_section( 'death', __( 'Death', 'geotour-genoroots' ) ),
			$genoroots_row( 'death_date_raw', __( 'Date', 'geotour-genoroots' ), 'death_date_raw', 30 ),
			$genoroots_row( 'death_place', __( 'Place', 'geotour-genoroots' ), 'death_place', 35 ),
			$genoroots_row( 'death_place_en', __( 'Place (English)', 'geotour-genoroots' ), 'death_place_en', 35 ),
			$genoroots_row( 'burial_place', __( 'Burial place', 'geotour-genoroots' ), 'burial_place', 100 ),

			array(
				'key'      => 'field_genoroots_person_section_end',
				'label'    => '',
				'name'     => '',
				'type'     => 'accordion',
				'endpoint' => 1,
			),
			array(
				'key'       => 'field_genoroots_person_notes',
				'label'     => __( 'Notes', 'geotour-genoroots' ),
				'name'      => 'notes',
				'type'      => 'textarea',
				'rows'      => 5,
				'new_lines' => 'wpautop',
			),
			$genoroots_row( 'period', __( 'Historical period', 'geotour-genoroots' ), 'period', 100, array(
				'placeholder' => __( 'e.g. Cretan State / Interwar Republic', 'geotour-genoroots' ),
			) ),
			array(
				'key'          => 'field_genoroots_person_sources',
				'label'        => __( 'Sources', 'geotour-genoroots' ),
				'name'         => 'sources',
				'type'         => 'textarea',
				'rows'         => 3,
				'new_lines'    => 'br',
				'instructions' => __( 'Where the information comes from: documents, books, archives, testimonies. One per line.', 'geotour-genoroots' ),
			),
			array(
				'key'           => 'field_genoroots_person_related_post',
				'label'         => __( 'Related article', 'geotour-genoroots' ),
				'name'          => 'related_post',
				'type'          => 'post_object',
				'instructions'  => __( 'A page on this site about this person, e.g. a biography. Its link follows the language of the page.', 'geotour-genoroots' ),
				'post_type'     => array(), // narrowed by GenoRoots_Related::limit_choices()
				'return_format' => 'id',
				'allow_null'    => 1,
				'multiple'      => 0,
				'ui'            => 1,
			),

			// Managed by imports / the Piwigo sync; shown in the side box.
			array(
				'key'      => 'field_genoroots_person_gedcom_id',
				'label'    => 'GenoRoots ID',
				'name'     => 'gedcom_id',
				'type'     => 'text',
				'readonly' => 1,
				'wrapper'  => $genoroots_hidden,
			),
			array(
				'key'     => 'field_genoroots_person_piwigo_tag_id',
				'label'   => 'Piwigo tag ID',
				'name'    => 'piwigo_tag_id',
				'type'    => 'number',
				'wrapper' => $genoroots_hidden,
			),
			array(
				'key'      => 'field_genoroots_person_piwigo_photo_count',
				'label'    => 'Photos in Piwigo',
				'name'     => 'piwigo_photo_count',
				'type'     => 'number',
				'readonly' => 1,
				'wrapper'  => $genoroots_hidden,
			),
			array(
				'key'     => 'field_genoroots_person_photo_archive_url',
				'label'   => 'Photo archive link',
				'name'    => 'photo_archive_url',
				'type'    => 'url',
				'wrapper' => $genoroots_hidden,
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'genoroots_person',
				),
			),
		),
	) );

} );
