<?php
/**
 * Zentrale Prüfung und Bereinigung aller Eingaben (Whitelist, Längenlimits).
 *
 * Hinweis zum Code-Feld: HTML, CSS und JavaScript werden bewusst NICHT
 * gefiltert – genau dafür ist das Plugin da. Abgesichert wird das über die
 * Berechtigung (siehe Capabilities: nur mit unfiltered_html).
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class Sanitizer {

	public const MAX_TITLE       = 150;
	public const MAX_DESCRIPTION = 1000;
	public const MAX_TAG         = 64;
	public const MAX_CODE_BYTES  = 262144; // 256 KB je Code-Feld.

	/**
	 * Shortcode-Namen vereinheitlichen: Kleinbuchstaben, Ziffern, Unterstrich.
	 * (Gleiche Regeln wie in Version 1.x, damit bestehende Namen gleich bleiben.)
	 */
	public static function tag( string $tag ): string {
		$tag = strtolower( remove_accents( $tag ) );
		$tag = (string) preg_replace( '/[^a-z0-9_]+/', '_', $tag );
		$tag = trim( substr( $tag, 0, self::MAX_TAG ), '_' );
		return $tag;
	}

	/** Kurzer Text ohne HTML, auf Länge gekürzt. */
	public static function text( mixed $value, int $max ): string {
		$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		return mb_substr( $value, 0, $max );
	}

	/** Mehrzeiliger Text ohne HTML, auf Länge gekürzt. */
	public static function textarea( mixed $value, int $max ): string {
		$value = sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
		return mb_substr( $value, 0, $max );
	}

	/**
	 * Code-Feld: unverändert übernehmen, aber Größe begrenzen.
	 *
	 * @return string|\WP_Error
	 */
	public static function code( mixed $value ): string|\WP_Error {
		$value = is_scalar( $value ) ? (string) $value : '';
		// Zeilenenden vereinheitlichen und ungültige UTF-8-Zeichen entfernen.
		$value = str_replace( "\r\n", "\n", $value );
		$value = wp_check_invalid_utf8( $value, true );

		if ( strlen( $value ) > self::MAX_CODE_BYTES ) {
			return new \WP_Error(
				'b83_sc_code_too_long',
				/* translators: %d: maximale Größe in KB */
				sprintf( __( 'Der Code ist zu groß (maximal %d KB je Feld).', 'b83-shortcodes' ), (int) ( self::MAX_CODE_BYTES / 1024 ) )
			);
		}
		return $value;
	}

	/** Eindeutige ID (UUID) prüfen, sonst neue erzeugen. */
	public static function id( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^[a-z0-9-]{8,64}$/', $value ) ? $value : wp_generate_uuid4();
	}

	/**
	 * Gespeicherten bzw. importierten Eintrag in die aktuelle Struktur bringen.
	 * Unbekannte Felder werden verworfen.
	 *
	 * @param array<mixed> $raw Rohdaten.
	 * @return array<string,mixed>|null Null, wenn der Eintrag unbrauchbar ist.
	 */
	public static function stored_entry( array $raw ): ?array {
		$title = self::text( $raw['title'] ?? ( $raw['name'] ?? '' ), self::MAX_TITLE );
		$tag   = self::tag( is_scalar( $raw['tag'] ?? null ) ? (string) $raw['tag'] : '' );
		if ( '' === $tag ) {
			return null;
		}

		$code   = self::code( $raw['code'] ?? '' );
		$footer = self::code( $raw['footer_code'] ?? '' );

		$previous = null;
		if ( isset( $raw['previous'] ) && is_array( $raw['previous'] ) ) {
			$prev_code   = self::code( $raw['previous']['code'] ?? '' );
			$prev_footer = self::code( $raw['previous']['footer_code'] ?? '' );
			if ( is_string( $prev_code ) && is_string( $prev_footer ) ) {
				$previous = array(
					'code'        => $prev_code,
					'footer_code' => $prev_footer,
					'saved_at'    => self::text( $raw['previous']['saved_at'] ?? '', 30 ),
				);
			}
		}

		return array(
			'id'                  => self::id( $raw['id'] ?? '' ),
			'title'               => '' !== $title ? $title : __( 'Shortcode', 'b83-shortcodes' ),
			'description'         => self::textarea( $raw['description'] ?? '', self::MAX_DESCRIPTION ),
			'tag'                 => $tag,
			'code'                => is_string( $code ) ? $code : '',
			'footer_code'         => is_string( $footer ) ? $footer : '',
			'active'              => ! isset( $raw['active'] ) || (bool) $raw['active'],
			'builder_placeholder' => ! empty( $raw['builder_placeholder'] ),
			'updated_at'          => self::text( $raw['updated_at'] ?? '', 30 ),
			'previous'            => $previous,
		);
	}

	/**
	 * Einstellungen.
	 *
	 * @param array<mixed> $raw Rohdaten.
	 * @return array{purge_cache: bool, delete_on_uninstall: bool}
	 */
	public static function settings( array $raw ): array {
		return array(
			'purge_cache'         => ! isset( $raw['purge_cache'] ) || (bool) $raw['purge_cache'],
			'delete_on_uninstall' => ! empty( $raw['delete_on_uninstall'] ),
		);
	}
}
