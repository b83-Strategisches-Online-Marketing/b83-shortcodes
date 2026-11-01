<?php
/**
 * Gemeinsames Hauptmenü „b83“ für alle b83-Plugins.
 *
 * Jedes b83-Plugin bringt diese Klasse (im eigenen Namespace) mit. Es wird
 * nur ein Hauptmenü angelegt: Alle Plugins melden ihre Version an, die
 * neueste Implementierung baut Menü und Übersichtsseite. Die Plugins hängen
 * sich als Unterpunkte mit add_submenu_page( B83Menu::SLUG, … ) ein.
 *
 * Bei Änderungen an dieser Datei MENU_VERSION erhöhen und die Datei in
 * alle b83-Plugins übernehmen.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes\Admin;

defined( 'ABSPATH' ) || exit;

final class B83Menu {

	public const SLUG         = 'b83';
	public const MENU_VERSION = 2;

	/** Basis-URL und -Pfad des Plugins, das diese Kopie mitbringt. */
	public function __construct( private string $plugin_url, private string $plugin_dir ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'announce' ), 4 );
		add_action( 'admin_menu', array( $this, 'maybe_build' ), 5 );
	}

	/** Version anmelden – die höchste gewinnt. */
	public function announce(): void {
		$current = $GLOBALS['b83_menu_provider'] ?? null;
		if ( ! is_array( $current ) || self::MENU_VERSION > (int) $current['version'] ) {
			$GLOBALS['b83_menu_provider'] = array(
				'version'  => self::MENU_VERSION,
				'instance' => $this,
			);
		}
	}

	public function maybe_build(): void {
		$provider = $GLOBALS['b83_menu_provider'] ?? null;
		if ( ! is_array( $provider ) || $provider['instance'] !== $this || ! empty( $GLOBALS['b83_menu_built'] ) ) {
			return;
		}
		$GLOBALS['b83_menu_built'] = true;

		add_menu_page(
			'b83 Plugins',
			'b83',
			'manage_options',
			self::SLUG,
			array( $this, 'render_overview' ),
			$this->icon(),
			81
		);
		add_submenu_page( self::SLUG, 'b83 Plugins', __( 'Übersicht', 'b83-shortcodes' ), 'manage_options', self::SLUG, array( $this, 'render_overview' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Einfarbiges SVG als Data-URI: WordPress färbt es passend zum
	 * Admin-Farbschema ein (normal, Hover, aktiv).
	 */
	private function icon(): string {
		$file = $this->plugin_dir . 'assets/img/b83-menu-icon.svg';
		$svg  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Plugin-Datei.
		return '' !== $svg ? 'data:image/svg+xml;base64,' . base64_encode( $svg ) : 'dashicons-admin-generic'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menü-Icon.
	}

	/** Stile für Menü-Icon (alle Admin-Seiten) und Übersichtsseite. */
	public function enqueue(): void {
		wp_enqueue_style( 'b83-menu', $this->plugin_url . 'assets/css/b83-menu.css', array(), (string) self::MENU_VERSION );
		wp_add_inline_style( 'b83-menu', $this->submenu_icon_css() );
	}

	/**
	 * Icon eines installierten b83-Plugins (leer, wenn keines vorhanden).
	 *
	 * @param string $file Plugin-Datei relativ zum Plugin-Ordner.
	 */
	private function plugin_icon_url( string $file ): string {
		$folder = dirname( $file );
		if ( '.' === $folder || '' === $folder ) {
			return '';
		}
		$candidates = array( 'assets/img/icon.svg', 'assets/img/icon-128x128.png', 'assets/img/' . $folder . '-icon.png' );
		foreach ( $candidates as $relative ) {
			if ( is_readable( WP_PLUGIN_DIR . '/' . $folder . '/' . $relative ) ) {
				return plugins_url( $relative, WP_PLUGIN_DIR . '/' . $file );
			}
		}
		return '';
	}

	/**
	 * Kleines Icon vor dem Menüpunkt jedes Plugins, das seine Seite über
	 * b83_admin_plugin_links meldet.
	 */
	private function submenu_icon_css(): string {
		$css = '';
		foreach ( (array) apply_filters( 'b83_admin_plugin_links', array() ) as $file => $url ) {
			$query = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
			parse_str( $query, $args );
			$page = sanitize_key( (string) ( $args['page'] ?? '' ) );
			$icon = $this->plugin_icon_url( (string) $file );
			if ( '' === $page || '' === $icon ) {
				continue;
			}
			$css .= '#toplevel_page_b83 .wp-submenu a[href="admin.php?page=' . $page . '"]::before{content:"";background-image:url("' . esc_url_raw( $icon ) . '")}';
		}
		return $css;
	}

	/* ---------------------------------------------------------------------
	 * Übersichtsseite
	 * ------------------------------------------------------------------- */

	/**
	 * Alle installierten b83-Plugins (Ordner b83-* oder Author URI b83.at).
	 *
	 * @return array<string, array<string,string>>
	 */
	private function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();
		foreach ( get_plugins() as $file => $data ) {
			$is_b83 = str_starts_with( $file, 'b83-' ) || str_contains( (string) ( $data['AuthorURI'] ?? '' ), 'b83.at' );
			if ( $is_b83 ) {
				$found[ $file ] = $data;
			}
		}
		return $found;
	}

	public function render_overview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'b83-shortcodes' ), '', array( 'response' => 403 ) );
		}

		/**
		 * b83-Plugins können hier ihre Einstellungsseite eintragen:
		 * array( 'plugin-ordner/plugin.php' => admin_url( 'admin.php?page=…' ) ).
		 *
		 * @param array<string,string> $links Plugin-Datei => URL.
		 */
		$links   = (array) apply_filters( 'b83_admin_plugin_links', array() );
		$plugins = $this->installed_plugins();
		$logo    = $this->plugin_url . 'assets/img/b83-logo.svg';

		echo '<div class="wrap b83-overview">';

		echo '<div class="b83-overview__head"><img src="' . esc_url( $logo ) . '" alt="b83"><div>';
		echo '<h1>' . esc_html__( 'b83 Plugins', 'b83-shortcodes' ) . '</h1>';
		echo '<p>' . esc_html__( 'Entwickelt und betreut von b83 – Strategisches Online Marketing.', 'b83-shortcodes' ) . '</p>';
		echo '</div></div>';

		echo '<div class="b83-overview__grid">';
		foreach ( $plugins as $file => $data ) {
			$active = is_plugin_active( $file );
			echo '<div class="b83-overview__card">';
			$icon = $this->plugin_icon_url( (string) $file );
			if ( '' !== $icon ) {
				echo '<img class=\"b83-overview__icon\" src=\"' . esc_url( $icon ) . '\" alt=\"\" width=\"48\" height=\"48\">';
			}
			echo '<h2>' . esc_html( (string) $data['Name'] );
			printf(
				'<span class="b83-overview__status %1$s">%2$s</span>',
				$active ? 'is-on' : 'is-off',
				$active ? esc_html__( 'aktiv', 'b83-shortcodes' ) : esc_html__( 'inaktiv', 'b83-shortcodes' )
			);
			echo '</h2>';
			/* translators: %s: Versionsnummer */
			echo '<p class="b83-overview__meta">' . esc_html( sprintf( __( 'Version %s', 'b83-shortcodes' ), (string) $data['Version'] ) ) . '</p>';
			echo '<p>' . esc_html( wp_strip_all_tags( (string) $data['Description'] ) ) . '</p>';
			if ( $active && ! empty( $links[ $file ] ) ) {
				echo '<p><a class="button button-primary" href="' . esc_url( (string) $links[ $file ] ) . '">' . esc_html__( 'Öffnen', 'b83-shortcodes' ) . '</a></p>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<p class="b83-overview__foot">b83 – Strategisches Online Marketing · <a href="https://b83.at" target="_blank" rel="noopener">b83.at</a> · <a href="mailto:office@b83.at">office@b83.at</a></p>';
		echo '</div>';
	}
}
