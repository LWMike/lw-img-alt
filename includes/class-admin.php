<?php
/**
 * Admin menu registration, screen callbacks, asset enqueues, and form handlers.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Admin
 */
class LWIA_Admin {

	/**
	 * Hook suffixes returned by add_*_page() — used to scope asset enqueuing.
	 *
	 * @var string[]
	 */
	private array $page_hooks = array();

	/**
	 * Constructor — registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// GET-based export — intercepted before the page renders.
		add_action( 'admin_init', array( $this, 'handle_export' ) );

		// POST-based handlers via admin-post.php.
		add_action( 'admin_post_lwia_import_upload', array( $this, 'handle_import_upload' ) );
		add_action( 'admin_post_lwia_undo_batch',    array( $this, 'handle_undo_batch' ) );
	}

	// =========================================================================
	// Menu registration
	// =========================================================================

	/**
	 * Register the top-level menu and submenus.
	 */
	public function register_menus(): void {
		add_menu_page(
			esc_html__( 'Image Alt Text', 'lw-img-alt' ),
			esc_html__( 'Image Alt', 'lw-img-alt' ),
			'upload_files',
			'lw-img-alt',
			array( $this, 'render_scan' ),
			'dashicons-format-image',
			80
		);

		// The auto-generated first submenu item mirrors the top-level page.
		// Re-add it with the label "Scan" so the menu reads cleanly.
		$this->page_hooks['scan'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Scan Results', 'lw-img-alt' ),
			esc_html__( 'Scan', 'lw-img-alt' ),
			'upload_files',
			'lw-img-alt',
			array( $this, 'render_scan' )
		);

		$this->page_hooks['import'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Import Alt Text', 'lw-img-alt' ),
			esc_html__( 'Import', 'lw-img-alt' ),
			'upload_files',
			'lwia-import',
			array( $this, 'render_import' )
		);

		$this->page_hooks['log'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Alt Text Change Log', 'lw-img-alt' ),
			esc_html__( 'Change Log', 'lw-img-alt' ),
			'upload_files',
			'lwia-log',
			array( $this, 'render_log' )
		);

		$this->page_hooks['undo'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Undo Changes', 'lw-img-alt' ),
			esc_html__( 'Undo', 'lw-img-alt' ),
			'manage_options',
			'lwia-undo',
			array( $this, 'render_undo' )
		);
	}

	// =========================================================================
	// Asset enqueuing
	// =========================================================================

