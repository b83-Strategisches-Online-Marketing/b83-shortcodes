<?php
/**
 * Plugin Name:       b83 Shortcodes
 * Plugin URI:        https://b83.at
 * Description:       Eigene Shortcodes mit HTML, CSS und JavaScript verwalten – zum Einbinden von Scripts, Widgets und Bausteinen in Elementor, im Block-Editor oder in Widgets. Keine PHP-Ausführung.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            b83 – Wolfgang Grabs-Schrempf
 * Author URI:        https://b83.at
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       b83-shortcodes
 * Domain Path:       /languages
 * Update URI:        https://github.com/b83-Strategisches-Online-Marketing/b83-shortcodes
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'B83_SC_VERSION', '2.0.0' );
define( 'B83_SC_FILE', __FILE__ );
define( 'B83_SC_DIR', plugin_dir_path( __FILE__ ) );
define( 'B83_SC_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4-Autoloader für den Namespace B83\Shortcodes → src/.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'B83\\Shortcodes\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = B83_SC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \B83\Shortcodes\Migration::class, 'on_activation' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\B83\Shortcodes\Plugin::instance()->boot();
	}
);
