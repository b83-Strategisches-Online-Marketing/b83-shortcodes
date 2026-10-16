<?php
/**
 * Verwendung der Shortcodes finden und Seiten-Cache nach Änderungen leeren.
 *
 * Gesucht wird in Beitragsinhalten (Block-Editor, Classic Editor) und in den
 * Elementor-Daten. Steht ein Shortcode in einer Vorlage (z. B. Elementor
 * Theme Builder: Header/Footer), wird der gesamte Cache geleert, weil die
 * Vorlage auf beliebig vielen Seiten erscheinen kann.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes\Integrations;

use B83\Shortcodes\Repository;

defined( 'ABSPATH' ) || exit;

final class CachePurge {

	private const TRANSIENT_USAGE = 'b83_shortcodes_usage';
	private const MAX_RESULTS     = 200;

	/** Beitragstypen, die selbst keine eigene Seite sind, sondern in andere Seiten eingebunden werden. */
	private const TEMPLATE_TYPES = array( 'elementor_library', 'wp_template', 'wp_template_part', 'wp_block', 'ct_template', 'bricks_template', 'fl-builder-template', 'et_pb_layout' );

	public function __construct( private Repository $repository ) {}

	public function register(): void {
		add_action( 'b83_shortcodes_saved', array( $this, 'on_saved' ), 10, 2 );
		add_action( 'b83_shortcodes_deleted', array( $this, 'on_deleted' ) );
		add_action( 'save_post', array( $this, 'forget_usage' ) );
		add_action( 'deleted_post', array( $this, 'forget_usage' ) );
	}

	public function forget_usage(): void {
		delete_transient( self::TRANSIENT_USAGE );
	}

	/**
	 * @param array<string,mixed>      $entry Neuer Stand.
	 * @param array<string,mixed>|null $old   Bisheriger Stand.
	 */
	public function on_saved( array $entry, ?array $old ): void {
		$tags = array( (string) $entry['tag'] );
		if ( null !== $old && $old['tag'] !== $entry['tag'] ) {
			$tags[] = (string) $old['tag'];
		}
		$this->purge( $tags );
	}

	/** @param array<string,mixed> $entry Gelöschter Eintrag. */
	public function on_deleted( array $entry ): void {
		$this->purge( array( (string) $entry['tag'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Verwendung
	 * ------------------------------------------------------------------- */

	/**
	 * Beiträge/Seiten/Vorlagen, in denen der Shortcode vorkommt.
	 * Ergebnis wird 12 Stunden zwischengespeichert und beim Speichern eines Beitrags verworfen.
	 *
	 * @return array<int, array{id:int, title:string, type:string, status:string}>
	 */
	public function usage( string $tag ): array {
		$cache = get_transient( self::TRANSIENT_USAGE );
		$cache = is_array( $cache ) ? $cache : array();
		if ( isset( $cache[ $tag ] ) && is_array( $cache[ $tag ] ) ) {
			return $cache[ $tag ];
		}

		global $wpdb;

		// Vorkommen: [tag] [tag … [tag/ – in Elementor-JSON wird „/“ als „\/“ gespeichert.
		$likes = array();
		foreach ( array( ']', ' ', '/', '\\/' ) as $end ) {
			$likes[] = '%' . $wpdb->esc_like( '[' . $tag . $end ) . '%';
		}

		$statuses = "'publish','future','draft','pending','private'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ergebnis wird per Transient gecacht.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $statuses ist eine feste Liste.
		$from_content = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status IN ($statuses) AND post_type <> 'revision'
				AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
				LIMIT %d",
				$likes[0],
				$likes[1],
				$likes[2],
				$likes[3],
				self::MAX_RESULTS
			)
		);
		$from_meta = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_elementor_data' AND p.post_status IN ($statuses) AND p.post_type <> 'revision'
				AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s )
				LIMIT %d",
				$likes[0],
				$likes[1],
				$likes[2],
				$likes[3],
				self::MAX_RESULTS
			)
		);
		// phpcs:enable

		$ids    = array_slice( array_values( array_unique( array_map( 'intval', array_merge( (array) $from_content, (array) $from_meta ) ) ) ), 0, self::MAX_RESULTS );
		$result = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$result[] = array(
				'id'     => (int) $post->ID,
				'title'  => (string) get_the_title( $post ),
				'type'   => (string) $post->post_type,
				'status' => (string) $post->post_status,
			);
		}

		$cache[ $tag ] = $result;
		set_transient( self::TRANSIENT_USAGE, $cache, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Cache leeren
	 * ------------------------------------------------------------------- */

	/** @param string[] $tags Betroffene Shortcode-Namen. */
	public function purge( array $tags ): void {
		if ( ! $this->repository->get_settings()['purge_cache'] ) {
			return;
		}

		$this->forget_usage();
		$ids      = array();
		$template = false;
		foreach ( array_unique( $tags ) as $tag ) {
			foreach ( $this->usage( $tag ) as $post ) {
				if ( in_array( $post['type'], self::TEMPLATE_TYPES, true ) ) {
					$template = true;
				} elseif ( 'publish' === $post['status'] ) {
					$ids[] = $post['id'];
				}
			}
		}

		/**
		 * Filtert die IDs der Seiten, deren Cache nach dem Speichern geleert wird.
		 *
		 * @param int[]    $ids  Beitrags-/Seiten-IDs.
		 * @param string[] $tags Betroffene Shortcodes.
		 */
		$ids = array_map( 'intval', (array) apply_filters( 'b83_shortcodes_purge_post_ids', array_values( array_unique( $ids ) ), $tags ) );

		/**
		 * Filtert, ob der gesamte Seiten-Cache geleert wird (Standard: wenn der Shortcode in einer Vorlage steht).
		 *
		 * @param bool     $full Gesamten Cache leeren.
		 * @param string[] $tags Betroffene Shortcodes.
		 */
		$full = (bool) apply_filters( 'b83_shortcodes_purge_all', $template, $tags );

		if ( $full ) {
			$this->purge_all();
		} else {
			foreach ( $ids as $id ) {
				$this->purge_post( $id );
			}
		}

		/**
		 * Nach dem Leeren des Caches – für weitere Cache-Lösungen.
		 *
		 * @param int[] $ids  Beitrags-/Seiten-IDs.
		 * @param bool  $full Gesamter Cache wurde geleert.
		 */
		do_action( 'b83_shortcodes_purge_cache', $ids, $full );
	}

	private function purge_post( int $id ): void {
		clean_post_cache( $id );

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $id ); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $id ); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $id ); // WP Super Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( (string) get_permalink( $id ) ); // SiteGround.
		}
		do_action( 'litespeed_purge_post', $id ); // LiteSpeed Cache.
	}

	private function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			sg_cachepress_purge_everything(); // SiteGround.
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache.
	}

	/** Name des erkannten Cache-Plugins für die Anzeige im Backend ('' wenn keins). */
	public static function detected_plugin(): string {
		return match ( true ) {
			function_exists( 'rocket_clean_post' )         => 'WP Rocket',
			defined( 'LSCWP_V' )                           => 'LiteSpeed Cache',
			function_exists( 'w3tc_flush_post' )           => 'W3 Total Cache',
			function_exists( 'wp_cache_post_change' )      => 'WP Super Cache',
			function_exists( 'sg_cachepress_purge_cache' ) => 'SiteGround Speed Optimizer',
			default                                        => '',
		};
	}
}
