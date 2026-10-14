<?php
/**
 * Runs when the plugin is deleted from WordPress: removes its settings, keys and logs.
 * The backup folder (uploads/rahm-backups) is removed too.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
foreach ( array( 'rahm_settings', 'rahm_keys', 'rahm_log', 'rahm_undo', 'rahm_inbox', 'rahm_audits', 'rahm_seq' ) as $o ) {
	delete_option( $o );
}
wp_clear_scheduled_hook( 'rahm_daily' );
$u   = wp_upload_dir();
$dir = $u['basedir'] . '/rahm-backups';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getRealPath() ) : @unlink( $f->getRealPath() );
	}
	@rmdir( $dir );
}
