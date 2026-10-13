<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared layout for the Import and Export pages: the form and its steps
 * side by side, then the detailed help as collapsible sections. Help text
 * may contain <code>, <strong>, <em> and <pre>; nothing else.
 */
class GenoRoots_Admin_Help {

	public static function styles() {
		?>
		<style>
			.gnr-admin { max-width: 1180px; }
			.gnr-admin__intro { font-size: 14px; max-width: 760px; }
			.gnr-admin__grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 20px; align-items: start; margin-top: 16px; }
			@media (max-width: 960px) { .gnr-admin__grid { grid-template-columns: 1fr; } }
			.gnr-admin .card { max-width: none; margin: 0; padding: 16px 20px; }
			.gnr-admin .card h2 { margin-top: 0; }
			.gnr-admin__steps { margin: 0 0 0 1.2em; }
			.gnr-admin__steps li { margin-bottom: 8px; }
			.gnr-admin__help { margin-top: 28px; }
			.gnr-admin__help details { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 8px; }
			.gnr-admin__help summary { cursor: pointer; padding: 12px 16px; font-weight: 600; font-size: 14px; }
			.gnr-admin__help details[open] summary { border-bottom: 1px solid #dcdcde; }
			.gnr-admin__help .gnr-admin__body { padding: 4px 16px 12px; max-width: 860px; }
			.gnr-admin__help pre { background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px 12px; overflow-x: auto; }
			.gnr-admin__help table { margin: 8px 0; }
			.gnr-admin__report { margin-top: 20px; }
			.gnr-admin__report .gnr-admin__ok { color: #007017; }
			.gnr-admin__new-collection { margin-top: 10px; }
			.gnr-admin__new-collection[hidden] { display: none; }
			.gnr-admin__new-collection label { display: block; margin: 6px 0 2px; font-weight: 600; }
		</style>
		<?php
	}

	/** Numbered steps. @param string[] $steps */
	public static function steps( array $steps ) {
		echo '<ol class="gnr-admin__steps">';
		foreach ( $steps as $step ) {
			echo '<li>' . self::kses( $step ) . '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Collapsible help sections.
	 *
	 * @param array<int, array{title: string, body: array<int, string|array>}> $sections
	 *        Body items: a paragraph string, ['pre' => text], ['list' => string[]]
	 *        or ['table' => [head[], rows[][]]].
	 */
	public static function sections( $heading, array $sections ) {
		echo '<div class="gnr-admin__help"><h2>' . esc_html( $heading ) . '</h2>';
		foreach ( $sections as $section ) {
			echo '<details><summary>' . esc_html( $section['title'] ) . '</summary><div class="gnr-admin__body">';
			foreach ( $section['body'] as $item ) {
				if ( is_string( $item ) ) {
					echo '<p>' . self::kses( $item ) . '</p>';
				} elseif ( isset( $item['pre'] ) ) {
					echo '<pre>' . esc_html( $item['pre'] ) . '</pre>';
				} elseif ( isset( $item['list'] ) ) {
					echo '<ul class="ul-disc">';
					foreach ( $item['list'] as $li ) {
						echo '<li>' . self::kses( $li ) . '</li>';
					}
					echo '</ul>';
				} elseif ( isset( $item['table'] ) ) {
					list( $head, $rows ) = $item['table'];
					echo '<table class="widefat striped"><thead><tr>';
					foreach ( $head as $cell ) {
						echo '<th>' . esc_html( $cell ) . '</th>';
					}
					echo '</tr></thead><tbody>';
					foreach ( $rows as $row ) {
						echo '<tr>';
						foreach ( $row as $cell ) {
							echo '<td>' . self::kses( $cell ) . '</td>';
						}
						echo '</tr>';
					}
					echo '</tbody></table>';
				}
			}
			echo '</div></details>';
		}
		echo '</div>';
	}

	public static function kses( $html ) {
		return wp_kses( $html, array(
			'code'   => array(),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		) );
	}
}
