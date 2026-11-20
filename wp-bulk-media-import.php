<?php
/**
 * Plugin Name:       WP Bulk Media Import
 * Plugin URI:        https://github.com/Azanumer/wp-bulk-media-import
 * Description:       Imports images from a folder on your server into the Media Library in batches — with content-hash duplicate skipping and optional delete-after-import.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://azanumer.cv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-bulk-media-import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBMI_VERSION', '1.0.0' );
define( 'WBMI_BATCH_SIZE', 25 );
define( 'WBMI_JOB_PREFIX', 'wbmi_job_' );
define( 'WBMI_HASHES_TRANSIENT', 'wbmi_existing_hashes' );

/**
 * Register the Tools page.
 */
function wbmi_register_page() {
	add_management_page(
		__( 'Bulk Media Import', 'wp-bulk-media-import' ),
		__( 'Bulk Media Import', 'wp-bulk-media-import' ),
		'upload_files',
		'wbmi-bulk-media-import',
		'wbmi_render_page'
	);
}
add_action( 'admin_menu', 'wbmi_register_page' );

/**
 * Roots a source directory is allowed to live under.
 *
 * @return string[] Real paths.
 */
function wbmi_allowed_roots() {
	$roots = array();
	if ( defined( 'ABSPATH' ) ) {
		$roots[] = realpath( ABSPATH );
	}
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['basedir'] ) ) {
		$roots[] = realpath( $uploads['basedir'] );
	}
	return apply_filters( 'wbmi_allowed_roots', array_filter( $roots ) );
}

/**
 * Validate a source directory: exists, readable, inside an allowed root,
 * and not the uploads base directory itself (importing from there would
 * re-import the whole library).
 *
 * @param string $dir Raw user input.
 * @return string|WP_Error Real path, or an error.
 */
function wbmi_validate_dir( $dir ) {
	$real = realpath( $dir );
	if ( false === $real || ! is_dir( $real ) || ! is_readable( $real ) ) {
		return new WP_Error( 'wbmi_bad_dir', __( 'That directory does not exist or is not readable.', 'wp-bulk-media-import' ) );
	}
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['basedir'] ) && realpath( $uploads['basedir'] ) === $real ) {
		return new WP_Error( 'wbmi_uploads_root', __( 'Importing from the uploads folder itself would re-import your whole library. Point at a subfolder instead.', 'wp-bulk-media-import' ) );
	}
	$allowed = false;
	foreach ( wbmi_allowed_roots() as $root ) {
		if ( false !== $root && ( $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) ) {
			$allowed = true;
			break;
		}
	}
	if ( ! $allowed ) {
		return new WP_Error( 'wbmi_outside_root', __( 'For safety, the source folder must live inside your WordPress install or uploads directory.', 'wp-bulk-media-import' ) );
	}
	return $real;
}

/**
 * Image extensions this importer accepts.
 *
 * @return string[]
 */
function wbmi_image_extensions() {
	return apply_filters( 'wbmi_image_extensions', array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) );
}

/**
 * Recursively (or not) list importable image files under a directory.
 *
 * @param string $dir       Real path to scan.
 * @param bool   $recursive Scan subdirectories.
 * @return string[] Sorted absolute file paths.
 */
function wbmi_list_files( $dir, $recursive ) {
	$extensions = wbmi_image_extensions();
	$files      = array();

	$iterator = $recursive
		? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) )
		: new DirectoryIterator( $dir );

	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() ) {
			continue;
		}
		$ext = strtolower( pathinfo( $item->getFilename(), PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $extensions, true ) ) {
			$files[] = $item->getPathname();
		}
	}
	sort( $files );
	return $files;
}

/**
 * Content hashes of every image already in the Media Library.
 *
 * Built once per import run and cached in a transient (1 hour) so the
 * duplicate check stays cheap across batches. Newly imported files are
 * added to the map as they land.
 *
 * @return array<string, int> md5 hash => attachment ID.
 */
