<?php
/**
 * Speichern und Laden der Shortcodes und Einstellungen.
 *
 * Die Shortcodes liegen – wie in Version 1.x – als Liste in der Option
 * b83_shortcodes_entries. Neue Felder haben Standardwerte, die dem alten
 * Verhalten entsprechen.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public const OPTION_ENTRIES  = 'b83_shortcodes_entries';
	public const OPTION_SETTINGS = 'b83_shortcodes_settings';
	public const MAX_ENTRIES     = 300;

	/** @var array<int, array<string,mixed>>|null Zwischenspeicher je Seitenaufruf. */
	private ?array $entries = null;

	/* ---------------------------------------------------------------------
	 * Lesen
	 * ------------------------------------------------------------------- */

	/**
	 * Alle Shortcodes.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function all(): array {
		if ( null !== $this->entries ) {
			return $this->entries;
		}

		$raw   = get_option( self::OPTION_ENTRIES, array() );
		$clean = array();
		$fixed = false;
		$tags  = array();

		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$entry = is_array( $item ) ? Sanitizer::stored_entry( $item ) : null;
			if ( null === $entry || isset( $tags[ $entry['tag'] ] ) ) {
				$fixed = true;
				continue;
			}
			if ( ( $item['id'] ?? '' ) !== $entry['id'] ) {
				// ID fehlte oder war ungültig: einmalig dauerhaft speichern, sonst wäre der Eintrag nicht bearbeitbar.
				$fixed = true;
			}
			$tags[ $entry['tag'] ] = true;
			$clean[]               = $entry;
		}

		$this->entries = $clean;
		if ( $fixed ) {
			$this->persist( $clean );
		}
		return $clean;
	}

	/** @return array<string,mixed>|null */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_tag( string $tag ): ?array {
		foreach ( $this->all() as $entry ) {
			if ( $entry['tag'] === $tag ) {
				return $entry;
			}
		}
		return null;
	}

	/** Ist der Name von WordPress oder einem anderen Plugin belegt? */
	public function is_foreign_tag( string $tag ): bool {
		return shortcode_exists( $tag ) && null === $this->find_by_tag( $tag );
	}

	/* ---------------------------------------------------------------------
	 * Schreiben
	 * ------------------------------------------------------------------- */

	/**
	 * Neuen Shortcode anlegen oder bestehenden aktualisieren.
	 *
	 * @param array<string,mixed> $input Bereits mit wp_unslash übernommene Formulardaten.
	 * @param string              $id    Leer für neuen Eintrag.
	 * @return array<string,mixed>|\WP_Error Gespeicherter Eintrag.
	 */
	public function save( array $input, string $id = '' ): array|\WP_Error {
		$entries = $this->all();
		$old     = '' !== $id ? $this->find( $id ) : null;

		if ( '' !== $id && null === $old ) {
			return new \WP_Error( 'b83_sc_not_found', __( 'Der Shortcode wurde nicht gefunden.', 'b83-shortcodes' ) );
		}
		if ( null === $old && count( $entries ) >= self::MAX_ENTRIES ) {
			return new \WP_Error( 'b83_sc_limit', __( 'Die maximale Anzahl an Shortcodes ist erreicht.', 'b83-shortcodes' ) );
		}

		$code = Sanitizer::code( $input['code'] ?? '' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		$footer = Sanitizer::code( $input['footer_code'] ?? '' );
		if ( is_wp_error( $footer ) ) {
			return $footer;
		}

		$title = Sanitizer::text( $input['title'] ?? '', Sanitizer::MAX_TITLE );
		if ( '' === $title ) {
			$title = __( 'Shortcode', 'b83-shortcodes' );
		}

		$tag = $this->resolve_tag( (string) ( $input['tag'] ?? '' ), $title, $old['id'] ?? '', ! empty( $input['tag_auto'] ) );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}

		$entry = array(
			'id'                  => $old['id'] ?? wp_generate_uuid4(),
			'title'               => $title,
			'description'         => Sanitizer::textarea( $input['description'] ?? '', Sanitizer::MAX_DESCRIPTION ),
			'tag'                 => $tag,
			'code'                => $code,
			'footer_code'         => $footer,
			'active'              => ! empty( $input['active'] ),
			'builder_placeholder' => ! empty( $input['builder_placeholder'] ),
			'updated_at'          => current_time( 'mysql' ),
			'previous'            => $old['previous'] ?? null,
		);

		// Vorherige Fassung des Codes für „Wiederherstellen“ merken (nur wenn sich der Code ändert).
		if ( null !== $old && ( $old['code'] !== $code || $old['footer_code'] !== $footer ) ) {
			$entry['previous'] = array(
				'code'        => $old['code'],
				'footer_code' => $old['footer_code'],
				'saved_at'    => (string) $old['updated_at'],
			);
		}

		/**
		 * Filtert einen Shortcode unmittelbar vor dem Speichern.
		 *
		 * @param array<string,mixed>      $entry Neuer Stand.
		 * @param array<string,mixed>|null $old   Bisheriger Stand (null bei neuen Einträgen).
		 */
		$entry = (array) apply_filters( 'b83_shortcodes_before_save', $entry, $old );

		$this->replace_or_append( $entry );

		/**
		 * Nach dem Speichern eines Shortcodes.
		 *
		 * @param array<string,mixed>      $entry Neuer Stand.
		 * @param array<string,mixed>|null $old   Bisheriger Stand.
		 */
		do_action( 'b83_shortcodes_saved', $entry, $old );

		return $entry;
	}

	/** Aktiv/inaktiv umschalten. */
	public function set_active( string $id, bool $active ): ?array {
		$entry = $this->find( $id );
		if ( null === $entry ) {
			return null;
		}
		$old             = $entry;
		$entry['active'] = $active;
		$this->replace_or_append( $entry );
		do_action( 'b83_shortcodes_saved', $entry, $old );
		return $entry;
	}

	/** Vorherige Fassung des Codes wiederherstellen (aktuelle wird zur vorherigen). */
	public function restore_previous( string $id ): ?array {
		$entry = $this->find( $id );
		if ( null === $entry || empty( $entry['previous'] ) ) {
			return null;
		}
		$old                  = $entry;
		$entry['code']        = (string) $old['previous']['code'];
		$entry['footer_code'] = (string) $old['previous']['footer_code'];
		$entry['previous']    = array(
			'code'        => $old['code'],
			'footer_code' => $old['footer_code'],
			'saved_at'    => (string) $old['updated_at'],
		);
		$entry['updated_at']  = current_time( 'mysql' );
		$this->replace_or_append( $entry );
		do_action( 'b83_shortcodes_saved', $entry, $old );
		return $entry;
	}

	/** Kopie anlegen (inaktiv, mit neuem Namen). */
	public function duplicate( string $id ): array|\WP_Error {
		$entry = $this->find( $id );
		if ( null === $entry ) {
			return new \WP_Error( 'b83_sc_not_found', __( 'Der Shortcode wurde nicht gefunden.', 'b83-shortcodes' ) );
		}
		return $this->save(
			array(
				/* translators: %s: Titel des Originals */
				'title'               => sprintf( __( '%s (Kopie)', 'b83-shortcodes' ), $entry['title'] ),
				'description'         => $entry['description'],
				'tag'                 => $entry['tag'] . '_kopie',
				'tag_auto'            => true,
				'code'                => $entry['code'],
				'footer_code'         => $entry['footer_code'],
				'active'              => false,
				'builder_placeholder' => $entry['builder_placeholder'],
			)
		);
	}

	/** @return array<string,mixed>|null Gelöschter Eintrag. */
	public function delete( string $id ): ?array {
		$entry = $this->find( $id );
		if ( null === $entry ) {
			return null;
		}
		$this->persist(
			array_values( array_filter( $this->all(), static fn( array $e ): bool => $e['id'] !== $id ) )
		);

		/**
		 * Nach dem Löschen eines Shortcodes.
		 *
		 * @param array<string,mixed> $entry Gelöschter Eintrag.
		 */
		do_action( 'b83_shortcodes_deleted', $entry );
		return $entry;
	}

	/**
	 * Import aus einer Export-Datei.
	 *
	 * @param array<int, mixed> $items     Einträge.
	 * @param bool              $overwrite Gleichnamige Shortcodes überschreiben.
	 * @return array{added:int, updated:int, skipped:int}
	 */
	public function import( array $items, bool $overwrite ): array {
		$result = array(
			'added'   => 0,
			'updated' => 0,
			'skipped' => 0,
		);

		foreach ( $items as $item ) {
			$clean = is_array( $item ) ? Sanitizer::stored_entry( $item ) : null;
			if ( null === $clean ) {
				++$result['skipped'];
				continue;
			}
			$existing = $this->find_by_tag( $clean['tag'] );
			if ( null !== $existing && ! $overwrite ) {
				++$result['skipped'];
				continue;
			}

			$input = array(
				'title'               => $clean['title'],
				'description'         => $clean['description'],
				'tag'                 => $clean['tag'],
				'code'                => $clean['code'],
				'footer_code'         => $clean['footer_code'],
				'active'              => $clean['active'],
				'builder_placeholder' => $clean['builder_placeholder'],
			);
			$saved = $this->save( $input, $existing['id'] ?? '' );

			if ( is_wp_error( $saved ) ) {
				++$result['skipped'];
			} elseif ( null !== $existing ) {
				++$result['updated'];
			} else {
				++$result['added'];
			}
		}
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------------- */

	/** @return array{purge_cache: bool, delete_on_uninstall: bool} */
	public function get_settings(): array {
		$raw = get_option( self::OPTION_SETTINGS, array() );
		return Sanitizer::settings( is_array( $raw ) ? $raw : array() );
	}

	/** @param array<mixed> $settings Einstellungen. */
	public function save_settings( array $settings ): void {
		update_option( self::OPTION_SETTINGS, Sanitizer::settings( $settings ), false );
	}

	/* ---------------------------------------------------------------------
	 * Intern
	 * ------------------------------------------------------------------- */

	/**
	 * Shortcode-Namen bestimmen.
	 *  - Selbst eingegeben: muss frei sein, sonst Fehlermeldung.
	 *  - Aus dem Titel erzeugt (oder $auto): bei Bedarf mit _2, _3 … eindeutig gemacht.
	 */
	private function resolve_tag( string $wanted, string $title, string $current_id, bool $auto = false ): string|\WP_Error {
		$explicit = '' !== trim( $wanted );
		$base     = Sanitizer::tag( $explicit ? $wanted : $title );
		if ( '' === $base ) {
			$base = 'b83_shortcode';
		}

		if ( $explicit && ! $auto ) {
			if ( $this->tag_taken_by_other( $base, $current_id ) ) {
				return new \WP_Error(
					'b83_sc_tag_taken',
					/* translators: %s: Shortcode-Name */
					sprintf( __( 'Der Shortcode-Name [%s] ist bereits vergeben.', 'b83-shortcodes' ), $base )
				);
			}
			if ( $this->is_foreign_tag( $base ) ) {
				return new \WP_Error(
					'b83_sc_tag_foreign',
					/* translators: %s: Shortcode-Name */
					sprintf( __( 'Der Shortcode-Name [%s] wird bereits von WordPress oder einem anderen Plugin verwendet. Bitte einen anderen Namen wählen, sonst würde dessen Funktion überschrieben.', 'b83-shortcodes' ), $base )
				);
			}
			return $base;
		}

		$tag = $base;
		$i   = 2;
		while ( $this->tag_taken_by_other( $tag, $current_id ) || $this->is_foreign_tag( $tag ) ) {
			$suffix = '_' . $i;
			$tag    = substr( $base, 0, Sanitizer::MAX_TAG - strlen( $suffix ) ) . $suffix;
			++$i;
		}
		return $tag;
	}

	private function tag_taken_by_other( string $tag, string $current_id ): bool {
		$found = $this->find_by_tag( $tag );
		return null !== $found && $found['id'] !== $current_id;
	}

	/** @param array<string,mixed> $entry Eintrag. */
	private function replace_or_append( array $entry ): void {
		$entries = $this->all();
		$done    = false;
		foreach ( $entries as $i => $existing ) {
			if ( $existing['id'] === $entry['id'] ) {
				$entries[ $i ] = $entry;
				$done          = true;
				break;
			}
		}
		if ( ! $done ) {
			$entries[] = $entry;
		}
		$this->persist( $entries );
	}

	/**
	 * Dauerhaft speichern. Autoload ist aktiv, weil die Shortcodes bei jedem
	 * Seitenaufruf registriert werden.
	 *
	 * @param array<int, array<string,mixed>> $entries Einträge.
	 */
	private function persist( array $entries ): void {
		$entries       = array_values( $entries );
		$this->entries = $entries;
		update_option( self::OPTION_ENTRIES, $entries, true );
	}
}
