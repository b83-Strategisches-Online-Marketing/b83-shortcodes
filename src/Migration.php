<?php
/**
 * Installation und Datenbank-Versionen.
 *
 * DB-Version 1 (Plugin 2.0.0): Einträge aus Version 1.x in die neue Struktur
 * bringen (fehlende IDs dauerhaft vergeben, neue Felder mit Standardwerten)
 * und die Option automatisch laden lassen.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class Migration {

	public const DB_VERSION        = 1;
	public const OPTION_DB_VERSION = 'b83_shortcodes_db_version';

	/** Export-Kennung. */
	public const EXPORT_FORMAT = 'b83-shortcodes';

	public static function on_activation(): void {
		self::run();
	}

	/** Läuft bei jedem Seitenaufruf im Backend, tut aber nur bei Versionswechsel etwas. */
	public static function maybe_run(): void {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < self::DB_VERSION ) {
			self::run();
		}
	}

	private static function run(): void {
		$from = (int) get_option( self::OPTION_DB_VERSION, 0 );

		if ( $from < 1 ) {
			self::to_v1();
		}

		if ( false === get_option( Repository::OPTION_SETTINGS, false ) ) {
			add_option( Repository::OPTION_SETTINGS, Sanitizer::settings( array() ), '', false );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	private static function to_v1(): void {
		$raw = get_option( Repository::OPTION_ENTRIES, null );
		if ( ! is_array( $raw ) ) {
			return;
		}

		$entries = array();
		$tags    = array();
		foreach ( $raw as $item ) {
			$entry = is_array( $item ) ? Sanitizer::stored_entry( $item ) : null;
			if ( null === $entry || isset( $tags[ $entry['tag'] ] ) ) {
				continue;
			}
			$tags[ $entry['tag'] ] = true;
			$entries[]             = $entry;
		}

		update_option( Repository::OPTION_ENTRIES, $entries, true );

		// Version 1.x speicherte ohne Autoload – jetzt wird die Option bei jedem Aufruf gebraucht.
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Repository::OPTION_ENTRIES, true );
		}
	}

	/**
	 * Erkennt das Format einer importierten Datei und liefert die Einträge.
	 *
	 * @param array<mixed> $decoded JSON-Inhalt.
	 * @return array<int, mixed>|null Null bei unbekanntem Format.
	 */
	public static function normalize_import( array $decoded ): ?array {
		if ( ( $decoded['format'] ?? '' ) === self::EXPORT_FORMAT && isset( $decoded['entries'] ) && is_array( $decoded['entries'] ) ) {
			return array_values( $decoded['entries'] );
		}
		// Reine Liste von Einträgen (z. B. Inhalt der Option aus Version 1.x).
		if ( array_is_list( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) && isset( $decoded[0]['tag'] ) ) {
			return $decoded;
		}
		return null;
	}
}
