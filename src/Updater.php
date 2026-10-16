<?php
/**
 * Updates über GitHub-Releases.
 *
 * WordPress fragt für Plugins mit „Update URI: https://github.com/…“ nicht
 * wordpress.org, sondern den Filter update_plugins_github.com. Hier wird das
 * neueste Release des Repositorys abgefragt; das Release muss eine ZIP-Datei
 * namens b83-shortcodes.zip enthalten (erzeugt die GitHub Action automatisch).
 *
 * Private Repositorys: In der wp-config.php einen Lese-Token hinterlegen:
 *   define( 'B83_GITHUB_TOKEN', 'github_pat_…' );
 * Der Token steht nie im Plugin-Code und wird nirgends ausgegeben.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class Updater {

	private const REPO      = 'b83-Strategisches-Online-Marketing/b83-shortcodes';
	private const SLUG      = 'b83-shortcodes';
	private const ASSET     = 'b83-shortcodes.zip';
	private const TRANSIENT = 'b83_shortcodes_release';

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_private_asset' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ) );
		add_action( 'load-update-core.php', array( $this, 'flush_on_force_check' ) );
	}

	private function basename(): string {
		return plugin_basename( B83_SC_FILE );
	}

	/* ---------------------------------------------------------------------
	 * Update-Prüfung
	 * ------------------------------------------------------------------- */

	/**
	 * @param array|false          $update      Bisheriges Ergebnis.
	 * @param array<string,string> $plugin_data Plugin-Header.
	 * @param string               $plugin_file Plugin-Datei.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename() ) {
			return $update;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $update;
		}

		// Auch ohne neue Version zurückgeben: Dann bietet WordPress „Automatische Updates aktivieren“ an.
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '8.1',
			'requires'     => '6.2',
		);
	}

	/**
	 * Inhalt für „Details ansehen“.
	 *
	 * @param false|object|array $result Bisheriges Ergebnis.
	 * @param string             $action Aktion.
	 * @param object             $args   Argumente.
	 * @return false|object|array
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'b83 Shortcodes',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://b83.at">b83 – Wolfgang Grabs-Schrempf</a>',
			'homepage'      => 'https://b83.at',
			'requires'      => '6.2',
			'requires_php'  => '8.1',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => $release['notes'],
			),
		);
	}

	/**
	 * Neuestes Release (6 Stunden zwischengespeichert, Fehler 1 Stunde).
	 *
	 * @return array{version:string,url:string,package:string,published:string,notes:string}|null
	 */
	private function release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $this->headers( 'application/vnd.github+json' ),
			)
		);

		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$version = is_array( $body ) ? ltrim( (string) ( $body['tag_name'] ?? '' ), 'vV' ) : '';

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			set_transient( self::TRANSIENT, array( 'error' => true ), HOUR_IN_SECONDS );
			return null;
		}

		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && ( $asset['name'] ?? '' ) === self::ASSET ) {
				// Privat: über die API (mit Token), öffentlich: direkter Download.
				$package = (string) ( $this->token() ? ( $asset['url'] ?? '' ) : ( $asset['browser_download_url'] ?? '' ) );
				break;
			}
		}

		$release = array(
			'version'   => $version,
			'url'       => esc_url_raw( (string) ( $body['html_url'] ?? 'https://github.com/' . self::REPO ) ),
			'package'   => esc_url_raw( $package ),
			'published' => sanitize_text_field( (string) ( $body['published_at'] ?? '' ) ),
			'notes'     => wp_kses_post( nl2br( esc_html( (string) ( $body['body'] ?? '' ) ) ) ),
		);
		set_transient( self::TRANSIENT, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/* ---------------------------------------------------------------------
	 * Download und Installation
	 * ------------------------------------------------------------------- */

	/**
	 * Private Release-Dateien liegen hinter der API: erst mit Token die
	 * Weiterleitung abholen, dann ohne Token von dort herunterladen.
	 *
	 * @param mixed  $reply   Bisheriges Ergebnis.
	 * @param string $package Paket-URL.
	 * @return mixed
	 */
	public function download_private_asset( $reply, $package ) {
		$prefix = 'https://api.github.com/repos/' . self::REPO . '/releases/assets/';
		if ( false !== $reply || ! is_string( $package ) || ! str_starts_with( $package, $prefix ) || ! $this->token() ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => $this->headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( '' === $location ) {
				return new \WP_Error( 'b83_sc_download', __( 'Update-Download: keine Weiterleitung erhalten.', 'b83-shortcodes' ) );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return download_url( $location, 300 );
		}

		if ( 200 === $code ) {
			$tmp = wp_tempnam( self::ASSET );
			if ( $tmp && false !== file_put_contents( $tmp, wp_remote_retrieve_body( $response ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporäre Datei.
				return $tmp;
			}
		}

		return new \WP_Error(
			'b83_sc_download',
			/* translators: %d: HTTP-Statuscode */
			sprintf( __( 'Update-Download fehlgeschlagen (HTTP %d). Ist B83_GITHUB_TOKEN gültig?', 'b83-shortcodes' ), $code )
		);
	}

	/**
	 * Stellt sicher, dass der entpackte Ordner b83-shortcodes heißt
	 * (sonst würde WordPress das Plugin in einen neuen Ordner installieren).
	 *
	 * @param string|\WP_Error $source        Entpackter Ordner.
	 * @param string           $remote_source Übergeordneter Ordner.
	 * @param mixed            $upgrader      Upgrader.
	 * @param array            $hook_extra    Zusatzinfos.
	 * @return string|\WP_Error
	 */
	public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ( $hook_extra['plugin'] ?? '' ) !== $this->basename() ) {
			return $source;
		}
		if ( basename( untrailingslashit( $source ) ) === self::SLUG ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}
		return new \WP_Error( 'b83_sc_folder', __( 'Update: Plugin-Ordner konnte nicht umbenannt werden.', 'b83-shortcodes' ) );
	}

	public function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/** „Erneut prüfen“ unter Dashboard → Aktualisierungen fragt GitHub sofort neu ab. */
	public function flush_on_force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- löscht nur einen Zwischenspeicher.
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			$this->flush();
		}
	}

	/* ---------------------------------------------------------------------
	 * Hilfen
	 * ------------------------------------------------------------------- */

	private function token(): string {
		return defined( 'B83_GITHUB_TOKEN' ) && is_string( B83_GITHUB_TOKEN ) ? trim( B83_GITHUB_TOKEN ) : '';
	}

	/** @return array<string,string> */
	private function headers( string $accept ): array {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'b83-shortcodes/' . B83_SC_VERSION . '; ' . home_url( '/' ),
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( $this->token() ) {
			$headers['Authorization'] = 'Bearer ' . $this->token();
		}
		return $headers;
	}
}