	/**
	 * Enqueue CSS and JS only on plugin screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'lwia-admin',
			LWIA_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			LWIA_VERSION
		);

		wp_enqueue_script(
			'lwia-admin',
			LWIA_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			LWIA_VERSION,
			true
		);

		wp_localize_script(
			'lwia-admin',
			'lwiaData',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'lwia_save_alt' ),
				'importNonce'   => wp_create_nonce( 'lwia_import' ),
				'saving'        => esc_html__( 'Saving\u2026', 'lw-img-alt' ),
				'saved'         => esc_html__( 'Saved', 'lw-img-alt' ),
				'errorMsg'      => esc_html__( 'Error saving. Please try again.', 'lw-img-alt' ),
				'imageSingular' => esc_html__( 'image missing alt text', 'lw-img-alt' ),
				'imagePlural'   => esc_html__( 'images missing alt text', 'lw-img-alt' ),
			)
		);
	}

	// =========================================================================
	// Screen callbacks
	// =========================================================================

	/**
	 * Render the Scan screen.
	 */
	public function render_scan(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_page  = isset( $_GET['paged'] )     ? max( 1, absint( $_GET['paged'] ) )                               : 1;
		$filter_attach = isset( $_GET['attachment'] ) ? sanitize_key( wp_unslash( $_GET['attachment'] ) )                 : 'all';
		$filter_date_f = isset( $_GET['date_from'] )  ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )           : '';
		$filter_date_t = isset( $_GET['date_to'] )    ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )             : '';
		$filter_mime   = isset( $_GET['mime_type'] )  ? sanitize_mime_type( wp_unslash( (string) $_GET['mime_type'] ) )   : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 50;

		$scanner_args = array(
			'paged'      => $current_page,
			'per_page'   => $per_page,
			'attachment' => $filter_attach,
			'date_from'  => $filter_date_f,
			'date_to'    => $filter_date_t,
			'mime_type'  => $filter_mime,
		);

		$scanner   = new LWIA_Scanner();
		$total     = $scanner->get_total( $scanner_args );
		$rows      = $scanner->get_missing( $scanner_args );
		$lib_total = $scanner->get_library_total();

		$total_pages = ( $per_page > 0 ) ? (int) ceil( $total / $per_page ) : 1;

		// Prime parent-post cache to avoid N+1 queries for the "Attached to" column.
		$parent_ids = array_unique( array_filter( array_map( static fn( $r ) => (int) $r->post_parent, $rows ) ) );
		if ( $parent_ids ) {
			_prime_post_caches( $parent_ids, false, false );
		}

		$base_url = add_query_arg(
			array_filter( array(
				'page'       => 'lw-img-alt',
				'attachment' => ( 'all' !== $filter_attach ) ? $filter_attach : false,
				'date_from'  => $filter_date_f ?: false,
				'date_to'    => $filter_date_t ?: false,
				'mime_type'  => ( 'all' !== $filter_mime ) ? $filter_mime : false,
			) ),
			admin_url( 'admin.php' )
		);

		require LWIA_PLUGIN_DIR . 'admin/views/scan.php';
	}

	/**
	 * Render the Import screen (multi-step: upload → preview → done).
	 */
	public function render_import(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$step      = isset( $_GET['step'] )      ? sanitize_key( wp_unslash( $_GET['step'] ) )                        : 'upload';
		$import_id = isset( $_GET['import_id'] ) ? sanitize_text_field( wp_unslash( $_GET['import_id'] ) )            : '';
		$error     = isset( $_GET['error'] )     ? sanitize_key( wp_unslash( $_GET['error'] ) )                       : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$import_data = null;

		if ( 'preview' === $step && $import_id ) {
			$import_data = get_transient( $import_id );

			if ( false === $import_data ) {
				// Transient expired — send the user back to upload.
				$step  = 'upload';
				$error = 'expired';
			}
		}

		require LWIA_PLUGIN_DIR . 'admin/views/import.php';
	}

	/**
	 * Render the Change Log screen.
	 */
	public function render_log(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_page   = isset( $_GET['paged'] )    ? max( 1, absint( $_GET['paged'] ) )                              : 1;
		$filter_source  = isset( $_GET['source'] )   ? sanitize_key( wp_unslash( $_GET['source'] ) )                   : '';
		$filter_user_id = isset( $_GET['user_id'] )  ? absint( $_GET['user_id'] )                                      : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 50;

		$query_args = array_filter( array(
			'paged'    => $current_page,
			'per_page' => $per_page,
			'source'   => $filter_source ?: '',
			'user_id'  => $filter_user_id ?: 0,
		) );

		$entries     = LWIA_Logger::get_entries( $query_args );
		$total       = LWIA_Logger::get_total( $query_args );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		require LWIA_PLUGIN_DIR . 'admin/views/log.php';
	}

	/**
	 * Render the Undo screen.
	 */
	public function render_undo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$undo     = new LWIA_Undo();
		$batches  = $undo->get_batches( array( 'paged' => $current_page, 'per_page' => $per_page ) );
		$total    = $undo->get_batches_total();

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		// Read undo result from transient (set by handle_undo_batch after redirect).
		$undo_result_key = 'lwia_undo_result_' . get_current_user_id();
		$undo_result     = get_transient( $undo_result_key );
		if ( false !== $undo_result ) {
			delete_transient( $undo_result_key ); // Single-use.
		}

		require LWIA_PLUGIN_DIR . 'admin/views/undo.php';
	}

	// =========================================================================
	// Form handlers
	// =========================================================================

	/**
	 * Handle the CSV export download (GET request via admin_init).
	 *
	 * Triggered when admin.php?page=lw-img-alt&action=lwia_export_csv is requested.
	 * The _wpnonce was set by wp_nonce_url() in the scan view.
	 */
	public function handle_export(): void {
		if (
			! isset( $_GET['action'], $_GET['page'] )
			|| 'lwia_export_csv' !== $_GET['action']
			|| 'lw-img-alt' !== $_GET['page']
		) {
			return;
		}

		check_admin_referer( 'lwia_export_csv' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to export data.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above via check_admin_referer
		$filter_args = array(
			'attachment' => isset( $_GET['attachment'] ) ? sanitize_key( wp_unslash( $_GET['attachment'] ) )              : 'all',
			'date_from'  => isset( $_GET['date_from'] )  ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )        : '',
			'date_to'    => isset( $_GET['date_to'] )    ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )          : '',
			'mime_type'  => isset( $_GET['mime_type'] )  ? sanitize_mime_type( wp_unslash( (string) $_GET['mime_type'] ) ) : 'all',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		LWIA_CSV_Export::generate( $filter_args );
		exit;
	}

	/**
	 * Handle a CSV file upload and parse/validate it, then redirect to the preview step.
	 *
	 * Triggered by admin-post.php with action=lwia_import_upload.
	 */
	public function handle_import_upload(): void {
		check_admin_referer( 'lwia_import_upload' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to import data.', 'lw-img-alt' ) );
		}

		$redirect_base = admin_url( 'admin.php?page=lwia-import' );
		$tmp_path      = '';

		// Ensure a file was uploaded.
		if (
			empty( $_FILES['lwia_csv'] )
			|| ! isset( $_FILES['lwia_csv']['error'] )
			|| UPLOAD_ERR_OK !== $_FILES['lwia_csv']['error']
		) {
			wp_redirect( add_query_arg( 'error', 'no_file', $redirect_base ) );
			exit;
		}

		// --- Layer 1: extension check ---
		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['lwia_csv']['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			wp_redirect( add_query_arg( 'error', 'invalid_type', $redirect_base ) );
			exit;
		}

		// --- Layer 2: MIME sniff (WP) ---
		$check        = wp_check_filetype_and_ext(
			$_FILES['lwia_csv']['tmp_name'],
			sanitize_file_name( $_FILES['lwia_csv']['name'] ),
			array( 'csv' => 'text/csv' )
		);
		$allowed_mimes = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
		if ( empty( $check['ext'] ) && ! in_array( (string) $check['type'], $allowed_mimes, true ) ) {
			wp_redirect( add_query_arg( 'error', 'invalid_type', $redirect_base ) );
			exit;
		}

		// --- Move to a temporary location (outside wp-content/uploads to avoid public access) ---
		$tmp_path = get_temp_dir() . 'lwia-' . wp_generate_password( 16, false ) . '.csv';

		if ( ! move_uploaded_file( $_FILES['lwia_csv']['tmp_name'], $tmp_path ) ) {
			wp_redirect( add_query_arg( 'error', 'upload_failed', $redirect_base ) );
			exit;
		}

		// --- Layer 3: content sniff — first line must contain required column headers ---
		$importer = new LWIA_CSV_Import();
		$parsed   = $importer->parse( $tmp_path );

		@unlink( $tmp_path ); // Clean up temp file immediately; data is now in $parsed. phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! empty( $parsed['errors'] ) && empty( $parsed['rows'] ) ) {
			wp_redirect( add_query_arg( 'error', 'parse_failed', $redirect_base ) );
			exit;
		}

		// Validate rows.
		$validated = $importer->validate( $parsed['rows'] );

		// Compute stats for the preview.
		$stats = array(
			'ok'    => count( array_filter( $validated, static fn( $r ) => 'ok' === $r['status'] ) ),
			'warn'  => count( array_filter( $validated, static fn( $r ) => 'warn' === $r['status'] ) ),
			'skip'  => count( array_filter( $validated, static fn( $r ) => 'skip' === $r['status'] ) ),
			'error' => count( array_filter( $validated, static fn( $r ) => 'error' === $r['status'] ) ),
			'total' => count( $validated ),
		);

		// Store in a transient so the preview page can display it and the AJAX chunks can apply it.
		// Key embeds user ID to scope access; UUID ensures uniqueness.
		$batch_id  = wp_generate_uuid4();
		$import_id = 'lwia_import_' . get_current_user_id() . '_' . str_replace( '-', '', $batch_id );

		set_transient(
			$import_id,
			array(
				'rows'     => $validated,
				'batch_id' => $batch_id,
				'stats'    => $stats,
				'errors'   => $parsed['errors'], // Pass along any non-fatal parse warnings.
			),
			30 * MINUTE_IN_SECONDS
		);

		wp_redirect( add_query_arg(
			array( 'step' => 'preview', 'import_id' => urlencode( $import_id ) ),
			$redirect_base
		) );
		exit;
	}

	/**
	 * Handle a batch undo request.
	 *
	 * Triggered by admin-post.php with action=lwia_undo_batch.
	 */
	public function handle_undo_batch(): void {
		check_admin_referer( 'lwia_undo_batch' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to undo changes.', 'lw-img-alt' ) );
		}

		$batch_id    = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$redirect_to = admin_url( 'admin.php?page=lwia-undo' );

		if ( ! $batch_id ) {
			wp_redirect( add_query_arg( 'error', 'missing_batch', $redirect_to ) );
			exit;
		}

		$undo   = new LWIA_Undo();
		$result = $undo->rollback( $batch_id );

		// Store in a short-lived transient so the undo screen can display the result after redirect.
		set_transient( 'lwia_undo_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_redirect( add_query_arg( 'undone', '1', $redirect_to ) );
		exit;
	}
}
