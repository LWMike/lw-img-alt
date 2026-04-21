<?php
/**
 * AJAX handlers for inline edit and async operations.
 *
 * All endpoints are nonce-verified and capability-checked.
 * Only wp_ajax_ handlers — no nopriv versions (plugin is admin-only).
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LWIA_Ajax
 */
class LWIA_Ajax {

	/**
	 * Constructor — registers wp_ajax_ hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_lwia_save_alt',    array( $this, 'save_alt' ) );
		add_action( 'wp_ajax_lwia_apply_chunk', array( $this, 'apply_chunk' ) );
	}

	/**
	 * Handle the inline alt-text save request.
	 *
	 * Expected POST params:
	 *   nonce         string  wp_create_nonce( 'lwia_save_alt' )
	 *   attachment_id int
	 *   alt_text      string
	 *
	 * Responds with wp_send_json_success / wp_send_json_error.
	 */
	public function save_alt(): void {
		// 1. Nonce check.
		check_ajax_referer( 'lwia_save_alt', 'nonce' );

		// 2. Capability check.
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'You do not have permission to edit images.', 'lw-img-alt' ) ),
				403
			);
		}

		// 3. Read and validate input.
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$alt_text      = isset( $_POST['alt_text'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['alt_text'] ) )
			: '';

		if ( $attachment_id < 1 ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Invalid attachment ID.', 'lw-img-alt' ) ),
				400
			);
		}

		// 4. Confirm the attachment exists and is an image.
		$post = get_post( $attachment_id );

		if (
			! $post
			|| 'attachment' !== $post->post_type
			|| ! str_starts_with( $post->post_mime_type, 'image/' )
		) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Attachment not found or is not an image.', 'lw-img-alt' ) ),
				404
			);
		}

		// 5. Write via the single write path.
		$updated = LWIA_Updater::update(
			$attachment_id,
			$alt_text,
			'manual',
			wp_generate_uuid4()
		);

		if ( $updated ) {
			wp_send_json_success( array( 'attachment_id' => $attachment_id ) );
		} else {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Could not save alt text. Please try again.', 'lw-img-alt' ) ),
				500
			);
		}
	}

	/**
	 * Handle a single chunk of a CSV import apply operation.
	 *
	 * Expected POST params:
	 *   nonce        string  wp_create_nonce( 'lwia_import' )
	 *   import_id    string  Transient key returned by the upload handler.
	 *   chunk_offset int     Index of the first row in this chunk.
	 *
	 * The batch_id is read exclusively from the server-side transient — never
	 * from the client — to prevent batch-ID spoofing.
	 *
	 * Responds with wp_send_json_success( { applied, skipped, errors, next_offset, done, total } )
	 * or wp_send_json_error.
	 */
	public function apply_chunk(): void {
		check_ajax_referer( 'lwia_import', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'You do not have permission to import data.', 'lw-img-alt' ) ),
				403
			);
		}

		$import_id    = isset( $_POST['import_id'] )    ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) )    : '';
		$chunk_offset = isset( $_POST['chunk_offset'] ) ? absint( $_POST['chunk_offset'] )                             : 0;

		if ( ! $import_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing import ID.', 'lw-img-alt' ) ), 400 );
		}

		$data = get_transient( $import_id );

		if ( false === $data || ! is_array( $data ) || empty( $data['rows'] ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Import session expired. Please re-upload the CSV file.', 'lw-img-alt' ) ),
				410
			);
		}

		$importer = new LWIA_CSV_Import();
		$result   = $importer->apply_chunk(
			$data['rows'],
			$data['batch_id'],
			$chunk_offset,
			LWIA_CSV_Import::CHUNK_SIZE
		);

		// Clean up the transient once the last chunk completes.
		if ( $result['done'] ) {
			delete_transient( $import_id );
		}

		wp_send_json_success( $result );
	}
}
