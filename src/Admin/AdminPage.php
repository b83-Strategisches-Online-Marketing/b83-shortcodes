<?php
/**
 * Backend-Seite „Shortcodes“ im b83-Menü: Liste, Bearbeiten und alle
 * schreibenden Aktionen (über admin-post.php, jeweils mit Capability- und
 * Nonce-Prüfung).
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes\Admin;

use B83\Shortcodes\Capabilities;
use B83\Shortcodes\Frontend\Renderer;
use B83\Shortcodes\Integrations\CachePurge;
use B83\Shortcodes\Repository;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	public const SLUG = 'b83-shortcodes';

	/** Zwischenspeicher für Formulardaten nach einem Fehler (je Benutzer, 10 Minuten). */
	private const FORM_TRANSIENT = 'b83_sc_form_';

	private ToolsTab $tools;
	private string $hook = '';

	public function __construct( private Repository $repository, private Renderer $renderer, private CachePurge $cache ) {
		$this->tools = new ToolsTab( $repository );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_page_access_denied', array( $this, 'redirect_legacy_url' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( B83_SC_FILE ), array( $this, 'action_links' ) );
		add_filter( 'b83_admin_plugin_links', array( $this, 'overview_link' ) );

		foreach ( array( 'save', 'delete', 'toggle', 'duplicate', 'restore' ) as $action ) {
			add_action( 'admin_post_b83_sc_' . $action, array( $this, 'handle_' . $action ) );
		}

		( new B83Menu( B83_SC_URL, B83_SC_DIR ) )->register();
		$this->tools->register();
	}

	/** Unterpunkt im gemeinsamen b83-Menü. */
	public function add_menu(): void {
		$this->hook = (string) add_submenu_page(
			B83Menu::SLUG,
			__( 'Shortcodes', 'b83-shortcodes' ),
			__( 'Shortcodes', 'b83-shortcodes' ),
			Capabilities::VIEW,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Alte Adresse aus Version 1.x (Einstellungen → b83 Shortcodes) weiterleiten.
	 * Läuft über admin_page_access_denied, weil WordPress die alte Seite sonst mit 403 ablehnt.
	 */
	public function redirect_legacy_url(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Weiterleitung.
		if ( 'options-general.php' === $pagenow && self::SLUG === ( $_GET['page'] ?? '' ) && Capabilities::can_view() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
			wp_safe_redirect( self::url( '' !== $edit ? array( 'edit' => $edit ) : array() ) );
			exit;
		}
	}

	/**
	 * @param array<string,string> $links Links.
	 * @return array<string,string>
	 */
	public function overview_link( array $links ): array {
		$links[ plugin_basename( B83_SC_FILE ) ] = self::url();
		return $links;
	}

	/**
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Öffnen', 'b83-shortcodes' ) . '</a>' );
		return $links;
	}

	/**
	 * URL der Backend-Seite.
	 *
	 * @param array<string,string|int> $args Zusätzliche Parameter.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Mit festem Hinweis-Schlüssel zurück auf die Seite und beenden.
	 *
	 * @param string                   $notice Hinweis-Schlüssel (siehe notices()).
	 * @param array<string,string|int> $args   Weitere Parameter.
	 */
	public static function redirect( string $notice, array $args = array() ): void {
		wp_safe_redirect( self::url( array_merge( $args, array( 'b83_sc_notice' => $notice ) ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $this->hook !== $hook ) {
			return;
		}

		wp_enqueue_style( 'b83-shortcodes-admin', B83_SC_URL . 'assets/css/admin.css', array(), B83_SC_VERSION );

		$editor = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Ansicht.
		if ( isset( $_GET['edit'] ) && Capabilities::can_manage() ) {
			$editor = wp_enqueue_code_editor(
				array(
					'type'       => 'text/html',
					'codemirror' => array(
						'lineWrapping' => true,
					),
				)
			);
		}

		wp_enqueue_script( 'b83-shortcodes-admin', B83_SC_URL . 'assets/js/admin.js', array( 'jquery' ), B83_SC_VERSION, true );
		wp_localize_script(
			'b83-shortcodes-admin',
			'b83Shortcodes',
			array(
				'codeEditor' => $editor,
				'i18n'       => array(
					'copied'        => __( 'Kopiert', 'b83-shortcodes' ),
					'copyFailed'    => __( 'Kopieren nicht möglich – bitte manuell markieren.', 'b83-shortcodes' ),
					'confirmDelete' => __( 'Diesen Shortcode wirklich löschen? Auf Seiten, die ihn verwenden, erscheint danach der Shortcode-Text.', 'b83-shortcodes' ),
					'confirmRestore' => __( 'Vorherige Fassung des Codes wiederherstellen? Die aktuelle Fassung wird dabei zur vorherigen.', 'b83-shortcodes' ),
					'confirmImport' => __( 'Shortcodes jetzt importieren?', 'b83-shortcodes' ),
					'leave'         => __( 'Es gibt ungespeicherte Änderungen.', 'b83-shortcodes' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Aktionen
	 * ------------------------------------------------------------------- */

	/** Gemeinsame Prüfung für alle schreibenden Aktionen. */
	private function guard( string $nonce_action ): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Keine Berechtigung: Shortcodes dürfen nur Administratoren mit dem Recht „unfiltered_html“ ändern.', 'b83-shortcodes' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/** ID aus dem Formular (nur erlaubte Zeichen). */
	private function posted_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wurde in guard() geprüft.
		$id = isset( $_POST['entry_id'] ) ? strtolower( (string) wp_unslash( $_POST['entry_id'] ) ) : '';
		return 1 === preg_match( '/^[a-z0-9-]{8,64}$/', $id ) ? $id : '';
	}

	public function handle_save(): void {
		$this->guard( 'b83_sc_save' );
		$id = $this->posted_id();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in guard(); Bereinigung zentral im Repository/Sanitizer.
		$input = array(
			'title'               => isset( $_POST['entry_title'] ) ? wp_unslash( $_POST['entry_title'] ) : '',
			'tag'                 => isset( $_POST['entry_tag'] ) ? wp_unslash( $_POST['entry_tag'] ) : '',
			'description'         => isset( $_POST['entry_description'] ) ? wp_unslash( $_POST['entry_description'] ) : '',
			'code'                => isset( $_POST['entry_code'] ) ? wp_unslash( $_POST['entry_code'] ) : '',
			'footer_code'         => isset( $_POST['entry_footer_code'] ) ? wp_unslash( $_POST['entry_footer_code'] ) : '',
			'active'              => ! empty( $_POST['entry_active'] ),
			'builder_placeholder' => ! empty( $_POST['entry_builder_placeholder'] ),
		);
		// phpcs:enable
		$input = array_map( static fn( $v ) => is_string( $v ) || is_bool( $v ) ? $v : '', $input );

		$result = $this->repository->save( $input, $id );

		if ( is_wp_error( $result ) ) {
			set_transient(
				self::FORM_TRANSIENT . get_current_user_id(),
				array(
					'input'   => $input,
					'message' => $result->get_error_message(),
				),
				10 * MINUTE_IN_SECONDS
			);
			self::redirect( 'error', array( 'edit' => '' !== $id ? $id : 'new' ) );
		}

		self::redirect( '' !== $id ? 'saved' : 'created', array( 'edit' => (string) $result['id'] ) );
	}

	public function handle_delete(): void {
		$id = $this->posted_id();
		$this->guard( 'b83_sc_delete_' . $id );
		self::redirect( null !== $this->repository->delete( $id ) ? 'deleted' : 'not_found' );
	}

	public function handle_toggle(): void {
		$id = $this->posted_id();
		$this->guard( 'b83_sc_toggle_' . $id );
		$entry = $this->repository->find( $id );
		if ( null === $entry ) {
			self::redirect( 'not_found' );
		}
		$entry = $this->repository->set_active( $id, ! $entry['active'] );
		self::redirect( ! empty( $entry['active'] ) ? 'activated' : 'deactivated' );
	}

	public function handle_duplicate(): void {
		$id = $this->posted_id();
		$this->guard( 'b83_sc_duplicate_' . $id );
		$copy = $this->repository->duplicate( $id );
		if ( is_wp_error( $copy ) ) {
			self::redirect( 'not_found' );
		}
		self::redirect( 'duplicated', array( 'edit' => (string) $copy['id'] ) );
	}

	public function handle_restore(): void {
		$id = $this->posted_id();
		$this->guard( 'b83_sc_restore_' . $id );
		$entry = $this->repository->restore_previous( $id );
		self::redirect( null !== $entry ? 'restored' : 'not_found', null !== $entry ? array( 'edit' => $id ) : array() );
	}

	/* ---------------------------------------------------------------------
	 * Ausgabe
	 * ------------------------------------------------------------------- */

	public function render(): void {
		if ( ! Capabilities::can_view() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'b83-shortcodes' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Ansicht.
		$tab  = isset( $_GET['tab'] ) && 'werkzeuge' === $_GET['tab'] ? 'werkzeuge' : '';
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap b83-shortcodes-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'b83 Shortcodes', 'b83-shortcodes' ) . '</h1>';
		if ( Capabilities::can_manage() && '' === $edit && '' === $tab ) {
			echo ' <a href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Neuer Shortcode', 'b83-shortcodes' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';

		$this->notices();

		echo '<nav class="nav-tab-wrapper">';
		printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url() ), '' === $tab ? 'nav-tab-active' : '', esc_html__( 'Shortcodes', 'b83-shortcodes' ) );
		printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( array( 'tab' => 'werkzeuge' ) ) ), 'werkzeuge' === $tab ? 'nav-tab-active' : '', esc_html__( 'Import/Export & Einstellungen', 'b83-shortcodes' ) );
		echo '</nav>';

		if ( ! Capabilities::can_manage() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Nur Ansicht: Zum Ändern von Shortcodes ist zusätzlich das Recht „unfiltered_html“ nötig (auf Multisite nur Super-Admins; ist in der wp-config.php DISALLOW_UNFILTERED_HTML gesetzt, hat es niemand).', 'b83-shortcodes' ) . '</p></div>';
		}

		if ( 'werkzeuge' === $tab ) {
			$this->tools->render();
		} elseif ( '' !== $edit && Capabilities::can_manage() ) {
			$this->render_edit( $edit );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/** Hinweise über feste Schlüssel in der URL. */
	private function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige fester Texte.
		$key = isset( $_GET['b83_sc_notice'] ) ? sanitize_key( wp_unslash( $_GET['b83_sc_notice'] ) ) : '';
		// phpcs:enable
		if ( '' === $key ) {
			return;
		}

		$messages = array(
			'created'       => array( 'success', __( 'Shortcode angelegt.', 'b83-shortcodes' ) ),
			'saved'         => array( 'success', __( 'Shortcode gespeichert.', 'b83-shortcodes' ) ),
			'deleted'       => array( 'success', __( 'Shortcode gelöscht.', 'b83-shortcodes' ) ),
			'activated'     => array( 'success', __( 'Shortcode aktiviert.', 'b83-shortcodes' ) ),
			'deactivated'   => array( 'success', __( 'Shortcode deaktiviert – er gibt jetzt nichts mehr aus.', 'b83-shortcodes' ) ),
			'duplicated'    => array( 'success', __( 'Kopie angelegt (inaktiv). Bitte Namen prüfen und aktivieren.', 'b83-shortcodes' ) ),
			'restored'      => array( 'success', __( 'Vorherige Fassung wiederhergestellt.', 'b83-shortcodes' ) ),
			'settings'      => array( 'success', __( 'Einstellungen gespeichert.', 'b83-shortcodes' ) ),
			'imported'      => array( 'success', __( 'Import abgeschlossen.', 'b83-shortcodes' ) ),
			'import_upload' => array( 'error', __( 'Import fehlgeschlagen: Bitte eine JSON-Datei bis 2 MB auswählen.', 'b83-shortcodes' ) ),
			'import_format' => array( 'error', __( 'Import fehlgeschlagen: Die Datei ist kein Export von b83 Shortcodes.', 'b83-shortcodes' ) ),
			'not_found'     => array( 'error', __( 'Der Shortcode wurde nicht gefunden.', 'b83-shortcodes' ) ),
			'error'         => array( 'error', __( 'Der Shortcode wurde nicht gespeichert.', 'b83-shortcodes' ) ),
		);
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		[ $type, $text ] = $messages[ $key ];

		if ( 'imported' === $key ) {
			// Nur Zahlen aus der URL.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$text .= ' ' . sprintf(
				/* translators: 1: neu, 2: aktualisiert, 3: übersprungen */
				__( 'Neu: %1$d, aktualisiert: %2$d, übersprungen: %3$d.', 'b83-shortcodes' ),
				absint( $_GET['added'] ?? 0 ),
				absint( $_GET['updated'] ?? 0 ),
				absint( $_GET['skipped'] ?? 0 )
			);
			// phpcs:enable
		}
		if ( 'error' === $key ) {
			$form = get_transient( self::FORM_TRANSIENT . get_current_user_id() );
			if ( is_array( $form ) && ! empty( $form['message'] ) ) {
				$text .= ' ' . (string) $form['message'];
			}
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/* ---------------------------------------------------------------------
	 * Liste
	 * ------------------------------------------------------------------- */

	private function render_list(): void {
		$entries = $this->repository->all();
		$manage  = Capabilities::can_manage();

		echo '<div class="b83-sc-info">';
		echo '<p><strong>' . esc_html__( 'So funktioniert’s:', 'b83-shortcodes' ) . '</strong> ' . esc_html__( 'Shortcode anlegen, HTML/CSS/JavaScript einfügen und den Shortcode dort einsetzen, wo die Ausgabe erscheinen soll – in Elementor mit dem Widget „Shortcode“, im Block-Editor mit dem Block „Shortcode“, oder in einem Text-Widget. PHP-Code wird nie ausgeführt.', 'b83-shortcodes' ) . '</p>';
		echo '</div>';

		if ( empty( $entries ) ) {
			echo '<div class="b83-sc-empty"><p>' . esc_html__( 'Noch keine Shortcodes angelegt.', 'b83-shortcodes' ) . '</p>';
			if ( $manage ) {
				echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Ersten Shortcode anlegen', 'b83-shortcodes' ) . '</a></p>';
			}
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped b83-sc-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Titel', 'b83-shortcodes' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'b83-shortcodes' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'b83-shortcodes' ) . '</th>';
		echo '<th>' . esc_html__( 'Geändert', 'b83-shortcodes' ) . '</th>';
		if ( $manage ) {
			echo '<th class="b83-sc-col-actions">' . esc_html__( 'Aktionen', 'b83-shortcodes' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$id        = (string) $entry['id'];
			$shortcode = '[' . $entry['tag'] . ']';

			echo '<tr>';

			echo '<td class="b83-sc-col-title">';
			if ( $manage ) {
				echo '<a class="row-title" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html( (string) $entry['title'] ) . '</a>';
			} else {
				echo '<strong>' . esc_html( (string) $entry['title'] ) . '</strong>';
			}
			if ( '' !== $entry['description'] ) {
				echo '<p class="description">' . esc_html( (string) $entry['description'] ) . '</p>';
			}
			echo '</td>';

			echo '<td class="b83-sc-col-tag"><code class="b83-sc-tag">' . esc_html( $shortcode ) . '</code> ';
			echo '<button type="button" class="button button-small b83-sc-copy" data-copy="' . esc_attr( $shortcode ) . '">' . esc_html__( 'Kopieren', 'b83-shortcodes' ) . '</button></td>';

			echo '<td>';
			echo $entry['active']
				? '<span class="b83-sc-badge is-on">' . esc_html__( 'aktiv', 'b83-shortcodes' ) . '</span>'
				: '<span class="b83-sc-badge is-off">' . esc_html__( 'inaktiv', 'b83-shortcodes' ) . '</span>';
			if ( '' !== trim( (string) $entry['footer_code'] ) ) {
				echo ' <span class="b83-sc-badge">' . esc_html__( 'Seitenende', 'b83-shortcodes' ) . '</span>';
			}
			if ( $entry['builder_placeholder'] ) {
				echo ' <span class="b83-sc-badge">' . esc_html__( 'Editor-Platzhalter', 'b83-shortcodes' ) . '</span>';
			}
			if ( $this->renderer->is_overridden( (string) $entry['tag'] ) ) {
				echo '<p class="b83-sc-warning">' . esc_html__( 'Achtung: Ein anderes Plugin verwendet denselben Namen und überschreibt diesen Shortcode. Bitte umbenennen.', 'b83-shortcodes' ) . '</p>';
			} elseif ( $this->renderer->replaces_foreign( (string) $entry['tag'] ) ) {
				echo '<p class="b83-sc-warning">' . esc_html__( 'Achtung: Dieser Name gehört auch zu WordPress oder einem anderen Plugin – dessen Shortcode wird dadurch ersetzt. Bitte umbenennen.', 'b83-shortcodes' ) . '</p>';
			}
			echo '</td>';

			echo '<td>' . esc_html( (string) $entry['updated_at'] ) . '</td>';

			if ( $manage ) {
				echo '<td class="b83-sc-col-actions"><div class="b83-sc-actions">';
				echo '<a class="button button-small" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Bearbeiten', 'b83-shortcodes' ) . '</a>';
				$this->action_button( 'toggle', $id, $entry['active'] ? __( 'Deaktivieren', 'b83-shortcodes' ) : __( 'Aktivieren', 'b83-shortcodes' ) );
				$this->action_button( 'duplicate', $id, __( 'Duplizieren', 'b83-shortcodes' ) );
				$this->action_button( 'delete', $id, __( 'Löschen', 'b83-shortcodes' ), 'button-link-delete b83-sc-confirm-delete' );
				echo '</div></td>';
			}

			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** Kleines POST-Formular für eine Aktion an einem Eintrag. */
	private function action_button( string $action, string $id, string $label, string $class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'b83_sc_' . $action ) . '">';
		echo '<input type="hidden" name="entry_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'b83_sc_' . $action . '_' . $id );
		echo '<button type="submit" class="button button-small ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/* ---------------------------------------------------------------------
	 * Bearbeiten
	 * ------------------------------------------------------------------- */

	private function render_edit( string $edit ): void {
		$entry = 'new' === $edit ? null : $this->repository->find( $edit );
		if ( 'new' !== $edit && null === $entry ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Der Shortcode wurde nicht gefunden.', 'b83-shortcodes' ) . '</p></div>';
			return;
		}

		$values = array(
			'title'               => (string) ( $entry['title'] ?? '' ),
			'tag'                 => (string) ( $entry['tag'] ?? '' ),
			'description'         => (string) ( $entry['description'] ?? '' ),
			'code'                => (string) ( $entry['code'] ?? '' ),
			'footer_code'         => (string) ( $entry['footer_code'] ?? '' ),
			'active'              => null === $entry || ! empty( $entry['active'] ),
			'builder_placeholder' => ! empty( $entry['builder_placeholder'] ),
		);

		// Nach einem Fehler die zuletzt eingegebenen Werte wieder anzeigen.
		$form_key = self::FORM_TRANSIENT . get_current_user_id();
		$form     = get_transient( $form_key );
		if ( is_array( $form ) && isset( $form['input'] ) && is_array( $form['input'] ) ) {
			$values = array_merge( $values, array_intersect_key( $form['input'], $values ) );
			delete_transient( $form_key );
		}

		$id = (string) ( $entry['id'] ?? '' );

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Zurück zur Übersicht', 'b83-shortcodes' ) . '</a></p>';
		echo '<h2>' . ( null === $entry ? esc_html__( 'Neuer Shortcode', 'b83-shortcodes' ) : esc_html__( 'Shortcode bearbeiten', 'b83-shortcodes' ) ) . '</h2>';

		echo '<div class="b83-sc-edit">';
		echo '<div class="b83-sc-edit__main">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b83-sc-form" id="b83-sc-form">';
		echo '<input type="hidden" name="action" value="b83_sc_save">';
		echo '<input type="hidden" name="entry_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'b83_sc_save' );

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="entry_title">' . esc_html__( 'Titel', 'b83-shortcodes' ) . '</label></th><td>';
		echo '<input name="entry_title" id="entry_title" type="text" class="regular-text" maxlength="150" value="' . esc_attr( $values['title'] ) . '" placeholder="' . esc_attr__( 'z. B. Wetter-Widget Startseite', 'b83-shortcodes' ) . '">';
		echo '<p class="description">' . esc_html__( 'Interner Name für die Übersicht – wird auf der Website nicht angezeigt.', 'b83-shortcodes' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="entry_tag">' . esc_html__( 'Shortcode-Name', 'b83-shortcodes' ) . '</label></th><td>';
		echo '<input name="entry_tag" id="entry_tag" type="text" class="regular-text code" maxlength="64" value="' . esc_attr( $values['tag'] ) . '" placeholder="' . esc_attr__( 'z. B. wetter_startseite', 'b83-shortcodes' ) . '">';
		echo ' <code class="b83-sc-preview" id="b83-sc-preview">[' . esc_html( '' !== $values['tag'] ? $values['tag'] : '…' ) . ']</code>';
		echo ' <button type="button" class="button button-small b83-sc-copy" id="b83-sc-preview-copy" data-copy="' . esc_attr( '[' . $values['tag'] . ']' ) . '">' . esc_html__( 'Kopieren', 'b83-shortcodes' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Kleinbuchstaben, Ziffern und Unterstriche. Leer lassen, um den Namen aus dem Titel zu erzeugen. Achtung: Wird der Name geändert, muss er auch auf allen Seiten angepasst werden.', 'b83-shortcodes' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="entry_description">' . esc_html__( 'Notiz', 'b83-shortcodes' ) . '</label></th><td>';
		echo '<textarea name="entry_description" id="entry_description" rows="2" class="large-text" maxlength="1000" placeholder="' . esc_attr__( 'z. B. Wofür, von wem, Ansprechpartner beim Anbieter …', 'b83-shortcodes' ) . '">' . esc_textarea( $values['description'] ) . '</textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="entry_code">' . esc_html__( 'Code an der Stelle des Shortcodes', 'b83-shortcodes' ) . '</label></th><td>';
		echo '<textarea name="entry_code" id="entry_code" rows="14" class="large-text code b83-sc-code" spellcheck="false">' . esc_textarea( $values['code'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'HTML, <style> und <script> – wird bei jedem Vorkommen des Shortcodes ausgegeben. Weitere Shortcodes darin funktionieren.', 'b83-shortcodes' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="entry_footer_code">' . esc_html__( 'Code am Seitenende', 'b83-shortcodes' ) . '</label></th><td>';
		echo '<textarea name="entry_footer_code" id="entry_footer_code" rows="8" class="large-text code b83-sc-code" spellcheck="false">' . esc_textarea( $values['footer_code'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional, ideal für Scripts: wird nur auf Seiten ausgegeben, auf denen der Shortcode vorkommt – vor </body> und höchstens einmal pro Seite, auch wenn der Shortcode mehrfach verwendet wird. So kann der Container oben stehen und das Script einmal am Ende geladen werden.', 'b83-shortcodes' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Optionen', 'b83-shortcodes' ) . '</th><td><fieldset>';
		echo '<label><input type="checkbox" name="entry_active" value="1" ' . checked( $values['active'], true, false ) . '> ' . esc_html__( 'Aktiv (ausgeschaltet gibt der Shortcode nichts aus – auch nicht den Shortcode-Text)', 'b83-shortcodes' ) . '</label><br>';
		echo '<label><input type="checkbox" name="entry_builder_placeholder" value="1" ' . checked( $values['builder_placeholder'], true, false ) . '> ' . esc_html__( 'Im Page-Builder-Editor (Elementor u. a.) nur einen Platzhalter zeigen', 'b83-shortcodes' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Empfohlen für Tracking-, Chat- oder Buchungs-Scripts, die im Editor stören oder Fehler auslösen. Auf der Website wird der Code normal ausgegeben.', 'b83-shortcodes' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '</table>';

		submit_button( null === $entry ? __( 'Shortcode anlegen', 'b83-shortcodes' ) : __( 'Änderungen speichern', 'b83-shortcodes' ) );
		echo '</form>';
		echo '</div>';

		// Seitenleiste.
		echo '<aside class="b83-sc-edit__side">';
		$this->render_help_box();
		if ( null !== $entry ) {
			$this->render_usage_box( $entry );
			$this->render_previous_box( $entry );
		}
		echo '</aside>';

		echo '</div>';
	}

	private function render_help_box(): void {
		echo '<div class="b83-sc-box"><h3>' . esc_html__( 'Einbinden', 'b83-shortcodes' ) . '</h3><ul>';
		echo '<li>' . esc_html__( 'Elementor: Widget „Shortcode“ (nicht „HTML“) und den Shortcode einfügen.', 'b83-shortcodes' ) . '</li>';
		echo '<li>' . esc_html__( 'Block-Editor: Block „Shortcode“.', 'b83-shortcodes' ) . '</li>';
		echo '<li>' . esc_html__( 'Header/Footer für alle Seiten: Shortcode in die Elementor-Vorlage (Theme Builder) setzen.', 'b83-shortcodes' ) . '</li>';
		echo '</ul><p class="description">' . esc_html__( 'Tracking- und Marketing-Scripts brauchen in der EU in der Regel eine Einwilligung (Cookie-Banner).', 'b83-shortcodes' ) . '</p></div>';
	}

	/** @param array<string,mixed> $entry Shortcode. */
	private function render_usage_box( array $entry ): void {
		$usage = $this->cache->usage( (string) $entry['tag'] );

		echo '<div class="b83-sc-box"><h3>' . esc_html__( 'Verwendet in', 'b83-shortcodes' ) . '</h3>';
		if ( empty( $usage ) ) {
			echo '<p>' . esc_html__( 'Keine Seiten gefunden (gesucht in Inhalten und Elementor-Daten). Widgets und Theme-Einstellungen werden nicht durchsucht.', 'b83-shortcodes' ) . '</p>';
		} else {
			echo '<ul class="b83-sc-usage">';
			foreach ( $usage as $post ) {
				$type_obj = get_post_type_object( $post['type'] );
				$type     = $type_obj ? (string) $type_obj->labels->singular_name : $post['type'];
				$title    = '' !== $post['title'] ? $post['title'] : __( '(ohne Titel)', 'b83-shortcodes' );
				$link     = get_edit_post_link( $post['id'], 'raw' );
				echo '<li>';
				echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
				echo ' <span class="description">(' . esc_html( $type );
				if ( 'publish' !== $post['status'] ) {
					echo ', ' . esc_html( $post['status'] );
				}
				echo ')</span></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/** @param array<string,mixed> $entry Shortcode. */
	private function render_previous_box( array $entry ): void {
		if ( empty( $entry['previous'] ) ) {
			return;
		}
		echo '<div class="b83-sc-box"><h3>' . esc_html__( 'Vorherige Fassung', 'b83-shortcodes' ) . '</h3>';
		if ( '' !== (string) $entry['previous']['saved_at'] ) {
			/* translators: %s: Datum */
			echo '<p>' . esc_html( sprintf( __( 'Stand vom %s.', 'b83-shortcodes' ), (string) $entry['previous']['saved_at'] ) ) . '</p>';
		}
		echo '<details><summary>' . esc_html__( 'Code ansehen', 'b83-shortcodes' ) . '</summary><pre class="b83-sc-previous">' . esc_html( (string) $entry['previous']['code'] );
		if ( '' !== (string) $entry['previous']['footer_code'] ) {
			echo "\n\n<!-- " . esc_html__( 'Seitenende', 'b83-shortcodes' ) . " -->\n" . esc_html( (string) $entry['previous']['footer_code'] );
		}
		echo '</pre></details>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b83-sc-confirm-restore">';
		echo '<input type="hidden" name="action" value="b83_sc_restore">';
		echo '<input type="hidden" name="entry_id" value="' . esc_attr( (string) $entry['id'] ) . '">';
		wp_nonce_field( 'b83_sc_restore_' . $entry['id'] );
		echo '<button type="submit" class="button">' . esc_html__( 'Wiederherstellen', 'b83-shortcodes' ) . '</button>';
		echo '</form></div>';
	}
}
