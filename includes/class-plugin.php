<?php
/**
 * Main plugin class — singleton, boot sequence, activation/deactivation.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Plugin
 *
 * Orchestrates all subsystems. Instantiated once via get_instance().
 */
class LWIA_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var LWIA_Plugin|null
	 */
	private static ?LWIA_Plugin $instance = null;

	/**
	 * Return the single instance, creating it if necessary.
	 *
	 * @return LWIA_Plugin
	 */
	public static function get_instance(): LWIA_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Require all class files.
	 *
	 * Order matters: Logger before Updater (Updater calls Logger),
	 * Updater before Admin/Ajax/CLI (they call Updater).
	 */
	private function load_dependencies(): void {
		$dir = LWIA_PLUGIN_DIR . 'includes/';

		require_once $dir . 'class-logger.php';
		require_once $dir . 'class-updater.php';
		require_once $dir . 'class-scanner.php';
		require_once $dir . 'class-csv-export.php';
		require_once $dir . 'class-csv-import.php';
		require_once $dir . 'class-undo.php';
		require_once $dir . 'class-admin.php';
		require_once $dir . 'class-ajax.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-cli.php';
		}
	}

	/**
	 * Register WordPress hooks for each subsystem.
	 */
	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Flush scanner cache whenever a new attachment is uploaded, so the next
		// scan reflects the newly added image immediately.
		add_action( 'add_attachment', array( 'LWIA_Scanner', 'flush_cache' ) );

		// Admin screens — only in the admin context (includes admin-ajax.php).
		if ( is_admin() ) {
			new LWIA_Admin();
			new LWIA_Ajax();
		}
	}

	/**
	 * Load plugin text domain for i18n.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'lw-img-alt',
			false,
			dirname( plugin_basename( LWIA_PLUGIN_FILE ) ) . '/languages'
		);
	}

	// -------------------------------------------------------------------------
	// Activation / deactivation
	// -------------------------------------------------------------------------

	/**
	 * Runs on plugin activation.
	 *
	 * Creates the log table and stores the DB schema version.
	 * Must be static — called before the singleton exists.
	 */
	public static function activate(): void {
		// Bootstrap the logger directly; the singleton is not running yet.
		if ( ! class_exists( 'LWIA_Logger' ) ) {
			require_once LWIA_PLUGIN_DIR . 'includes/class-logger.php';
		}

		LWIA_Logger::create_table();

		update_option( 'lwia_db_version', LWIA_DB_VERSION );
		update_option( 'lwia_activated', true );

		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * No data is removed here — that is handled by uninstall.php.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
