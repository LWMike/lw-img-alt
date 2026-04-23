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
		add_action( 'current_screen',        array( $this, 'setup_screen' ) );
		add_action( 'admin_notices',         array( $this, 'maybe_show_batch_notice' ) );

		// GET-based handlers — intercepted before the page renders.
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'handle_sample_csv' ) );

		// POST-based handlers via admin-post.php.
		add_action( 'admin_post_lwia_import_upload',  array( $this, 'handle_import_upload' ) );
		add_action( 'admin_post_lwia_undo_batch',     array( $this, 'handle_undo_batch' ) );
		add_action( 'admin_post_lwia_ai_settings',    array( $this, 'handle_ai_settings' ) );
		add_action( 'admin_post_lwia_ai_batch_start', array( $this, 'handle_ai_batch_start' ) );
		add_action( 'admin_post_lwia_ai_batch_apply',   array( $this, 'handle_ai_batch_apply' ) );
		add_action( 'admin_post_lwia_ai_refetch',       array( $this, 'handle_ai_refetch' ) );

		// Allow WordPress to persist per-page screen options for our screens.
		add_filter( 'set_screen_option_lwia_scan_per_page',    fn( $s, $o, $v ) => (int) $v, 10, 3 );
		add_filter( 'set_screen_option_lwia_log_per_page',     fn( $s, $o, $v ) => (int) $v, 10, 3 );
		add_filter( 'set_screen_option_lwia_undo_per_page',    fn( $s, $o, $v ) => (int) $v, 10, 3 );
		add_filter( 'set_screen_option_lwia_suggest_per_page', fn( $s, $o, $v ) => (int) $v, 10, 3 );
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
			'dashicons-images-alt',
			80
		);

		// The auto-generated first submenu item mirrors the top-level page.
		// Re-add it with the label "Scan" so the menu reads cleanly.
		$this->page_hooks['scan'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Image Alt — Scan', 'lw-img-alt' ),
			esc_html__( 'Scan', 'lw-img-alt' ),
			'upload_files',
			'lw-img-alt',
			array( $this, 'render_scan' )
		);

		$this->page_hooks['import'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Image Alt — Import', 'lw-img-alt' ),
			esc_html__( 'Import', 'lw-img-alt' ),
			'upload_files',
			'lwia-import',
			array( $this, 'render_import' )
		);

		$this->page_hooks['log'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Image Alt — Change Log', 'lw-img-alt' ),
			esc_html__( 'Change Log', 'lw-img-alt' ),
			'upload_files',
			'lwia-log',
			array( $this, 'render_log' )
		);

		$this->page_hooks['undo'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Image Alt — Undo', 'lw-img-alt' ),
			esc_html__( 'Undo', 'lw-img-alt' ),
			'manage_options',
			'lwia-undo',
			array( $this, 'render_undo' )
		);

		// AI sub-menu items — only shown when AI is enabled.
		if ( LWIA_AI_Settings::is_enabled() ) {
			$this->page_hooks['ai-suggest'] = add_submenu_page(
				'lw-img-alt',
				esc_html__( 'Image Alt — AI Suggest', 'lw-img-alt' ),
				esc_html__( 'AI Suggest', 'lw-img-alt' ),
				'manage_options',
				'lwia-ai-suggest',
				array( $this, 'render_ai_suggest' )
			);
		}

		// Review screen — accessible via URL, not in the menu.
		$this->page_hooks['ai-review'] = add_submenu_page(
			null, // no parent — hidden from menu
			esc_html__( 'Image Alt — AI Review', 'lw-img-alt' ),
			'',
			'manage_options',
			'lwia-ai-review',
			array( $this, 'render_ai_review' )
		);

		$this->page_hooks['ai-settings'] = add_submenu_page(
			'lw-img-alt',
			esc_html__( 'Image Alt — AI Settings', 'lw-img-alt' ),
			esc_html__( 'AI Settings', 'lw-img-alt' ),
			'manage_options',
			'lwia-ai-settings',
			array( $this, 'render_ai_settings' )
		);
	}

	// =========================================================================
	// Screen setup — help tabs + screen options
	// =========================================================================

	/**
	 * Register per-page screen options and help tabs on plugin screens.
	 *
	 * Hooked to current_screen so page_hooks are already populated.
	 */
	public function setup_screen(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$screen_id = $screen->id;

		// ---- Per-page screen options ----
		$per_page_opts = array(
			$this->page_hooks['scan'] => array( 'option' => 'lwia_scan_per_page', 'default' => 50 ),
			$this->page_hooks['log']  => array( 'option' => 'lwia_log_per_page',  'default' => 50 ),
			$this->page_hooks['undo'] => array( 'option' => 'lwia_undo_per_page', 'default' => 20 ),
		);

		if ( isset( $per_page_opts[ $screen_id ] ) ) {
			$opt = $per_page_opts[ $screen_id ];
			add_screen_option( 'per_page', array(
				'label'   => __( 'Rows per page', 'lw-img-alt' ),
				'default' => $opt['default'],
				'option'  => $opt['option'],
			) );
		}

		// ---- Help tabs ----
		$help_map = array(
			$this->page_hooks['scan']   => array(
				'id'      => 'lwia-scan-overview',
				'title'   => __( 'Overview', 'lw-img-alt' ),
				'content' =>
					'<p>' . esc_html__( 'This screen lists all images in the Media Library that are missing alt text. Enter alt text directly in the table — it saves automatically when you leave the field or press Enter.', 'lw-img-alt' ) . '</p>'
					. '<p>' . esc_html__( 'Use the filters to narrow the list by attachment status, file type, or upload date. Export the full list as a CSV to bulk-edit in a spreadsheet.', 'lw-img-alt' ) . '</p>',
			),
			$this->page_hooks['import'] => array(
				'id'      => 'lwia-import-overview',
				'title'   => __( 'Overview', 'lw-img-alt' ),
				'content' =>
					'<p>' . esc_html__( 'Upload a CSV file to bulk-update alt text. Download the sample CSV to see the required format, or export the missing list from the Scan screen.', 'lw-img-alt' ) . '</p>'
					. '<p>' . esc_html__( 'After uploading, you will see a preview of changes before anything is applied. Every import is logged and can be undone from the Undo screen.', 'lw-img-alt' ) . '</p>',
			),
			$this->page_hooks['log']    => array(
				'id'      => 'lwia-log-overview',
				'title'   => __( 'Overview', 'lw-img-alt' ),
				'content' =>
					'<p>' . esc_html__( 'Every alt text change is recorded here. Filter by source, date range, or user. Click a Batch ID to copy the full UUID to your clipboard. Use the filter icon to view all changes from a single import batch.', 'lw-img-alt' ) . '</p>',
			),
			$this->page_hooks['undo']   => array(
				'id'      => 'lwia-undo-overview',
				'title'   => __( 'Overview', 'lw-img-alt' ),
				'content' =>
					'<p>' . esc_html__( 'Each row represents a group of alt text changes made together — either a CSV import batch or a single inline edit. Click Undo to restore the previous alt text for every image in the batch.', 'lw-img-alt' ) . '</p>'
					. '<p>' . esc_html__( 'Undo operations are themselves logged, so they can be undone too. Use View details to see every row in a batch before undoing.', 'lw-img-alt' ) . '</p>',
			),
		);

		if ( isset( $help_map[ $screen_id ] ) ) {
			$screen->add_help_tab( $help_map[ $screen_id ] );
		}
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
				'saving'        => esc_html__( 'Saving…', 'lw-img-alt' ),
				'saved'         => esc_html__( 'Saved', 'lw-img-alt' ),
				/* translators: %s: image filename */
				'savedToast'    => esc_html__( 'Alt text saved for %s.', 'lw-img-alt' ),
				/* translators: %s: image filename */
				'errorToast'    => esc_html__( 'Couldn\'t save alt text for %s.', 'lw-img-alt' ),
				'errorMsg'      => esc_html__( 'Error saving. Please try again.', 'lw-img-alt' ),
				'imageSingular' => esc_html__( 'image missing alt text', 'lw-img-alt' ),
				'imagePlural'   => esc_html__( 'images missing alt text', 'lw-img-alt' ),
				/* translators: 1: image count string e.g. "5 images", 2: short batch ID */
				'undoConfirm'   => esc_html__( 'This will restore the previous alt text for %1$s in batch %2$s. A new \'undo\' entry will be logged. Proceed?', 'lw-img-alt' ),
				'copied'        => esc_html__( 'Copied!', 'lw-img-alt' ),
				'dismiss'       => esc_html__( 'Dismiss this notice', 'lw-img-alt' ),
			)
		);

		// AI Generate script — Scan screen only.
		$scan_hook = $this->page_hooks['scan'] ?? '';
		if ( $hook_suffix === $scan_hook && LWIA_AI_Settings::is_enabled() && LWIA_AI_Settings::get_api_key() ) {
			wp_enqueue_script(
				'lwia-ai-generate',
				LWIA_PLUGIN_URL . 'admin/js/ai-generate.js',
				array( 'jquery', 'lwia-admin' ),
				LWIA_VERSION,
				true
			);
			wp_localize_script(
				'lwia-ai-generate',
				'lwiaAI',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( 'lwia_ai_generate' ),
					'generating'     => esc_html__( 'Generating…', 'lw-img-alt' ),
					'generateBtn'    => esc_html__( 'Generate', 'lw-img-alt' ),
					'generateFailed' => esc_html__( 'AI generation failed', 'lw-img-alt' ),
					'rateLimited'    => esc_html__( 'AI is busy — try batch mode for large jobs.', 'lw-img-alt' ),
				)
			);
		}

		// AI Review script — review screen only.
		$review_hook = $this->page_hooks['ai-review'] ?? '';
		if ( $hook_suffix === $review_hook ) {
			wp_enqueue_script(
				'lwia-ai-review',
				LWIA_PLUGIN_URL . 'admin/js/ai-review.js',
				array( 'jquery' ),
				LWIA_VERSION,
				true
			);
			wp_localize_script(
				'lwia-ai-review',
				'lwiaReview',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'applyNonce'     => wp_create_nonce( 'lwia_ai_batch_apply' ),
					'confirmApply'   => esc_html__( 'Apply the selected suggestions? This will update alt text for the checked images and cannot be undone without using the Undo screen.', 'lw-img-alt' ),
					'applying'       => esc_html__( 'Applying…', 'lw-img-alt' ),
					'applied'        => esc_html__( 'Done — alt text updated.', 'lw-img-alt' ),
					'errorMsg'       => esc_html__( 'Error applying suggestions. Please try again.', 'lw-img-alt' ),
				)
			);
		}
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
		$current_page  = isset( $_GET['paged'] )      ? max( 1, absint( $_GET['paged'] ) )                              : 1;
		$filter_attach = isset( $_GET['attachment'] )  ? sanitize_key( wp_unslash( $_GET['attachment'] ) )               : 'all';
		$filter_date_f = isset( $_GET['date_from'] )   ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )         : '';
		$filter_date_t = isset( $_GET['date_to'] )     ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )           : '';
		$filter_mime   = isset( $_GET['mime_type'] )   ? sanitize_mime_type( wp_unslash( (string) $_GET['mime_type'] ) ) : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = (int) ( get_user_option( 'lwia_scan_per_page' ) ?: 50 );
		if ( ! in_array( $per_page, array( 20, 50, 100, 200 ), true ) ) {
			$per_page = 50;
		}

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
		$step      = isset( $_GET['step'] )      ? sanitize_key( wp_unslash( $_GET['step'] ) )             : 'upload';
		$import_id = isset( $_GET['import_id'] ) ? sanitize_text_field( wp_unslash( $_GET['import_id'] ) ) : '';
		$error     = isset( $_GET['error'] )     ? sanitize_key( wp_unslash( $_GET['error'] ) )             : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$import_data = null;

		if ( 'preview' === $step && $import_id ) {
			$import_data = get_transient( $import_id );

			if ( false === $import_data ) {
				$step  = 'upload';
				$error = 'expired';
			}
		}

		$sample_url = wp_nonce_url(
			add_query_arg(
				array( 'page' => 'lwia-import', 'action' => 'lwia_sample_csv' ),
				admin_url( 'admin.php' )
			),
			'lwia_sample_csv'
		);

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
		$current_page   = isset( $_GET['paged'] )     ? max( 1, absint( $_GET['paged'] ) )                          : 1;
		$filter_source  = isset( $_GET['source'] )    ? sanitize_key( wp_unslash( $_GET['source'] ) )               : '';
		$filter_user_id = isset( $_GET['user_id'] )   ? absint( $_GET['user_id'] )                                  : 0;
		$filter_date_f  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )     : '';
		$filter_date_t  = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )       : '';
		$filter_batch   = isset( $_GET['batch_id'] )  ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) )      : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = (int) ( get_user_option( 'lwia_log_per_page' ) ?: 50 );
		if ( ! in_array( $per_page, array( 20, 50, 100, 200 ), true ) ) {
			$per_page = 50;
		}

		$query_args = array_filter( array(
			'paged'     => $current_page,
			'per_page'  => $per_page,
			'source'    => $filter_source,
			'user_id'   => $filter_user_id,
			'date_from' => $filter_date_f,
			'date_to'   => $filter_date_t,
			'batch_id'  => $filter_batch,
		) );

		$entries     = LWIA_Logger::get_entries( $query_args );
		$total       = LWIA_Logger::get_total( $query_args );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$log_users   = LWIA_Logger::get_distinct_users();

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

		$per_page = (int) ( get_user_option( 'lwia_undo_per_page' ) ?: 20 );
		if ( ! in_array( $per_page, array( 10, 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}

		$undo    = new LWIA_Undo();
		$batches = $undo->get_batches( array( 'paged' => $current_page, 'per_page' => $per_page ) );
		$total   = $undo->get_batches_total();

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		$undo_result_key = 'lwia_undo_result_' . get_current_user_id();
		$undo_result     = get_transient( $undo_result_key );
		if ( false !== $undo_result ) {
			delete_transient( $undo_result_key );
		}

		require LWIA_PLUGIN_DIR . 'admin/views/undo.php';
	}

	// =========================================================================
	// Form handlers
	// =========================================================================

	/**
	 * Handle the CSV export download (GET request via admin_init).
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
			'attachment' => isset( $_GET['attachment'] ) ? sanitize_key( wp_unslash( $_GET['attachment'] ) )               : 'all',
			'date_from'  => isset( $_GET['date_from'] )  ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )         : '',
			'date_to'    => isset( $_GET['date_to'] )    ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )           : '',
			'mime_type'  => isset( $_GET['mime_type'] )  ? sanitize_mime_type( wp_unslash( (string) $_GET['mime_type'] ) ) : 'all',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		LWIA_CSV_Export::generate( $filter_args );
		exit;
	}

	/**
	 * Serve a 3-row sample CSV so users can see the required import format.
	 */
	public function handle_sample_csv(): void {
		if (
			! isset( $_GET['action'], $_GET['page'] )
			|| 'lwia_sample_csv' !== $_GET['action']
			|| 'lwia-import' !== $_GET['page']
		) {
			return;
		}

		check_admin_referer( 'lwia_sample_csv' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to download sample data.', 'lw-img-alt' ) );
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="lw-img-alt-sample.csv"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel opens correctly.
		fputcsv( $out, array( 'attachment_id', 'filename', 'url', 'current_alt', 'new_alt', 'title', 'caption' ) );
		fputcsv( $out, array( '101', 'hero-banner.jpg', 'https://example.com/wp-content/uploads/hero-banner.jpg', '', 'A banner image showing the product range', 'Hero Banner', '' ) );
		fputcsv( $out, array( '102', 'team-photo.png',  'https://example.com/wp-content/uploads/team-photo.png',  '', 'The full team gathered outdoors in summer 2024', 'Team Photo', 'Our team' ) );
		fputcsv( $out, array( '103', 'logo.svg',        'https://example.com/wp-content/uploads/logo.svg',        '', 'Company logo on white background', 'Logo', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Handle a CSV file upload and parse/validate it, then redirect to the preview step.
	 */
	public function handle_import_upload(): void {
		check_admin_referer( 'lwia_import_upload' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to import data.', 'lw-img-alt' ) );
		}

		$redirect_base = admin_url( 'admin.php?page=lwia-import' );

		if (
			empty( $_FILES['lwia_csv'] )
			|| ! isset( $_FILES['lwia_csv']['error'] )
			|| UPLOAD_ERR_OK !== $_FILES['lwia_csv']['error']
		) {
			wp_redirect( add_query_arg( 'error', 'no_file', $redirect_base ) );
			exit;
		}

		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['lwia_csv']['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			wp_redirect( add_query_arg( 'error', 'invalid_type', $redirect_base ) );
			exit;
		}

		$check         = wp_check_filetype_and_ext(
			$_FILES['lwia_csv']['tmp_name'],
			sanitize_file_name( $_FILES['lwia_csv']['name'] ),
			array( 'csv' => 'text/csv' )
		);
		$allowed_mimes = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
		if ( empty( $check['ext'] ) && ! in_array( (string) $check['type'], $allowed_mimes, true ) ) {
			wp_redirect( add_query_arg( 'error', 'invalid_type', $redirect_base ) );
			exit;
		}

		$tmp_path = get_temp_dir() . 'lwia-' . wp_generate_password( 16, false ) . '.csv';

		if ( ! move_uploaded_file( $_FILES['lwia_csv']['tmp_name'], $tmp_path ) ) {
			wp_redirect( add_query_arg( 'error', 'upload_failed', $redirect_base ) );
			exit;
		}

		$importer = new LWIA_CSV_Import();
		$parsed   = $importer->parse( $tmp_path );

		@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! empty( $parsed['errors'] ) && empty( $parsed['rows'] ) ) {
			wp_redirect( add_query_arg( 'error', 'parse_failed', $redirect_base ) );
			exit;
		}

		$validated = $importer->validate( $parsed['rows'] );

		$stats = array(
			'ok'    => count( array_filter( $validated, static fn( $r ) => 'ok' === $r['status'] ) ),
			'warn'  => count( array_filter( $validated, static fn( $r ) => 'warn' === $r['status'] ) ),
			'skip'  => count( array_filter( $validated, static fn( $r ) => 'skip' === $r['status'] ) ),
			'error' => count( array_filter( $validated, static fn( $r ) => 'error' === $r['status'] ) ),
			'total' => count( $validated ),
		);

		$batch_id  = wp_generate_uuid4();
		$import_id = 'lwia_import_' . get_current_user_id() . '_' . str_replace( '-', '', $batch_id );

		set_transient(
			$import_id,
			array(
				'rows'     => $validated,
				'batch_id' => $batch_id,
				'stats'    => $stats,
				'errors'   => $parsed['errors'],
			),
			30 * MINUTE_IN_SECONDS
		);

		wp_redirect( add_query_arg(
			array( 'step' => 'preview', 'import_id' => urlencode( $import_id ) ),
			$redirect_base
		) );
		exit;
	}

	// =========================================================================
	// Admin notice — AI batch completion
	// =========================================================================

	/**
	 * Show a dismissible notice when an AI batch job completes for the current user.
	 */
	public function maybe_show_batch_notice(): void {
		$notice_key = 'lwia_ai_complete_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $notice_key );

		$review_url = add_query_arg(
			array( 'page' => 'lwia-ai-review', 'job_id' => urlencode( (string) $notice['job_id'] ) ),
			admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: 1: number of suggestions, 2: opening anchor, 3: closing anchor */
					__( 'AI batch complete — %1$d suggestions ready. %2$sReview and apply them now%3$s.', 'lw-img-alt' ),
					(int) $notice['succeeded'],
					'<a href="' . esc_url( $review_url ) . '">',
					'</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	// =========================================================================
	// AI screen callbacks
	// =========================================================================

	/**
	 * Render the AI Suggest screen.
	 */
	public function render_ai_suggest(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		if ( ! LWIA_AI_Settings::get_api_key() ) {
			wp_redirect( admin_url( 'admin.php?page=lwia-ai-settings&notice=no_key' ) );
			exit;
		}

		$scanner    = new LWIA_Scanner();
		$jobs       = LWIA_AI_Batch_Queue::get_jobs();
		$has_active = LWIA_AI_Batch_Queue::has_active_job();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter_mode   = isset( $_GET['mode'] )      ? sanitize_key( wp_unslash( $_GET['mode'] ) )                        : 'missing';
		$filter_attach = isset( $_GET['attachment'] ) ? sanitize_key( wp_unslash( $_GET['attachment'] ) )                  : 'all';
		$filter_date_f = isset( $_GET['date_from'] )  ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )            : '';
		$filter_date_t = isset( $_GET['date_to'] )    ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )              : '';
		$filter_mime   = isset( $_GET['mime_type'] )  ? sanitize_mime_type( wp_unslash( (string) $_GET['mime_type'] ) )    : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Count images in scope for the cost estimate (missing alt only for now).
		$count_args = array(
			'attachment' => $filter_attach,
			'date_from'  => $filter_date_f,
			'date_to'    => $filter_date_t,
			'mime_type'  => $filter_mime,
		);
		$image_count   = $scanner->get_total( $count_args );
		$estimated_gbp = LWIA_AI_Settings::estimate_cost( $image_count );
		$spend         = LWIA_AI_Settings::get_current_spend();
		$cap           = LWIA_AI_Settings::get_spend_cap();

		// Read and clear any stored API error from the previous batch start attempt.
		$err_key         = 'lwia_batch_err_' . get_current_user_id();
		$batch_api_error = (string) ( get_transient( $err_key ) ?: '' );
		if ( $batch_api_error ) {
			delete_transient( $err_key );
		}

		require LWIA_PLUGIN_DIR . 'admin/views/ai-suggest.php';
	}

	/**
	 * Render the AI Review screen (job results).
	 */
	public function render_ai_review(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$paged  = isset( $_GET['paged'] )  ? max( 1, absint( $_GET['paged'] ) )                   : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$job     = $job_id ? LWIA_AI_Batch_Queue::get_job( $job_id ) : null;
		$results = ( $job && 'complete' === ( $job['status'] ?? '' ) )
			? LWIA_AI_Batch_Queue::get_results( $job_id )
			: false;

		$per_page    = 50;
		$all_results = is_array( $results ) ? array_values( $results ) : array();
		$total       = count( $all_results );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$offset      = ( $paged - 1 ) * $per_page;
		$page_results = array_slice( $all_results, $offset, $per_page );

		require LWIA_PLUGIN_DIR . 'admin/views/ai-review.php';
	}

	/**
	 * Render the AI Settings screen.
	 */
	public function render_ai_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$saved  = isset( $_GET['saved'] )  ? (bool) $_GET['saved']                                  : false;
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) )           : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$spend = LWIA_AI_Settings::get_current_spend();

		require LWIA_PLUGIN_DIR . 'admin/views/ai-settings.php';
	}

	// =========================================================================
	// AI form handlers
	// =========================================================================

	/**
	 * Handle a batch undo request.
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

		set_transient( 'lwia_undo_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_redirect( add_query_arg( 'undone', '1', $redirect_to ) );
		exit;
	}

	/**
	 * Save AI settings.
	 */
	public function handle_ai_settings(): void {
		check_admin_referer( 'lwia_ai_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change AI settings.', 'lw-img-alt' ) );
		}

		LWIA_AI_Settings::save_from_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above

		wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=lwia-ai-settings' ) ) );
		exit;
	}

	/**
	 * Start a new AI batch generation job.
	 */
	public function handle_ai_batch_start(): void {
		check_admin_referer( 'lwia_ai_batch_start' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to start AI batch jobs.', 'lw-img-alt' ) );
		}

		$redirect_base = admin_url( 'admin.php?page=lwia-ai-suggest' );

		if ( LWIA_AI_Batch_Queue::has_active_job() ) {
			wp_redirect( add_query_arg( 'error', 'job_active', $redirect_base ) );
			exit;
		}

		if ( LWIA_AI_Settings::is_cap_reached() ) {
			wp_redirect( add_query_arg( 'error', 'cap_reached', $redirect_base ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above
		$mode          = isset( $_POST['mode'] )       ? sanitize_key( wp_unslash( $_POST['mode'] ) )                        : 'missing';
		$filter_attach = isset( $_POST['attachment'] ) ? sanitize_key( wp_unslash( $_POST['attachment'] ) )                  : 'all';
		$filter_date_f = isset( $_POST['date_from'] )  ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) )            : '';
		$filter_date_t = isset( $_POST['date_to'] )    ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) )              : '';
		$filter_mime   = isset( $_POST['mime_type'] )  ? sanitize_mime_type( wp_unslash( (string) $_POST['mime_type'] ) )    : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$scanner = new LWIA_Scanner();
		$rows    = $scanner->get_missing( array(
			'per_page'   => 10000,
			'attachment' => $filter_attach,
			'date_from'  => $filter_date_f,
			'date_to'    => $filter_date_t,
			'mime_type'  => $filter_mime,
		) );

		if ( empty( $rows ) ) {
			wp_redirect( add_query_arg( 'error', 'no_images', $redirect_base ) );
			exit;
		}

		$supported_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		$images          = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row->post_mime_type, $supported_mimes, true ) ) {
				continue;
			}
			$url = wp_get_attachment_image_url( (int) $row->ID, 'large' );
			if ( ! $url ) {
				$url = (string) $row->guid;
			}
			$images[] = array(
				'attachment_id' => (int) $row->ID,
				'image_url'     => $url,
				'existing_alt'  => '',
			);
		}

		$local_id = LWIA_AI_Batch_Queue::create( $images, $mode );

		if ( ! $local_id ) {
			wp_redirect( add_query_arg( 'error', 'batch_failed', $redirect_base ) );
			exit;
		}

		wp_redirect( add_query_arg( array( 'started' => '1', 'job_id' => urlencode( $local_id ) ), $redirect_base ) );
		exit;
	}

	/**
	 * Apply selected AI suggestions from the review screen.
	 */
	public function handle_ai_batch_apply(): void {
		check_admin_referer( 'lwia_ai_batch_apply' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to apply AI suggestions.', 'lw-img-alt' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above
		$job_id   = isset( $_POST['job_id'] )   ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) )   : '';
		$selected = isset( $_POST['selected'] ) ? array_map( 'absint', (array) $_POST['selected'] )        : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$redirect_base = add_query_arg(
			array( 'page' => 'lwia-ai-review', 'job_id' => urlencode( $job_id ) ),
			admin_url( 'admin.php' )
		);

		if ( ! $job_id || empty( $selected ) ) {
			wp_redirect( add_query_arg( 'error', 'no_selection', $redirect_base ) );
			exit;
		}

		$results = LWIA_AI_Batch_Queue::get_results( $job_id );
		if ( ! is_array( $results ) ) {
			wp_redirect( add_query_arg( 'error', 'expired', $redirect_base ) );
			exit;
		}

		$batch_id = wp_generate_uuid4();
		$applied  = 0;
		$skipped  = 0;
		$errors   = 0;
		$ai_data  = array(
			'ai_model'          => LWIA_AI_OpenAI::MODEL,
			'ai_prompt_version' => LWIA_AI_Prompt::VERSION,
		);

		foreach ( $selected as $attachment_id ) {
			$key    = (string) $attachment_id;
			$result = $results[ $key ] ?? null;

			if ( ! $result || ! $result->success || '' === $result->alt ) {
				$skipped++;
				continue;
			}

			// Inline edits from the review screen are stored in POST.
			$alt_key  = 'alt_' . $attachment_id;
			$alt_text = isset( $_POST[ $alt_key ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				? sanitize_text_field( wp_unslash( $_POST[ $alt_key ] ) )
				: $result->alt;

			$ok = LWIA_Updater::update(
				$attachment_id,
				$alt_text,
				'batch',
				$batch_id,
				array_merge( $ai_data, array( 'ai_confidence' => $result->confidence ) )
			);

			if ( $ok ) {
				$applied++;
			} else {
				$errors++;
			}
		}

		wp_redirect( add_query_arg(
			array( 'applied' => $applied, 'skipped' => $skipped, 'errors' => $errors ),
			$redirect_base
		) );
		exit;
	}

	/**
	 * Re-fetch batch results from the vendor API and refresh the transient.
	 * Handles cases where the original retrieval silently failed (e.g. all
	 * individual requests errored and output_file_id was null).
	 */
	public function handle_ai_refetch(): void {
		check_admin_referer( 'lwia_ai_refetch' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'lw-img-alt' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		$redirect_base = add_query_arg(
			array( 'page' => 'lwia-ai-review', 'job_id' => urlencode( $job_id ) ),
			admin_url( 'admin.php' )
		);

		if ( ! $job_id ) {
			wp_redirect( add_query_arg( 'error', 'expired', $redirect_base ) );
			exit;
		}

		LWIA_AI_Batch_Queue::refetch_results( $job_id );

		wp_redirect( $redirect_base );
		exit;
	}
}
