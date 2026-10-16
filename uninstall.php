<?php
/**
 * Remove AI Connection Switch's own settings, state and log.
 * The connector plugins themselves are left exactly as they are.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aics_settings' );
delete_option( 'aics_state' );
delete_option( 'aics_log' );
wp_clear_scheduled_hook( 'aics_idle_check' );
