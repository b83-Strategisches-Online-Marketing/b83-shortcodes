<?php
/**
 * Einstiegspunkt: verdrahtet alle Komponenten.
 *
 * @package B83\Shortcodes
 */

declare(strict_types=1);

namespace B83\Shortcodes;

use B83\Shortcodes\Admin\AdminPage;
use B83\Shortcodes\Frontend\Renderer;
use B83\Shortcodes\Integrations\CachePurge;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Repository $repository;
	private Renderer $renderer;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repository = new Repository();
		$this->renderer   = new Renderer( $this->repository );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( Migration::class, 'maybe_run' ) );

		$cache = new CachePurge( $this->repository );
		$cache->register();

		( new Updater() )->register();
		$this->renderer->register();

		if ( is_admin() ) {
			( new AdminPage( $this->repository, $this->renderer, $cache ) )->register();
		}

		/**
		 * Nach dem Laden aller Komponenten. Erweiterungen können sich hier einhängen.
		 *
		 * @param Plugin $plugin Instanz.
		 */
		do_action( 'b83_shortcodes_loaded', $this );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'b83-shortcodes', false, dirname( plugin_basename( B83_SC_FILE ) ) . '/languages' );
	}

	public function repository(): Repository {
		return $this->repository;
	}

	public function renderer(): Renderer {
		return $this->renderer;
	}
}
