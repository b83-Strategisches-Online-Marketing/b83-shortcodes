<?php
/**
 * Berechtigungen.
 *
 * Shortcodes enthalten ungefiltertes HTML und JavaScript. Verwalten darf sie
 * daher nur, wer Einstellungen ändern (manage_options) UND ungefiltertes HTML
 * speichern darf (unfiltered_html). Damit gelten automatisch die WordPress-
 * Regeln: Auf Multisite nur Super-Admins, mit DISALLOW_UNFILTERED_HTML niemand.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/** Capability für Menüpunkt und Ansicht. */
	public const VIEW = 'manage_options';

	/** Darf die Übersicht sehen. */
	public static function can_view(): bool {
		return current_user_can( self::VIEW );
	}

	/** Darf Shortcodes anlegen, ändern, löschen, importieren und Einstellungen ändern. */
	public static function can_manage(): bool {
		$allowed = current_user_can( self::VIEW ) && current_user_can( 'unfiltered_html' );

		/**
		 * Filtert, ob der aktuelle Benutzer Shortcodes verwalten darf.
		 * Achtung: Wer Shortcodes verwaltet, kann beliebiges JavaScript ausspielen.
		 *
		 * @param bool $allowed Standard: manage_options und unfiltered_html.
		 */
		return (bool) apply_filters( 'b83_shortcodes_can_manage', $allowed );
	}
}
