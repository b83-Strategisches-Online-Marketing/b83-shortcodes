<?php
/**
 * Registriert die Shortcodes und gibt sie aus.
 *
 * Je Shortcode gibt es zwei Code-Felder:
 *  - „An der Stelle des Shortcodes“: wird bei jedem Vorkommen ausgegeben.
 *  - „Am Seitenende“: wird höchstens einmal pro Seite vor </body> ausgegeben,
 *    auch wenn der Shortcode mehrfach vorkommt – ideal für Scripts.
 *
 * Zusätzlich: Schutz vor Endlosschleifen (Shortcode ruft sich selbst auf)
 * und optional ein Platzhalter im Page-Builder-Editor (z. B. Elementor).
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes\Frontend;

use B83\Shortcodes\Repository;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** @var string[] IDs der Shortcodes, die gerade ausgegeben werden (Schleifenschutz). */
	private array $stack = array();

	/** @var array<string,string> Code für das Seitenende, je Shortcode-ID. */
	private array $footer = array();

	/** @var array<string,bool> Namen, die beim Registrieren schon von WordPress/anderen Plugins belegt waren. */
	private array $collisions = array();

	public function __construct( private Repository $repository ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_builder_styles' ) );
		add_action( 'wp_footer', array( $this, 'print_footer' ), 100 );
	}

	public function register_shortcodes(): void {
		foreach ( $this->repository->all() as $entry ) {
			if ( shortcode_exists( $entry['tag'] ) ) {
				// Wie in Version 1.x trotzdem registrieren (Kompatibilität), im Backend aber warnen.
				$this->collisions[ $entry['tag'] ] = true;
			}
			add_shortcode( $entry['tag'], array( $this, 'render' ) );
		}
	}

	/**
	 * Ausgabe eines Shortcodes.
	 *
	 * @param array<mixed>|string $atts    Attribute (derzeit nicht verwendet).
	 * @param string|null         $content Eingeschlossener Inhalt (nicht verwendet).
	 * @param string              $tag     Shortcode-Name.
	 */
	public function render( $atts = array(), $content = null, $tag = '' ): string {
		$entry = $this->repository->find_by_tag( (string) $tag );
		if ( null === $entry ) {
			return '';
		}

		$builder = $this->is_builder_preview();

		if ( ! $entry['active'] ) {
			return $builder ? $this->placeholder( $entry, __( 'inaktiv – wird auf der Website nicht ausgegeben', 'b83-shortcodes' ) ) : '';
		}
		if ( $builder && $entry['builder_placeholder'] ) {
			return $this->placeholder( $entry, __( 'Ausgabe im Editor ausgeblendet, auf der Website aktiv', 'b83-shortcodes' ) );
		}
		if ( in_array( $entry['id'], $this->stack, true ) ) {
			return '<!-- b83 Shortcodes: Endlosschleife bei [' . esc_html( $entry['tag'] ) . '] verhindert -->';
		}

		$this->stack[] = $entry['id'];

		// Bewusst ohne Filter: Der Code stammt von Benutzern mit unfiltered_html.
		$html = do_shortcode( (string) $entry['code'] );

		if ( '' !== trim( (string) $entry['footer_code'] ) && ! array_key_exists( $entry['id'], $this->footer ) ) {
			$footer_html = do_shortcode( (string) $entry['footer_code'] );

			if ( $this->footer_unavailable() ) {
				// Seitenende schon ausgegeben oder gibt es nicht (AJAX/REST): direkt anhängen.
				$html                         .= $footer_html;
				$this->footer[ $entry['id'] ] = '';
			} else {
				$this->footer[ $entry['id'] ] = $footer_html;
			}
		}

		array_pop( $this->stack );

		/**
		 * Filtert die Ausgabe eines Shortcodes an seiner Stelle.
		 *
		 * @param string              $html  Ausgabe.
		 * @param array<string,mixed> $entry Shortcode.
		 * @param array<mixed>|string $atts  Attribute aus dem Inhalt.
		 */
		return (string) apply_filters( 'b83_shortcodes_output', $html, $entry, $atts );
	}

	/** Gesammelten Code für das Seitenende ausgeben. */
	public function print_footer(): void {
		foreach ( $this->footer as $id => $html ) {
			if ( '' === $html ) {
				continue;
			}
			/**
			 * Filtert den Code, der am Seitenende ausgegeben wird.
			 *
			 * @param string $html Code.
			 * @param string $id   Shortcode-ID.
			 */
			$html = (string) apply_filters( 'b83_shortcodes_footer_output', $html, $id );

			// Ungefiltert ausgeben: Inhalt stammt ausschließlich von Benutzern mit unfiltered_html.
			echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->footer[ $id ] = '';
		}
	}

	/** Stile für den Platzhalter nur in der Editor-Vorschau laden. */
	public function enqueue_builder_styles(): void {
		if ( $this->is_builder_preview() ) {
			wp_enqueue_style( 'b83-shortcodes-builder', B83_SC_URL . 'assets/css/builder.css', array(), B83_SC_VERSION );
		}
	}

	/**
	 * Wird die Seite gerade in einem Page-Builder-Editor angezeigt?
	 * Unterstützt Elementor sowie die Vorschau von Beaver Builder, Divi,
	 * Bricks und Oxygen. Nur lesende Prüfung von Parametern.
	 */
	public function is_builder_preview(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Erkennung, keine Aktion.
		$is = isset( $_GET['elementor-preview'] )
			|| ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'elementor_ajax' === $_REQUEST['action'] )
			|| isset( $_GET['fl_builder'] )
			|| isset( $_GET['et_fb'] )
			|| ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] )
			|| isset( $_GET['ct_builder'] );
		// phpcs:enable

		if ( ! $is && class_exists( '\Elementor\Plugin', false ) && isset( \Elementor\Plugin::$instance ) ) {
			$elementor = \Elementor\Plugin::$instance;
			$is        = ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() )
				|| ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() );
		}

		// Nur für angemeldete Benutzer mit Bearbeitungsrechten – Besucher sehen nie Platzhalter.
		$is = $is && is_user_logged_in() && current_user_can( 'edit_posts' );

		/**
		 * Filtert, ob gerade eine Page-Builder-Vorschau angezeigt wird.
		 *
		 * @param bool $is Ergebnis der Erkennung.
		 */
		return (bool) apply_filters( 'b83_shortcodes_is_builder_preview', $is );
	}

	/** Hat dieser Shortcode beim Registrieren einen gleichnamigen von WordPress/einem anderen Plugin ersetzt? */
	public function replaces_foreign( string $tag ): bool {
		return isset( $this->collisions[ $tag ] );
	}

	/** Wurde der Shortcode von einem anderen Plugin überschrieben? */
	public function is_overridden( string $tag ): bool {
		global $shortcode_tags;
		if ( ! isset( $shortcode_tags[ $tag ] ) ) {
			return false;
		}
		$callback = $shortcode_tags[ $tag ];
		return ! ( is_array( $callback ) && ( $callback[0] ?? null ) === $this && 'render' === ( $callback[1] ?? '' ) );
	}

	private function footer_unavailable(): bool {
		return did_action( 'wp_footer' ) > 0 || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/** @param array<string,mixed> $entry Shortcode. */
	private function placeholder( array $entry, string $note ): string {
		return sprintf(
			'<div class="b83-shortcodes-placeholder"><strong>[%1$s]</strong> %2$s<br><span>%3$s</span></div>',
			esc_html( (string) $entry['tag'] ),
			esc_html( (string) $entry['title'] ),
			esc_html( $note )
		);
	}
}
