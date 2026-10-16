<?php
/**
 * Plugin Name:       AI Connection Switch
 * Description:       Keeps AI / MCP connector plugins (such as Novamira) switched off when you are not using them. Turns them on when a chosen administrator logs in, off when they log out or after a period with no AI activity, and adds an on/off switch to the admin bar.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            HHH Consulting
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-connection-switch
 *
 * Why: a connector that lets an AI run code on your site is the most powerful
 * door into it. While it is switched off, a stolen application password or a
 * flaw in the connector is useless. This plugin makes "off unless I am working"
 * the default without having to remember to do it.
 */

defined( 'ABSPATH' ) || exit;

const AICS_VERSION = '1.0.1';
const AICS_OPTION  = 'aics_settings';
const AICS_STATE   = 'aics_state';
const AICS_LOG     = 'aics_log';
const AICS_CRON    = 'aics_idle_check';

/* --------------------------------------------------------------------------
 * Settings and state
 * ------------------------------------------------------------------------ */

function aics_defaults() {
	return array(
		'targets'       => array(),       // plugin basenames to switch
		'users'         => array(),       // user IDs who control the switch
		'on_login'      => 1,             // switch on when a chosen user logs in
		'off_logout'    => 1,             // switch off when a chosen user logs out
		'idle_minutes'  => 120,           // switch off after this long with no AI activity (0 = never)
		'rest_prefixes' => 'novamira,mcp', // REST routes that count as AI activity
	);
}

function aics_settings() {
	return wp_parse_args( (array) get_option( AICS_OPTION, array() ), aics_defaults() );
}

function aics_state() {
	return wp_parse_args( (array) get_option( AICS_STATE, array() ), array( 'last_on' => 0, 'last_activity' => 0 ) );
}

function aics_set_state( $changes ) {
	update_option( AICS_STATE, array_merge( aics_state(), $changes ), false );
}

/** Keep the last 50 events, newest first, so there is an audit trail. */
function aics_log( $message ) {
	$log = (array) get_option( AICS_LOG, array() );
	array_unshift( $log, array( 'time' => time(), 'message' => $message ) );
	update_option( AICS_LOG, array_slice( $log, 0, 50 ), false );
}

function aics_load_plugin_api() {
	if ( ! function_exists( 'activate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
}

/** Configured targets that are actually installed (never this plugin itself). */
function aics_targets() {
	aics_load_plugin_api();
	$installed = array_keys( get_plugins() );
	$self      = plugin_basename( __FILE__ );
	return array_values( array_filter( (array) aics_settings()['targets'], function ( $t ) use ( $installed, $self ) {
		return $t !== $self && in_array( $t, $installed, true );
	} ) );
}

function aics_is_on() {
	aics_load_plugin_api();
	foreach ( aics_targets() as $t ) {
		if ( is_plugin_active( $t ) ) {
			return true;
		}
	}
	return false;
}

/** A chosen user who may also manage plugins. */
function aics_is_controller( $user_id ) {
	$user_id = (int) $user_id;
	return $user_id
		&& in_array( $user_id, array_map( 'intval', (array) aics_settings()['users'] ), true )
		&& user_can( $user_id, 'activate_plugins' );
}

/* --------------------------------------------------------------------------
 * Switching
 * ------------------------------------------------------------------------ */

function aics_switch_on( $reason ) {
	$targets = aics_targets();
	if ( ! $targets ) {
		return;
	}
	aics_load_plugin_api();
	$result = activate_plugins( $targets );
	if ( is_wp_error( $result ) ) {
		aics_log( sprintf( __( 'Could not switch on (%1$s): %2$s', 'ai-connection-switch' ), $reason, $result->get_error_message() ) );
		return;
	}
	aics_set_state( array( 'last_on' => time() ) );
	aics_log( sprintf( __( 'Switched ON: %s', 'ai-connection-switch' ), $reason ) );
}

function aics_switch_off( $reason ) {
	$targets = aics_targets();
	if ( ! $targets || ! aics_is_on() ) {
		return;
	}
	aics_load_plugin_api();
	deactivate_plugins( $targets ); // runs their normal deactivation hooks; settings are kept
	aics_log( sprintf( __( 'Switched OFF: %s', 'ai-connection-switch' ), $reason ) );
}

function aics_user_label( $user_id ) {
	$u = get_userdata( (int) $user_id );
	return $u ? $u->user_login : '#' . (int) $user_id;
}

/* Log in / log out ------------------------------------------------------- */

add_action( 'wp_login', function ( $login, $user ) {
	if ( aics_settings()['on_login'] && $user instanceof WP_User && aics_is_controller( $user->ID ) && ! aics_is_on() ) {
		aics_switch_on( sprintf( __( '%s logged in', 'ai-connection-switch' ), $user->user_login ) );
	}
}, 10, 2 );

add_action( 'wp_logout', function ( $user_id = 0 ) {
	if ( aics_settings()['off_logout'] && aics_is_controller( $user_id ) ) {
		aics_switch_off( sprintf( __( '%s logged out', 'ai-connection-switch' ), aics_user_label( $user_id ) ) );
	}
} );

/* AI activity tracking --------------------------------------------------- */

/** Any successful application-password login by a chosen user counts as AI activity. */
add_action( 'application_password_did_authenticate', function ( $user ) {
	if ( $user instanceof WP_User && aics_is_controller( $user->ID ) ) {
		aics_set_state( array( 'last_activity' => time() ) );
	}
} );

/** So does any REST request to a configured route prefix (e.g. /wp-json/novamira/...). */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	$route = ltrim( (string) $request->get_route(), '/' );
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) aics_settings()['rest_prefixes'] ) ) ) as $prefix ) {
		if ( stripos( $route, $prefix ) === 0 ) {
			aics_set_state( array( 'last_activity' => time() ) );
			break;
		}
	}
	return $result;
}, 10, 3 );

