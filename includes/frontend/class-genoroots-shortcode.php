<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [genoroots_tree person="" group="" up="2" down="2"] — embeds the same
 * focus-chart component used on the archive page anywhere else on the
 * site. Unlike the archive page's instance, a shortcode instance never
 * touches the browser's URL (data-drives-url is left unset) — it just
 * re-centers itself on click and offers a "view in full tree" link for
 * anyone who wants the shareable, deep-linkable version.
 */
class GenoRoots_Shortcode {

	private static $instance_count = 0;
	private static $app_rendered   = false;

	public static function init() {
		add_shortcode( 'genoroots_tree', array( __CLASS__, 'render' ) );
		add_shortcode( 'genoroots_app', array( __CLASS__, 'render_app' ) );
	}

	/**
	 * [genoroots_app collection=""] — the full groups explorer + tree (the
	 * /mele/ page) inside any page, so a site can place it where it likes;
	 * collection="slug" limits it to one collection. It owns the page URL
	 * (?groups=, ?person=), so only one per page.
	 */
	public static function render_app( $atts = array() ) {
		$atts                 = shortcode_atts( array( 'collection' => '' ), $atts, 'genoroots_app' );
		$genoroots_collection = sanitize_text_field( $atts['collection'] );
		if ( self::$app_rendered ) {
			return '';
		}
		self::$app_rendered = true;
		GenoRoots_Frontend::enqueue();

		ob_start();
		echo '<div class="gnr-archive gnr-archive--embedded">';
		require GENOROOTS_PLUGIN_DIR . 'templates/parts/app.php';
		echo '</div>';
		return ob_get_clean();
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'person' => '',
			'group'  => '',
			'up'     => 2,
			'down'   => 2,
		), $atts, 'genoroots_tree' );

		GenoRoots_Frontend::enqueue();

		self::$instance_count++;
		$mount_id = 'genoroots-shortcode-' . self::$instance_count;

		ob_start();
		?>
		<div class="gnr-focus-chart-wrap">
			<div
				id="<?php echo esc_attr( $mount_id ); ?>"
				class="gnr-focus-chart"
				data-person-id="<?php echo esc_attr( $atts['person'] ); ?>"
				data-group-slug="<?php echo esc_attr( $atts['group'] ); ?>"
				data-up="<?php echo esc_attr( (int) $atts['up'] ); ?>"
				data-down="<?php echo esc_attr( (int) $atts['down'] ); ?>"
			></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
