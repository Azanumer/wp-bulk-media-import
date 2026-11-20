<?php
/**
 * Uninstall WP Bulk Media Import.
 *
 * Removes any leftover import-job and duplicate-hash transients.
 * Imported media is never touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'wbmi_existing_hashes' );

global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wbmi\_job\_%' OR option_name LIKE '\_transient\_timeout\_wbmi\_job\_%'"
);
