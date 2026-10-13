<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'acf_add_local_field_group' ) ) :

	acf_add_local_field_group( array(
		'key'      => 'group_genoroots_family',
		'title'    => 'Family Details',
		'fields'   => array(
			array(
				'key'           => 'field_genoroots_family_spouse1',
				'label'         => 'Spouse 1',
				'name'          => 'spouse1',
				'type'          => 'post_object',
				'post_type'     => array( 'genoroots_person' ),
				'return_format' => 'id',
				'ui'            => 1,
				'instructions'  => 'Optional — one known parent (blank husband or wife) is a valid state in the source data.',
			),
			array(
				'key'           => 'field_genoroots_family_spouse2',
				'label'         => 'Spouse 2',
				'name'          => 'spouse2',
				'type'          => 'post_object',
				'post_type'     => array( 'genoroots_person' ),
				'return_format' => 'id',
				'ui'            => 1,
			),
			array(
				'key'           => 'field_genoroots_family_children',
				'label'         => 'Children',
				'name'          => 'children',
				'type'          => 'relationship',
				'post_type'     => array( 'genoroots_person' ),
				'return_format' => 'id',
			),
			array(
				'key'   => 'field_genoroots_family_marriage_date_raw',
				'label' => 'Marriage date',
				'name'  => 'marriage_date_raw',
				'type'  => 'text',
			),
			array(
				'key'   => 'field_genoroots_family_marriage_place',
				'label' => 'Marriage place',
				'name'  => 'marriage_place',
				'type'  => 'text',
			),
			array(
				'key'   => 'field_genoroots_family_marriage_place_en',
				'label' => 'Marriage place (English)',
				'name'  => 'marriage_place_en',
				'type'  => 'text',
			),
			array(
				'key'           => 'field_genoroots_family_status',
				'label'         => 'Certainty',
				'name'          => 'status',
				'type'          => 'select',
				'choices'       => array(
					'confirmed' => 'Confirmed by sources',
					'probable'  => 'Probable',
					'claimed'   => 'Claimed (family tradition)',
				),
				'default_value' => 'confirmed',
				'return_format' => 'value',
				'instructions'  => 'How sure the link between these parents and children (or this marriage) is.',
			),
			array(
				'key'          => 'field_genoroots_family_gedcom_id',
				'label'        => 'GEDCOM ID',
				'name'         => 'gedcom_id',
				'type'         => 'text',
				'instructions' => 'Original GEDCOM xref (e.g. F949). Used to match this record on re-import — do not edit.',
				'readonly'     => 1,
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'genoroots_family',
				),
			),
		),
	) );

endif;