function wbmi_existing_hashes() {
	$hashes = get_transient( WBMI_HASHES_TRANSIENT );
	if ( is_array( $hashes ) ) {
		return $hashes;
	}
	$hashes = array();
	$query  = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	foreach ( $query->posts as $id ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! is_file( $file ) ) {
			continue;
		}
		$hash = md5_file( $file );
		if ( false !== $hash ) {
			$hashes[ $hash ] = (int) $id;
		}
	}
	set_transient( WBMI_HASHES_TRANSIENT, $hashes, HOUR_IN_SECONDS );
	return $hashes;
}

/**
 * Import one file into the Media Library.
 *
 * @param string $source Absolute path of the source file.
 * @param bool   $delete_source Remove the source file after a successful import.
 * @return int|WP_Error Attachment ID, or an error.
 */
function wbmi_import_file( $source, $delete_source ) {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'wbmi_uploads_error', $uploads['error'] );
	}

	$filename = wp_unique_filename( $uploads['path'], basename( $source ) );
	$dest     = trailingslashit( $uploads['path'] ) . $filename;
	if ( ! copy( $source, $dest ) ) {
		return new WP_Error( 'wbmi_copy_failed', sprintf( __( 'Could not copy %s into uploads.', 'wp-bulk-media-import' ), basename( $source ) ) );
	}

	$filetype = wp_check_filetype( $filename );
	$attachment = array(
		'post_mime_type' => $filetype['type'],
		'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attach_id = wp_insert_attachment( $attachment, $dest );
	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		wp_delete_file( $dest );
		return new WP_Error( 'wbmi_insert_failed', sprintf( __( 'Could not create an attachment for %s.', 'wp-bulk-media-import' ), basename( $source ) ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$meta = wp_generate_attachment_metadata( $attach_id, $dest );
	wp_update_attachment_metadata( $attach_id, $meta );

	if ( $delete_source ) {
		wp_delete_file( $source );
	}
	return (int) $attach_id;
}

/**
 * Process one import batch and return updated counters.
 *
 * @param array $job Job array from the transient (dir, files, options, counters).
 * @return array Updated counters: imported, skipped, failed, errors[].
 */
function wbmi_run_batch( $job ) {
	$offset  = (int) $job['offset'];
	$files   = array_slice( $job['files'], $offset, WBMI_BATCH_SIZE );
	$hashes  = wbmi_existing_hashes();
	$counts  = $job['counts'];
	$errors  = $job['errors'];

	foreach ( $files as $file ) {
		if ( ! is_file( $file ) ) {
			$counts['failed']++;
			$errors[] = sprintf( __( '%s: file disappeared before import.', 'wp-bulk-media-import' ), basename( $file ) );
			continue;
		}
		if ( $job['skip_duplicates'] ) {
			$hash = md5_file( $file );
			if ( false !== $hash && isset( $hashes[ $hash ] ) ) {
				$counts['skipped']++;
				continue;
			}
		}
		$result = wbmi_import_file( $file, $job['delete_source'] );
		if ( is_wp_error( $result ) ) {
			$counts['failed']++;
			$errors[] = basename( $file ) . ': ' . $result->get_error_message();
			continue;
		}
		$counts['imported']++;
		if ( $job['skip_duplicates'] ) {
			$hash = md5_file( $file );
			if ( false !== $hash ) {
				$hashes[ $hash ] = $result;
			}
		}
	}

	set_transient( WBMI_HASHES_TRANSIENT, $hashes, HOUR_IN_SECONDS );
	return array(
		'counts' => $counts,
		'errors' => $errors,
		'offset' => $offset + count( $files ),
	);
}

/**
 * Render the Tools page: directory form, scan preview, import progress.
 */
function wbmi_render_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-bulk-media-import' ) );
	}

	$error  = '';
	$notice = '';

	// --- Start a new import job (scan step) -------------------------------
	if ( isset( $_POST['wbmi_scan'] ) ) {
		check_admin_referer( 'wbmi_scan' );
		$dir = isset( $_POST['wbmi_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['wbmi_dir'] ) ) : '';
		$validated = wbmi_validate_dir( $dir );
		if ( is_wp_error( $validated ) ) {
			$error = $validated->get_error_message();
		} else {
			$recursive       = ! empty( $_POST['wbmi_recursive'] );
			$skip_duplicates = ! empty( $_POST['wbmi_skip_duplicates'] );
			$delete_source   = ! empty( $_POST['wbmi_delete_source'] );
			$files           = wbmi_list_files( $validated, $recursive );
			if ( empty( $files ) ) {
				$error = __( 'No image files found in that directory.', 'wp-bulk-media-import' );
			} else {
				$job_id = wp_generate_password( 12, false );
				set_transient( WBMI_JOB_PREFIX . $job_id, array(
					'dir'             => $validated,
					'files'           => $files,
					'skip_duplicates' => $skip_duplicates,
					'delete_source'   => $delete_source,
					'offset'          => 0,
					'counts'          => array( 'imported' => 0, 'skipped' => 0, 'failed' => 0 ),
					'errors'          => array(),
				), HOUR_IN_SECONDS );
				// Warm the duplicate hash map now, while the user reviews.
				wbmi_existing_hashes();
				wp_safe_redirect( add_query_arg( array(
					'page'       => 'wbmi-bulk-media-import',
					'wbmi_job'   => $job_id,
					'wbmi_preview' => 1,
				), admin_url( 'tools.php' ) ) );
				exit;
			}
		}
	}

	// --- Confirm + run the import in batches ------------------------------
	if ( isset( $_GET['wbmi_run'] ) && '1' === $_GET['wbmi_run'] && isset( $_GET['wbmi_job'] ) ) {
		check_admin_referer( 'wbmi_run' );
		$job_id = sanitize_key( wp_unslash( $_GET['wbmi_job'] ) );
		$job    = get_transient( WBMI_JOB_PREFIX . $job_id );
		if ( false === $job ) {
			$error = __( 'This import job expired. Please start a new scan.', 'wp-bulk-media-import' );
		} else {
			$result       = wbmi_run_batch( $job );
			$job['counts'] = $result['counts'];
			$job['errors'] = $result['errors'];
			$job['offset'] = $result['offset'];
			$total = count( $job['files'] );
			if ( $job['offset'] < $total ) {
				set_transient( WBMI_JOB_PREFIX . $job_id, $job, HOUR_IN_SECONDS );
				wp_safe_redirect( add_query_arg( array(
					'page'     => 'wbmi-bulk-media-import',
					'wbmi_job' => $job_id,
					'wbmi_run' => 1,
					'_wpnonce' => wp_create_nonce( 'wbmi_run' ),
				), admin_url( 'tools.php' ) ) );
				exit;
			}
			// Finished: clear the job + hash cache, show the summary.
			delete_transient( WBMI_JOB_PREFIX . $job_id );
			delete_transient( WBMI_HASHES_TRANSIENT );
			$counts = $job['counts'];
			$notice = sprintf(
				/* translators: %1$d imported, %2$d skipped duplicates, %3$d failed. */
				__( 'Import finished: %1$d imported, %2$d skipped as duplicates, %3$d failed.', 'wp-bulk-media-import' ),
				$counts['imported'],
				$counts['skipped'],
				$counts['failed']
			);
			if ( ! empty( $job['errors'] ) ) {
				$error = implode( "\n", array_slice( $job['errors'], 0, 20 ) );
			}
			$job = false;
		}
	}

	// --- Preview a scanned job --------------------------------------------
	$preview_job = false;
	if ( isset( $_GET['wbmi_preview'] ) && isset( $_GET['wbmi_job'] ) ) {
		$preview_job = get_transient( WBMI_JOB_PREFIX . sanitize_key( wp_unslash( $_GET['wbmi_job'] ) ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Bulk Media Import', 'wp-bulk-media-import' ); ?></h1>
		<p><?php esc_html_e( 'Import images straight from a folder on your server into the Media Library — handy after an FTP upload or a site migration. Duplicates are detected by file content, not filename.', 'wp-bulk-media-import' ); ?></p>

		<?php if ( $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( $preview_job ) : ?>
			<h2><?php esc_html_e( 'Ready to import', 'wp-bulk-media-import' ); ?></h2>
			<p>
				<?php
				printf(
					esc_html__( '%1$d image(s) found in %2$s.', 'wp-bulk-media-import' ),
					count( $preview_job['files'] ),
					'<code>' . esc_html( $preview_job['dir'] ) . '</code>'
				);
				?>
			</p>
			<ul>
				<li><?php echo $preview_job['skip_duplicates'] ? esc_html__( 'Duplicates (same file content) will be skipped.', 'wp-bulk-media-import' ) : esc_html__( 'Duplicates will be imported again.', 'wp-bulk-media-import' ); ?></li>
				<li><?php echo $preview_job['delete_source'] ? esc_html__( 'Source files will be deleted after import.', 'wp-bulk-media-import' ) : esc_html__( 'Source files will be left in place.', 'wp-bulk-media-import' ); ?></li>
			</ul>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'wbmi-bulk-media-import', 'wbmi_job' => sanitize_key( wp_unslash( $_GET['wbmi_job'] ) ), 'wbmi_run' => 1 ), admin_url( 'tools.php' ) ), 'wbmi_run' ) ); ?>">
					<?php esc_html_e( 'Start Import', 'wp-bulk-media-import' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wbmi-bulk-media-import' ), admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'wp-bulk-media-import' ); ?></a>
			</p>
			<h3><?php esc_html_e( 'Files', 'wp-bulk-media-import' ); ?></h3>
			<ol>
				<?php foreach ( array_slice( $preview_job['files'], 0, 50 ) as $file ) : ?>
					<li><code><?php echo esc_html( str_replace( $preview_job['dir'] . '/', '', $file ) ); ?></code></li>
				<?php endforeach; ?>
				<?php if ( count( $preview_job['files'] ) > 50 ) : ?>
					<li><em><?php printf( esc_html__( '…and %d more.', 'wp-bulk-media-import' ), count( $preview_job['files'] ) - 50 ); ?></em></li>
				<?php endif; ?>
			</ol>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'wbmi_scan' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wbmi_dir"><?php esc_html_e( 'Server folder', 'wp-bulk-media-import' ); ?></label></th>
						<td>
							<input type="text" id="wbmi_dir" name="wbmi_dir" size="70" required placeholder="/var/www/example.com/wp-content/uploads/import/" />
							<p class="description"><?php esc_html_e( 'Absolute path on the server. Must live inside your WordPress install. JPG, JPEG, PNG, GIF and WebP are imported.', 'wp-bulk-media-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'wp-bulk-media-import' ); ?></th>
						<td>
							<label><input type="checkbox" name="wbmi_recursive" value="1" checked /> <?php esc_html_e( 'Include subfolders', 'wp-bulk-media-import' ); ?></label><br />
							<label><input type="checkbox" name="wbmi_skip_duplicates" value="1" checked /> <?php esc_html_e( 'Skip files already in the library (same content)', 'wp-bulk-media-import' ); ?></label><br />
							<label><input type="checkbox" name="wbmi_delete_source" value="1" /> <?php esc_html_e( 'Delete source files after a successful import', 'wp-bulk-media-import' ); ?></label>
						</td>
					</tr>
				</table>
				<p><input type="submit" name="wbmi_scan" class="button button-primary" value="<?php esc_attr_e( 'Scan Folder', 'wp-bulk-media-import' ); ?>" /></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