/* Idle timeout ----------------------------------------------------------- */

function aics_idle_check() {
	$minutes = (int) aics_settings()['idle_minutes'];
	if ( $minutes <= 0 || ! aics_is_on() ) {
		return;
	}
	$state = aics_state();
	$since = max( (int) $state['last_on'], (int) $state['last_activity'] );
	if ( $since && time() - $since > $minutes * MINUTE_IN_SECONDS ) {
		aics_switch_off( sprintf( __( 'no AI activity for %d minutes', 'ai-connection-switch' ), $minutes ) );
	}
}
add_action( AICS_CRON, 'aics_idle_check' );
add_action( 'admin_init', 'aics_idle_check' ); // backup in case WP-Cron is slow

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['aics_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every five minutes', 'ai-connection-switch' ),
	);
	return $schedules;
} );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( AICS_CRON ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'aics_five_minutes', AICS_CRON );
	}
	// Treat "now" as the start of any session already in progress.
	aics_set_state( array( 'last_on' => time() ) );
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( AICS_CRON );
} );

/* --------------------------------------------------------------------------
 * Admin bar switch
 * ------------------------------------------------------------------------ */

add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! is_user_logged_in() || ! aics_is_controller( get_current_user_id() ) || ! aics_targets() ) {
		return;
	}
	$on  = aics_is_on();
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=aics_toggle' ), 'aics_toggle' );
	$bar->add_node( array(
		'id'    => 'aics-switch',
		'title' => '<span class="aics-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:' . ( $on ? '#3c3' : '#999' ) . '"></span>'
			. esc_html( $on ? __( 'AI: ON', 'ai-connection-switch' ) : __( 'AI: OFF', 'ai-connection-switch' ) ),
		'href'  => $url,
		'meta'  => array(
			'title' => $on ? __( 'Click to switch the AI connection off', 'ai-connection-switch' ) : __( 'Click to switch the AI connection on', 'ai-connection-switch' ),
		),
	) );
}, 100 );

add_action( 'admin_post_aics_toggle', function () {
	check_admin_referer( 'aics_toggle' );
	if ( ! aics_is_controller( get_current_user_id() ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'ai-connection-switch' ), '', array( 'response' => 403 ) );
	}
	$who     = aics_user_label( get_current_user_id() );
	$was_on  = aics_is_on();
	$back_to = wp_get_referer() ? wp_get_referer() : admin_url();
	if ( $was_on ) {
		aics_switch_off( sprintf( __( 'switched off by %s', 'ai-connection-switch' ), $who ) );
	} else {
		aics_switch_on( sprintf( __( 'switched on by %s', 'ai-connection-switch' ), $who ) );
	}
	// After switching OFF, an admin page that belonged to the connector no longer
	// exists ("Sorry, you are not allowed to access this page"). So never return to
	// a plugin's own admin page (admin.php?page=…) after switching off: go to this
	// plugin's settings instead, which also shows the result.
	if ( $was_on && false !== strpos( $back_to, 'admin.php?page=' ) ) {
		$back_to = admin_url( 'options-general.php?page=ai-connection-switch' );
	}
	wp_safe_redirect( $back_to );
	exit;
} );

