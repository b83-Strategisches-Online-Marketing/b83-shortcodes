<?php
/**
 * Deinstallation. Daten werden nur entfernt, wenn das in den Einstellungen
 * ausdrücklich aktiviert wurde – sonst bleiben die Shortcodes erhalten.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$b83_sc_settings = get_option( 'b83_shortcodes_settings', array() );

if ( is_array( $b83_sc_settings ) && ! empty( $b83_sc_settings['delete_on_uninstall'] ) ) {
	foreach ( array(
		'b83_shortcodes_entries',
		'b83_shortcodes_settings',
		'b83_shortcodes_db_version',
	) as $b83_sc_option ) {
		delete_option( $b83_sc_option );
	}
	delete_transient( 'b83_shortcodes_usage' );
	delete_transient( 'b83_shortcodes_release' );
}
