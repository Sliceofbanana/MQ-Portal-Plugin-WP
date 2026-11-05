<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub releases: wp-admin → Plugins shows "Update available" when the repository
 * has a newer release, and "Update now" (or auto-updates) installs it — no zip upload by hand.
 *
 * - Repository: MQP_UPDATE_REPO in wp-config.php, else Sliceofbanana/MQ-Portal-Plugin-WP.
 * - Private repository: put a read-only token in wp-config.php:
 *     define( 'MQP_GITHUB_TOKEN', 'github_pat_…' );
 * - Each release needs an asset named mq-portal.zip (the GitHub Action builds it on every v* tag).
 */
class MQP_Updater {

	const REPO      = 'Sliceofbanana/MQ-Portal-Plugin-WP';
	const ASSET     = 'mq-portal.zip';
	const SLUG      = 'mq-portal';
	const TRANSIENT = 'mqp_gh_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( MQP_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_init', array( __CLASS__, 'check_now' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	private static function repo() {
		return defined( 'MQP_UPDATE_REPO' ) && MQP_UPDATE_REPO ? (string) MQP_UPDATE_REPO : self::REPO;
	}

	private static function token() {
		return defined( 'MQP_GITHUB_TOKEN' ) ? (string) MQP_GITHUB_TOKEN : '';
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = array( 'Accept' => $accept, 'User-Agent' => 'mq-portal-updater', 'X-GitHub-Api-Version' => '2022-11-28' );
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	/** The latest release: version, zip (API asset link), notes. Cached 6 hours; null when unknown. */
	public static function release( $fresh = false ) {
		$cached = $fresh ? false : get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::repo() . '/releases/latest', array( 'timeout' => 10, 'headers' => self::headers() ) );
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$out  = array( 'version' => '', 'package' => '', 'notes' => '', 'url' => '', 'error' => '' );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$out['error'] = is_wp_error( $res ) ? $res->get_error_message() : 'GitHub answered ' . $code . ( 404 === $code ? ' (no release yet, or the token cannot read the repository)' : '' );
		} else {
			foreach ( (array) ( $data['assets'] ?? array() ) as $a ) {
				if ( self::ASSET === ( $a['name'] ?? '' ) ) {
					$out['package'] = (string) $a['url']; // API link: works for private repositories with the token.
				}
			}
			$out['version'] = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
			$out['notes']   = (string) ( $data['body'] ?? '' );
			$out['url']     = (string) ( $data['html_url'] ?? '' );
			if ( ! $out['package'] ) {
				$out['error']   = 'The latest release has no ' . self::ASSET . ' file.';
				$out['version'] = '';
			}
		}
		// A failed check is remembered for an hour only, so a fixed token works soon.
		set_site_transient( self::TRANSIENT, $out, $out['version'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		update_site_option( 'mqp_update_last_check', array( 'at' => time(), 'error' => $out['error'], 'version' => $out['version'] ) );
		return $out['version'] ? $out : null;
	}

	private static function item( array $r ) {
		return (object) array(
			'id'           => self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => plugin_basename( MQP_FILE ),
			'new_version'  => $r['version'],
			'url'          => 'https://github.com/' . self::repo(),
			'package'      => $r['package'],
			'requires_php' => '7.4',
			'icons'        => array( '1x' => MQP_URL . 'assets/icon-64.png', '2x' => MQP_URL . 'assets/icon-192.png' ),
		);
	}

	/** Tells WordPress about a newer release (or that this one is current, so auto-updates can be switched on). */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$r = self::release();
		if ( ! $r ) {
			return $transient;
		}
		$file = plugin_basename( MQP_FILE );
		if ( version_compare( $r['version'], MQP_VERSION, '>' ) ) {
			$transient->response[ $file ] = self::item( $r );
			unset( $transient->no_update[ $file ] );
		} else {
			$transient->no_update[ $file ] = self::item( $r );
			unset( $transient->response[ $file ] );
		}
		return $transient;
	}

	/** "View details" popup: the release notes. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$r = self::release();
		if ( ! $r ) {
			return $result;
		}
		return (object) array(
			'name'          => 'MQ Portal',
			'slug'          => self::SLUG,
			'version'       => $r['version'],
			'author'        => 'Genesis Esdrilon Jr',
			'homepage'      => $r['url'],
			'requires_php'  => '7.4',
			'download_link' => $r['package'],
			'sections'      => array( 'changelog' => wpautop( esc_html( $r['notes'] ? $r['notes'] : 'See the changelog in MQ Portal → System → Tools.' ) ) ),
		);
	}

	/**
	 * Downloads the release zip. The GitHub API answers with a redirect to a signed storage link:
	 * the token goes to GitHub only, never to that link (it refuses a second sign-in).
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || 0 !== strpos( (string) $package, 'https://api.github.com/repos/' . self::repo() . '/releases/assets/' ) ) {
			return $reply;
		}
		$res  = wp_remote_get( $package, array( 'timeout' => 30, 'redirection' => 0, 'headers' => self::headers( 'application/octet-stream' ) ) );
		$code = (int) wp_remote_retrieve_response_code( $res );
		$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( in_array( $code, array( 301, 302, 307 ), true ) && $loc ) {
			return download_url( $loc, 300 );
		}
		if ( 200 === $code && '' !== wp_remote_retrieve_body( $res ) ) {
			$tmp = wp_tempnam( self::ASSET );
			file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $tmp;
		}
		return new WP_Error( 'mqp_update_download', 'Could not download the update from GitHub (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'answer ' . $code ) . '). Check MQP_GITHUB_TOKEN in wp-config.php.' );
	}

	/** Makes sure the unpacked folder is "mq-portal", so the update replaces this plugin. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( ( $extra['plugin'] ?? '' ) !== plugin_basename( MQP_FILE ) || ! $wp_filesystem ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( plugin_basename( MQP_FILE ) ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $want, true ) ? $want : $source;
	}

	public static function links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?mqp_check_update=1' ), 'mqp_check_update' ) ) . '">Check for updates</a>';
		}
		return $links;
	}

	/** Plugins → MQ Portal → "Check for updates": asks GitHub now instead of waiting up to 12 hours. */
	public static function check_now() {
		if ( empty( $_GET['mqp_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'mqp_check_update' );
		self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$last = get_site_option( 'mqp_update_last_check', array() );
		$msg  = ! empty( $last['error'] ) ? 'error:' . $last['error'] : ( version_compare( (string) ( $last['version'] ?? '' ), MQP_VERSION, '>' ) ? 'new:' . $last['version'] : 'current' );
		set_transient( 'mqp_update_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public static function notice() {
		$msg = get_transient( 'mqp_update_notice_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'mqp_update_notice_' . get_current_user_id() );
		if ( 0 === strpos( $msg, 'error:' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>MQ Portal:</strong> could not check for updates — ' . esc_html( substr( $msg, 6 ) ) . '</p></div>';
		} elseif ( 0 === strpos( $msg, 'new:' ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>MQ Portal ' . esc_html( substr( $msg, 4 ) ) . '</strong> is available. Click "Update now" under MQ Portal.</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p><strong>MQ Portal</strong> is up to date (' . esc_html( MQP_VERSION ) . ').</p></div>';
		}
	}

	public static function forget() {
		delete_site_transient( self::TRANSIENT );
	}
}
