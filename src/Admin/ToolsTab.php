<?php
/**
 * Reiter „Import/Export & Einstellungen“.
 *
 * Export/Import als JSON – praktisch, um bewährte Shortcodes auf weitere
 * Websites zu übertragen oder vor größeren Änderungen eine Sicherung zu ziehen.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes\Admin;

use B83\Shortcodes\Capabilities;
use B83\Shortcodes\Integrations\CachePurge;
use B83\Shortcodes\Migration;
use B83\Shortcodes\Repository;

defined( 'ABSPATH' ) || exit;

final class ToolsTab {

	private const MAX_UPLOAD = 2 * MB_IN_BYTES;

	public function __construct( private Repository $repository ) {}

	public function register(): void {
		add_action( 'admin_post_b83_sc_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_b83_sc_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_b83_sc_settings', array( $this, 'handle_settings' ) );
	}

	private function guard( string $nonce_action ): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'b83-shortcodes' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/* ---------------------------------------------------------------------
	 * Aktionen
	 * ------------------------------------------------------------------- */

	public function handle_export(): void {
		$this->guard( 'b83_sc_export' );

		$entries = array_map(
			static function ( array $entry ): array {
				unset( $entry['previous'] );
				return $entry;
			},
			$this->repository->all()
		);

		$payload = array(
			'format'      => Migration::EXPORT_FORMAT,
			'version'     => B83_SC_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'entries'     => $entries,
		);

		$host     = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$filename = 'b83-shortcodes-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function handle_import(): void {
		$this->guard( 'b83_sc_import' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce in guard(); Datei wird unten geprüft.
		$file      = $_FILES['b83_sc_file'] ?? null;
		$overwrite = ! empty( $_POST['b83_sc_overwrite'] );
		// phpcs:enable

		if ( ! is_array( $file )
			|| ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] )
			|| UPLOAD_ERR_OK !== (int) $file['error']
			|| ! is_uploaded_file( (string) $file['tmp_name'] )
			|| (int) $file['size'] <= 0
			|| (int) $file['size'] > self::MAX_UPLOAD
			|| 'json' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) )
		) {
			AdminPage::redirect( 'import_upload', array( 'tab' => 'werkzeuge' ) );
		}

		$json    = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- geprüfte Upload-Datei.
		$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
		$items   = is_array( $decoded ) ? Migration::normalize_import( $decoded ) : null;

		if ( null === $items ) {
			AdminPage::redirect( 'import_format', array( 'tab' => 'werkzeuge' ) );
		}

		$result = $this->repository->import( $items, $overwrite );
		AdminPage::redirect( 'imported', array_merge( array( 'tab' => 'werkzeuge' ), $result ) );
	}

	public function handle_settings(): void {
		$this->guard( 'b83_sc_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce in guard().
		$this->repository->save_settings(
			array(
				'purge_cache'         => ! empty( $_POST['purge_cache'] ),
				'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ),
			)
		);
		// phpcs:enable
		AdminPage::redirect( 'settings', array( 'tab' => 'werkzeuge' ) );
	}

	/* ---------------------------------------------------------------------
	 * Ausgabe
	 * ------------------------------------------------------------------- */

	public function render(): void {
		$manage   = Capabilities::can_manage();
		$settings = $this->repository->get_settings();
		$post_url = admin_url( 'admin-post.php' );

		echo '<div class="b83-sc-tools">';

		// Export.
		echo '<div class="b83-sc-box"><h2>' . esc_html__( 'Export', 'b83-shortcodes' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lädt alle Shortcodes als JSON-Datei herunter – als Sicherung oder zum Übertragen auf eine andere Website.', 'b83-shortcodes' ) . '</p>';
		if ( $manage ) {
			echo '<form method="post" action="' . esc_url( $post_url ) . '">';
			echo '<input type="hidden" name="action" value="b83_sc_export">';
			wp_nonce_field( 'b83_sc_export' );
			submit_button( __( 'Export herunterladen', 'b83-shortcodes' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		// Import.
		echo '<div class="b83-sc-box"><h2>' . esc_html__( 'Import', 'b83-shortcodes' ) . '</h2>';
		echo '<p>' . esc_html__( 'JSON-Datei aus dem Export einer anderen Website hochladen. Shortcodes, deren Name hier noch nicht existiert, werden neu angelegt.', 'b83-shortcodes' ) . '</p>';
		if ( $manage ) {
			echo '<form method="post" action="' . esc_url( $post_url ) . '" enctype="multipart/form-data" class="b83-sc-confirm-import">';
			echo '<input type="hidden" name="action" value="b83_sc_import">';
			wp_nonce_field( 'b83_sc_import' );
			echo '<p><input type="file" name="b83_sc_file" accept=".json,application/json" required></p>';
			echo '<p><label><input type="checkbox" name="b83_sc_overwrite" value="1"> ' . esc_html__( 'Gleichnamige Shortcodes überschreiben (sonst werden sie übersprungen)', 'b83-shortcodes' ) . '</label></p>';
			submit_button( __( 'Importieren', 'b83-shortcodes' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		// Einstellungen.
		echo '<div class="b83-sc-box"><h2>' . esc_html__( 'Einstellungen', 'b83-shortcodes' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $post_url ) . '">';
		echo '<input type="hidden" name="action" value="b83_sc_settings">';
		wp_nonce_field( 'b83_sc_settings' );

		$cache_plugin = CachePurge::detected_plugin();
		echo '<p><label><input type="checkbox" name="purge_cache" value="1" ' . checked( $settings['purge_cache'], true, false ) . disabled( ! $manage, true, false ) . '> ' . esc_html__( 'Nach dem Speichern den Seiten-Cache der betroffenen Seiten leeren', 'b83-shortcodes' ) . '</label></p>';
		echo '<p class="description">';
		echo '' !== $cache_plugin
			/* translators: %s: Name des Cache-Plugins */
			? esc_html( sprintf( __( 'Erkanntes Cache-Plugin: %s.', 'b83-shortcodes' ), $cache_plugin ) ) . ' '
			: esc_html__( 'Kein unterstütztes Cache-Plugin erkannt.', 'b83-shortcodes' ) . ' ';
		echo esc_html__( 'Steht der Shortcode in einer Vorlage (z. B. Elementor-Header/-Footer), wird der gesamte Cache geleert.', 'b83-shortcodes' ) . '</p>';

		echo '<p><label><input type="checkbox" name="delete_on_uninstall" value="1" ' . checked( $settings['delete_on_uninstall'], true, false ) . disabled( ! $manage, true, false ) . '> ' . esc_html__( 'Beim Löschen des Plugins alle Shortcodes und Einstellungen entfernen', 'b83-shortcodes' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Standardmäßig aus: Dann bleiben die Shortcodes erhalten, wenn das Plugin gelöscht und neu installiert wird.', 'b83-shortcodes' ) . '</p>';

		if ( $manage ) {
			submit_button( __( 'Einstellungen speichern', 'b83-shortcodes' ) );
		}
		echo '</form></div>';

		echo '</div>';
	}
}