/* --------------------------------------------------------------------------
 * Settings page (Settings > AI Connection Switch)
 * ------------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'AI Connection Switch', 'ai-connection-switch' ),
		__( 'AI Connection Switch', 'ai-connection-switch' ),
		'activate_plugins',
		'ai-connection-switch',
		'aics_render_settings'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'aics', AICS_OPTION, array( 'sanitize_callback' => 'aics_sanitize' ) );
} );

function aics_sanitize( $input ) {
	aics_load_plugin_api();
	$input     = (array) $input;
	$installed = array_keys( get_plugins() );
	$self      = plugin_basename( __FILE__ );
	$out       = aics_defaults();

	$out['targets'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $input['targets'] ?? array() ) ), function ( $t ) use ( $installed, $self ) {
		return $t !== $self && in_array( $t, $installed, true );
	} ) );
	$out['users'] = array_values( array_filter( array_map( 'absint', (array) ( $input['users'] ?? array() ) ), function ( $id ) {
		return $id && user_can( $id, 'activate_plugins' );
	} ) );
	$out['on_login']      = empty( $input['on_login'] ) ? 0 : 1;
	$out['off_logout']    = empty( $input['off_logout'] ) ? 0 : 1;
	$out['idle_minutes']  = max( 0, min( 1440, absint( $input['idle_minutes'] ?? 120 ) ) );
	$out['rest_prefixes'] = sanitize_text_field( $input['rest_prefixes'] ?? '' );
	return $out;
}

function aics_render_settings() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	aics_load_plugin_api();
	$s      = aics_settings();
	$state  = aics_state();
	$self   = plugin_basename( __FILE__ );
	$admins = get_users( array( 'capability' => 'activate_plugins', 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
	$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'AI Connection Switch', 'ai-connection-switch' ); ?></h1>
		<p><?php esc_html_e( 'Keeps the chosen AI / MCP connector plugins switched off unless one of the chosen administrators is working with them.', 'ai-connection-switch' ); ?></p>

		<p><strong><?php esc_html_e( 'Now:', 'ai-connection-switch' ); ?></strong>
			<?php echo aics_is_on() ? '<span style="color:#1a7f37">' . esc_html__( 'ON', 'ai-connection-switch' ) . '</span>' : '<span style="color:#666">' . esc_html__( 'OFF', 'ai-connection-switch' ) . '</span>'; ?>
			&nbsp;·&nbsp;<?php esc_html_e( 'Last AI activity:', 'ai-connection-switch' ); ?>
			<?php echo $state['last_activity'] ? esc_html( wp_date( $fmt, (int) $state['last_activity'] ) ) : esc_html__( 'none recorded', 'ai-connection-switch' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'aics' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugins to switch', 'ai-connection-switch' ); ?></th>
					<td>
						<?php foreach ( get_plugins() as $file => $data ) : if ( $file === $self ) { continue; } ?>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( AICS_OPTION ); ?>[targets][]" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, (array) $s['targets'], true ) ); ?>> <?php echo esc_html( $data['Name'] ); ?> <code><?php echo esc_html( $file ); ?></code></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Tick the connector and any add-ons it needs (e.g. Novamira and Novamira Pro). Make sure nothing else on your site relies on code these plugins load.', 'ai-connection-switch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Administrators in control', 'ai-connection-switch' ); ?></th>
					<td>
						<?php foreach ( $admins as $a ) : ?>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( AICS_OPTION ); ?>[users][]" value="<?php echo (int) $a->ID; ?>" <?php checked( in_array( (int) $a->ID, array_map( 'intval', (array) $s['users'] ), true ) ); ?>> <?php echo esc_html( $a->user_login . ' (' . $a->display_name . ')' ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Only these users see the admin-bar switch and trigger the automatic on/off.', 'ai-connection-switch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic', 'ai-connection-switch' ); ?></th>
					<td>
						<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( AICS_OPTION ); ?>[on_login]" value="1" <?php checked( $s['on_login'] ); ?>> <?php esc_html_e( 'Switch on when one of them logs in', 'ai-connection-switch' ); ?></label>
						<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( AICS_OPTION ); ?>[off_logout]" value="1" <?php checked( $s['off_logout'] ); ?>> <?php esc_html_e( 'Switch off when one of them logs out', 'ai-connection-switch' ); ?></label>
						<label style="display:block"><?php esc_html_e( 'Switch off after', 'ai-connection-switch' ); ?> <input type="number" min="0" max="1440" style="width:6em" name="<?php echo esc_attr( AICS_OPTION ); ?>[idle_minutes]" value="<?php echo (int) $s['idle_minutes']; ?>"> <?php esc_html_e( 'minutes with no AI activity (0 = never)', 'ai-connection-switch' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'What counts as AI activity', 'ai-connection-switch' ); ?></th>
					<td>
						<input type="text" class="regular-text" name="<?php echo esc_attr( AICS_OPTION ); ?>[rest_prefixes]" value="<?php echo esc_attr( $s['rest_prefixes'] ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated REST route prefixes used by your connector (e.g. novamira, mcp). Any application-password login by an administrator in control also counts.', 'ai-connection-switch' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Recent events', 'ai-connection-switch' ); ?></h2>
		<table class="widefat striped" style="max-width:720px">
			<tbody>
			<?php $log = (array) get_option( AICS_LOG, array() ); if ( ! $log ) : ?>
				<tr><td><?php esc_html_e( 'Nothing yet.', 'ai-connection-switch' ); ?></td></tr>
			<?php else : foreach ( $log as $row ) : ?>
				<tr><td style="width:13em"><?php echo esc_html( wp_date( $fmt, (int) $row['time'] ) ); ?></td><td><?php echo esc_html( $row['message'] ); ?></td></tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/** Link to the settings from the Plugins screen. */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=ai-connection-switch' ) ) . '">' . esc_html__( 'Settings', 'ai-connection-switch' ) . '</a>' );
	return $links;
} );
