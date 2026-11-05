<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Drive for archives: each new archive zip is uploaded to a folder in the connected
 * Google Drive. Once Google confirms the copy (same size and MD5 checksum), the space on the
 * hosting can be freed automatically.
 *
 * Set-up (once, by the site administrator): a Google Cloud OAuth client (Web application) with
 * this site's callback address, then System → Settings → Google Drive → Connect. The plugin only
 * asks for access to the files it creates itself (drive.file), never the rest of the Drive.
 * The client secret and the Google sign-in are stored on this site only and never exported.
 *
 * Uploads run in the background in 8 MB pieces (resumable upload), so big zips also work on
 * shared hosting; an interrupted upload continues where it stopped.
 */
class MQP_GDrive {

	const OPT    = 'mqp_gdrive';
	const ACTION = 'mqp_gdrive';
	const CHUNK  = 8388608; // 8 MB (a multiple of 256 KB, as Google requires).
	const SCOPE  = 'https://www.googleapis.com/auth/drive.file';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'mqp_gdrive_upload', array( __CLASS__, 'upload_next' ) );
	}

	/** Google's addresses (a test site can point them at a stand-in). */
	private static function endpoint( $name ) {
		$urls = apply_filters(
			'mqp_gdrive_endpoints',
			array(
				'auth'   => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token'  => 'https://oauth2.googleapis.com/token',
				'revoke' => 'https://oauth2.googleapis.com/revoke',
				'files'  => 'https://www.googleapis.com/drive/v3/files',
				'upload' => 'https://www.googleapis.com/upload/drive/v3/files',
				'about'  => 'https://www.googleapis.com/drive/v3/about',
			)
		);
		return $urls[ $name ];
	}

	public static function get() {
		return wp_parse_args(
			(array) get_option( self::OPT, array() ),
			array( 'client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'folder' => 'MQ Portal archives', 'folder_id' => '', 'account' => '', 'auto_free' => 1, 'connected_at' => 0 )
		);
	}

	private static function put( array $changes ) {
		update_option( self::OPT, array_merge( self::get(), $changes ), false );
	}

	public static function connected() {
		$g = self::get();
		return '' !== $g['refresh_token'] && '' !== $g['client_id'];
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=' . self::ACTION . '&op=callback' );
	}

	/* ---------- Google sign-in ---------- */

	private static function token() {
		$cached = get_transient( 'mqp_gdrive_token' );
		if ( $cached ) {
			return $cached;
		}
		$g   = self::get();
		$res = wp_remote_post( self::endpoint( 'token' ), array(
			'timeout' => 20,
			'body'    => array( 'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'], 'refresh_token' => $g['refresh_token'], 'grant_type' => 'refresh_token' ),
		) );
		$body = is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			$why = is_wp_error( $res ) ? $res->get_error_message() : ( $body['error_description'] ?? $body['error'] ?? 'no answer' );
			if ( isset( $body['error'] ) && 'invalid_grant' === $body['error'] ) {
				self::put( array( 'refresh_token' => '' ) ); // Access was removed in the Google account: connect again.
			}
			return new WP_Error( 'mqp_gdrive', 'Google Drive sign-in failed: ' . $why );
		}
		set_transient( 'mqp_gdrive_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		return $body['access_token'];
	}

	private static function api( $method, $url, $args = array() ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array_merge( array( 'method' => $method, 'timeout' => 30 ), $args );
		$args['headers'] = array_merge( array( 'Authorization' => 'Bearer ' . $token ), (array) ( $args['headers'] ?? array() ) );
		return wp_remote_request( $url, $args );
	}

	/** The Drive folder for archives (made once). */
	private static function folder_id() {
		$g = self::get();
		if ( $g['folder_id'] ) {
			return $g['folder_id'];
		}
		$res  = self::api( 'POST', self::endpoint( 'files' ) . '?fields=id', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'name' => $g['folder'] ? $g['folder'] : 'MQ Portal archives', 'mimeType' => 'application/vnd.google-apps.folder' ) ),
		) );
		$body = is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['id'] ) ) {
			return new WP_Error( 'mqp_gdrive', 'Could not make the Google Drive folder: ' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ) ) );
		}
		self::put( array( 'folder_id' => $body['id'] ) );
		return $body['id'];
	}

	/* ---------- Uploading archives ---------- */

	/** Queues the upload of new archives (called after an archive is made). */
	public static function queue() {
		if ( self::connected() && ! wp_next_scheduled( 'mqp_gdrive_upload' ) ) {
			wp_schedule_single_event( time() + 5, 'mqp_gdrive_upload' );
		}
	}

	/**
	 * Background: uploads the oldest archive not yet on Drive, in pieces, for up to ~4 minutes;
	 * then it plans the next run, so big zips and many archives all get there.
	 */
	public static function upload_next() {
		global $wpdb;
		if ( ! self::connected() ) {
			return;
		}
		$t = mqp_table( 'archives' );
		$a = $wpdb->get_row( "SELECT * FROM $t WHERE status IN ('ready','downloaded') AND drive_id = '' ORDER BY id LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $a ) {
			return;
		}
		$res = self::upload( $a, time() + 240 );
		if ( is_wp_error( $res ) ) {
			$wpdb->update( $t, array( 'drive_error' => substr( $res->get_error_message(), 0, 250 ) ), array( 'id' => $a['id'] ) );
			MQP_Monitor::record( 'background', 'Archive ' . $a['name'] . ' could not be uploaded to Google Drive: ' . $res->get_error_message() );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'mqp_gdrive_upload' ); // Try again later.
			return;
		}
		wp_schedule_single_event( time() + 30, 'mqp_gdrive_upload' ); // The rest of this one, or the next archive.
	}

	/** Uploads (or continues uploading) one archive until $deadline. true when done, 'more' when not yet. */
	public static function upload( array $a, $deadline ) {
		global $wpdb;
		$t    = mqp_table( 'archives' );
		$path = MQP_Files::base_dir() . '/' . $a['path'];
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'mqp_gdrive', 'the zip file is missing on the server' );
		}
		$size    = (int) filesize( $path );
		$session = (string) $a['drive_session'];
		$offset  = (int) $a['drive_offset'];

		if ( '' === $session ) {
			$folder = self::folder_id();
			if ( is_wp_error( $folder ) ) {
				return $folder;
			}
			$res = self::api( 'POST', self::endpoint( 'upload' ) . '?uploadType=resumable&fields=id,size,md5Checksum,webViewLink', array(
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) $size ),
				'body'    => wp_json_encode( array( 'name' => $a['name'], 'parents' => array( $folder ) ) ),
			) );
			$session = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'location' );
			if ( '' === $session ) {
				return new WP_Error( 'mqp_gdrive', 'Google Drive did not start the upload (' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ) ) . ')' );
			}
			$offset = 0;
			$wpdb->update( $t, array( 'drive_session' => $session, 'drive_offset' => 0, 'drive_error' => '' ), array( 'id' => $a['id'] ) );
		}

		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new WP_Error( 'mqp_gdrive', 'could not read the zip file' );
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$done = null;
		while ( time() < $deadline ) {
			fseek( $fh, $offset );
			$piece = (string) fread( $fh, (int) apply_filters( 'mqp_gdrive_chunk', self::CHUNK ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$end   = $offset + strlen( $piece ) - 1;
			$token = self::token();
			if ( is_wp_error( $token ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return $token;
			}
			$res  = wp_remote_request( $session, array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Length' => (string) strlen( $piece ), 'Content-Range' => $size ? "bytes $offset-$end/$size" : 'bytes */0' ),
				'body'    => $piece,
			) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 308 === $code ) {
				// Google keeps what it got: continue after its last byte.
				$range  = (string) wp_remote_retrieve_header( $res, 'range' );
				$offset = preg_match( '/bytes=0-(\d+)/', $range, $m ) ? (int) $m[1] + 1 : 0;
				$wpdb->update( $t, array( 'drive_offset' => $offset ), array( 'id' => $a['id'] ) );
				continue;
			}
			if ( 200 === $code || 201 === $code ) {
				$done = (array) json_decode( wp_remote_retrieve_body( $res ), true );
				break;
			}
			if ( 404 === $code || 410 === $code ) {
				// The upload session ran out (after about a week): start again next time.
				$wpdb->update( $t, array( 'drive_session' => '', 'drive_offset' => 0 ), array( 'id' => $a['id'] ) );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'mqp_gdrive', 'the upload stopped (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'Google answered ' . $code ) . ')' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $done ) {
			return 'more';
		}

		// Google's copy must be exactly ours before anything is deleted here.
		$md5 = md5_file( $path );
		if ( (int) ( $done['size'] ?? -1 ) !== $size || strtolower( (string) ( $done['md5Checksum'] ?? '' ) ) !== $md5 ) {
			$wpdb->update( $t, array( 'drive_session' => '', 'drive_offset' => 0 ), array( 'id' => $a['id'] ) );
			return new WP_Error( 'mqp_gdrive', 'Google\'s copy does not match the zip (size or checksum), so nothing was deleted; it will be uploaded again' );
		}
		$wpdb->update( $t, array( 'drive_id' => (string) $done['id'], 'drive_link' => (string) ( $done['webViewLink'] ?? '' ), 'drive_at' => mqp_now(), 'drive_session' => '', 'drive_error' => '' ), array( 'id' => $a['id'] ) );
		MQP_Monitor::log( 'archive_on_drive', $a['name'] . ' · ' . size_format( $size ) . ' · checked (MD5)' );
		if ( self::get()['auto_free'] ) {
			MQP_Archive::free_space( (int) $a['id'] );
		}
		return true;
	}

	/* ---------- wp-admin ---------- */

	private static function action_url( $op ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'op' => $op ), admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only a WordPress administrator can do this.', 403 );
		}
		$op   = sanitize_key( (string) ( $_REQUEST['op'] ?? '' ) );
		$back = admin_url( 'admin.php?page=mq-portal-system&tab=settings' ) . '#gdrive';
		$msg  = '';

		if ( 'callback' === $op ) {
			// Google sends the person back here with a one-time code; the state ties it to this admin.
			$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! wp_verify_nonce( $state, 'mqp_gdrive_connect' ) ) {
				wp_die( 'This Google sign-in link expired. Go back and click Connect again.', 403 );
			}
			if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$msg = 'error:Google Drive was not connected (' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) . ').'; // phpcs:ignore WordPress.Security.NonceVerification
			} else {
				$g    = self::get();
				$res  = wp_remote_post( self::endpoint( 'token' ), array(
					'timeout' => 20,
					'body'    => array( 'code' => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ), 'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'], 'redirect_uri' => self::redirect_uri(), 'grant_type' => 'authorization_code' ), // phpcs:ignore WordPress.Security.NonceVerification
				) );
				$body = is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
				if ( empty( $body['refresh_token'] ) ) {
					$msg = 'error:Google did not give access (' . ( is_wp_error( $res ) ? $res->get_error_message() : ( $body['error_description'] ?? $body['error'] ?? 'no answer' ) ) . ').';
				} else {
					self::put( array( 'refresh_token' => $body['refresh_token'], 'folder_id' => '', 'connected_at' => time() ) );
					if ( ! empty( $body['access_token'] ) ) {
						set_transient( 'mqp_gdrive_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
					}
					$about = self::api( 'GET', self::endpoint( 'about' ) . '?fields=user(emailAddress)' );
					$email = is_wp_error( $about ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $about ), true )['user']['emailAddress'] ?? '' );
					self::put( array( 'account' => sanitize_email( $email ) ) );
					MQP_Monitor::log( 'system', 'Connected Google Drive for archives' . ( $email ? ' (' . $email . ')' : '' ) );
					self::queue();
					$msg = 'connected';
				}
			}
			wp_safe_redirect( add_query_arg( 'gdrive', rawurlencode( $msg ), $back ) );
			exit;
		}

		check_admin_referer( self::ACTION );
		switch ( $op ) {
			case 'save':
				$g      = self::get();
				$id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
				$secret = (string) wp_unslash( $_POST['client_secret'] ?? '' );
				$change = array(
					'client_id' => $id,
					'folder'    => sanitize_text_field( wp_unslash( $_POST['folder'] ?? '' ) ) ?: 'MQ Portal archives',
					'auto_free' => empty( $_POST['auto_free'] ) ? 0 : 1,
				);
				if ( '' !== $secret && '••••••••' !== $secret ) {
					$change['client_secret'] = sanitize_text_field( $secret );
				}
				if ( $id !== $g['client_id'] ) {
					$change['refresh_token'] = ''; // Another Google app: connect again.
					$change['folder_id']     = '';
				}
				self::put( $change );
				MQP_Monitor::log( 'system', 'Saved the Google Drive settings' );
				$msg = 'saved';
				break;
			case 'connect':
				$g = self::get();
				if ( ! $g['client_id'] || ! $g['client_secret'] ) {
					$msg = 'error:Enter the Client ID and Client secret first, and save.';
					break;
				}
				wp_redirect( add_query_arg( array( // phpcs:ignore WordPress.Security.SafeRedirect -- Google's sign-in page.
					'client_id'              => $g['client_id'],
					'redirect_uri'           => self::redirect_uri(),
					'response_type'          => 'code',
					'scope'                  => self::SCOPE,
					'access_type'            => 'offline',
					'prompt'                 => 'consent',
					'include_granted_scopes' => 'true',
					'state'                  => wp_create_nonce( 'mqp_gdrive_connect' ),
				), self::endpoint( 'auth' ) ) );
				exit;
			case 'disconnect':
				$g = self::get();
				if ( $g['refresh_token'] ) {
					wp_remote_post( self::endpoint( 'revoke' ), array( 'timeout' => 10, 'body' => array( 'token' => $g['refresh_token'] ) ) );
				}
				self::put( array( 'refresh_token' => '', 'folder_id' => '', 'account' => '', 'connected_at' => 0 ) );
				delete_transient( 'mqp_gdrive_token' );
				MQP_Monitor::log( 'system', 'Disconnected Google Drive' );
				$msg = 'disconnected';
				break;
			case 'upload':
				self::queue();
				spawn_cron();
				$msg = 'queued';
				break;
		}
		wp_safe_redirect( add_query_arg( 'gdrive', rawurlencode( $msg ), $back ) );
		exit;
	}

	/** System → Settings → Google Drive. */
	public static function card() {
		$g    = self::get();
		$on   = self::connected();
		$msg  = isset( $_GET['gdrive'] ) ? sanitize_text_field( wp_unslash( $_GET['gdrive'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$text = array(
			'saved'        => array( 'success', 'Saved.' ),
			'connected'    => array( 'success', 'Google Drive is connected. New archives are uploaded automatically.' ),
			'disconnected' => array( 'success', 'Google Drive is disconnected.' ),
			'queued'       => array( 'success', 'Uploading in the background. Refresh System → Files & archives in a few minutes.' ),
		);
		echo '<div class="mqp-card" id="gdrive"><h2>Google Drive (archives)</h2>';
		if ( 0 === strpos( $msg, 'error:' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( substr( $msg, 6 ) ) . '</p></div>';
		} elseif ( isset( $text[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $text[ $msg ][0] ) . ' inline"><p>' . esc_html( $text[ $msg ][1] ) . '</p></div>';
		}
		echo '<p>' . ( $on
			? '<span class="mqp-pill is-ok">Connected</span> ' . esc_html( $g['account'] ? $g['account'] : 'Google account' ) . ' · folder “' . esc_html( $g['folder'] ) . '”. Every new archive is uploaded there and checked (size and MD5).'
			: '<span class="mqp-pill is-warn">Not connected</span> Archives stay on the hosting until you download them.' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="save"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="mqp-gd-id">Client ID</label></th><td><input id="mqp-gd-id" class="regular-text" name="client_id" value="' . esc_attr( $g['client_id'] ) . '" autocomplete="off"></td></tr>';
		echo '<tr><th scope="row"><label for="mqp-gd-secret">Client secret</label></th><td><input id="mqp-gd-secret" class="regular-text" type="password" name="client_secret" value="' . ( $g['client_secret'] ? '••••••••' : '' ) . '" autocomplete="new-password"><p class="description">Stored on this site only, never shown again or exported.</p></td></tr>';
		echo '<tr><th scope="row"><label for="mqp-gd-folder">Folder name</label></th><td><input id="mqp-gd-folder" class="regular-text" name="folder" value="' . esc_attr( $g['folder'] ) . '"></td></tr>';
		echo '<tr><th scope="row">After the upload</th><td><label><input type="checkbox" name="auto_free" value="1"' . checked( $g['auto_free'], 1, false ) . '> Free up the space on the hosting automatically once Google\'s copy is checked</label></td></tr>';
		echo '</tbody></table><p><button class="button">Save</button> ';
		if ( $on ) {
			echo '<a class="button" href="' . esc_url( self::action_url( 'upload' ) ) . '">Upload waiting archives now</a> <a class="button-link-delete" href="' . esc_url( self::action_url( 'disconnect' ) ) . '" onclick="return confirm(\'Disconnect Google Drive? Archives already there stay.\')">Disconnect</a>';
		} else {
			echo '<a class="button button-primary" href="' . esc_url( self::action_url( 'connect' ) ) . '">Connect Google Drive</a>';
		}
		echo '</p></form>';
		echo '<details><summary>How to set it up (once, about 10 minutes)</summary><ol>';
		echo '<li>Open <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> with the Google account that should keep the archives, and create a project (e.g. “MQ Portal”).</li>';
		echo '<li>APIs &amp; Services → Library → enable <strong>Google Drive API</strong>.</li>';
		echo '<li>APIs &amp; Services → OAuth consent screen: type External, add your email as a test user, then <strong>Publish app</strong> (otherwise Google signs you out every 7 days).</li>';
		echo '<li>APIs &amp; Services → Credentials → Create credentials → OAuth client ID → Web application. Under “Authorized redirect URIs” add exactly:<br><code>' . esc_html( self::redirect_uri() ) . '</code></li>';
		echo '<li>Copy the Client ID and Client secret here, Save, then click <strong>Connect Google Drive</strong> and allow access.</li>';
		echo '</ol><p class="description">The portal can only see the files it uploads itself, not the rest of the Drive.</p></details></div>';
	}
}
