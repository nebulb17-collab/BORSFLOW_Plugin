<?php
/**
 * The `borsflow_form` custom post type.
 *
 * Why a CPT instead of a dedicated table: forms are few, edited rarely and read
 * by ID, which is exactly what posts are good at. We get titles, status
 * (publish = enabled, draft = disabled), authorship, dates, WXR export/import
 * and object caching for free. The builder schema is one JSON blob in post meta
 * because it is always loaded and saved as a whole. Submissions, which are
 * numerous and filtered/sorted, live in their own table.
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the form post type.
 */
class BorsFlow_Post_Type {

	const POST_TYPE = 'borsflow_form';

	/**
	 * Register the post type (idempotent).
	 */
	public static function register() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		$cap = BorsFlow_Plugin::CAP;
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Forms', 'borsflow-forms' ),
					'singular_name' => __( 'Form', 'borsflow-forms' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'can_export'      => true,
				'map_meta_cap'    => false,
				'capabilities'    => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'edit_published_posts'   => $cap,
				),
			)
		);
	}
}
