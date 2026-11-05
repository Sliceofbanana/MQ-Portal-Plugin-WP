<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff profile pictures. Uploaded photos are cropped to a square, resized and saved as JPEG
 * in the protected portal folder (avatars/). Only logged-in staff can load them.
 */
class MQP_Avatar {

	const META     = 'mqp_avatar'; // Path relative to MQP_Files::base_dir(), e.g. avatars/u12-Ab3dE9xY.jpg
	const SIZE     = 256;
	const MAX_SIZE = 8; // MB

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/avatar/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'serve' ),
			'permission_callback' => MQP_REST::can( 'mq_access_portal' ),
		) );
		register_rest_route( $ns, '/me/avatar', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_mine' ), 'permission_callback' => MQP_REST::can( 'mq_access_portal' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove_mine' ), 'permission_callback' => MQP_REST::can( 'mq_access_portal' ) ),
		) );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/avatar', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_for' ), 'permission_callback' => MQP_REST::can( 'mq_manage' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove_for' ), 'permission_callback' => MQP_REST::can( 'mq_manage' ) ),
		) );
	}

	/** Short version string for cache-busting URLs; null when the user has no photo. */
	public static function version( $user_id ) {
		$path = get_user_meta( (int) $user_id, self::META, true );
		return $path ? substr( md5( $path ), 0, 8 ) : null;
	}

	public static function upload_mine( WP_REST_Request $req ) {
		return self::upload( get_current_user_id(), $req );
	}

	public static function remove_mine() {
		self::remove( get_current_user_id() );
		return array( 'avatar' => null );
	}

	public static function upload_for( WP_REST_Request $req ) {
		$check = self::can_edit( (int) $req['id'] );
		return is_wp_error( $check ) ? $check : self::upload( (int) $req['id'], $req );
	}

	public static function remove_for( WP_REST_Request $req ) {
		$check = self::can_edit( (int) $req['id'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		self::remove( (int) $req['id'] );
		return array( 'avatar' => null );
	}

	/** Same rule as editing the account: only WP administrators can change an administrator's account. */
	private static function can_edit( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! MQP_Roles::is_staff( $user ) ) {
			return MQP_REST::error( 'User not found.', 404 );
		}
		if ( in_array( 'administrator', $user->roles, true ) && ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can edit this account.', 403 );
		}
		return true;
	}

	private static function upload( $user_id, WP_REST_Request $req ) {
		$file = $req->get_file_params()['file'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return MQP_REST::error( 'Choose a photo to upload.' );
		}
		if ( $file['size'] > self::MAX_SIZE * MB_IN_BYTES ) {
			return MQP_REST::error( sprintf( 'That photo is too large. Please use one under %d MB.', self::MAX_SIZE ) );
		}
		// Check the actual contents, not just the name.
		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return MQP_REST::error( 'Please upload a JPG, PNG or WebP photo.' );
		}

		$editor = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $editor ) ) {
			return MQP_REST::error( 'This photo could not be read. Please try another one.' );
		}
		// Phone photos are often stored sideways with an orientation tag; turn them upright first.
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$size = $editor->get_size();
		$side = min( $size['width'], $size['height'] );
		$editor->crop( (int) ( ( $size['width'] - $side ) / 2 ), (int) ( ( $size['height'] - $side ) / 2 ), $side, $side, self::SIZE, self::SIZE );
		$editor->set_quality( 85 );

		$dir = MQP_Files::base_dir() . '/avatars';
		wp_mkdir_p( $dir );
		$name  = 'u' . (int) $user_id . '-' . wp_generate_password( 10, false ) . '.jpg';
		$saved = $editor->save( $dir . '/' . $name, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return MQP_REST::error( 'The photo could not be saved. Please try again.' );
		}
		self::remove( $user_id );
		update_user_meta( $user_id, self::META, 'avatars/' . $name );
		return array( 'avatar' => self::version( $user_id ) );
	}

	/** Also used when a WordPress user is deleted. */
	public static function remove( $user_id ) {
		$path = get_user_meta( $user_id, self::META, true );
		if ( $path ) {
			$file = MQP_Files::base_dir() . '/' . $path;
			if ( 0 === strpos( $path, 'avatars/' ) && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			delete_user_meta( $user_id, self::META );
		}
	}

	/** Streams a user's photo. The URL carries ?v=<version>, so the browser may keep it. */
	public static function serve( WP_REST_Request $req ) {
		$path = get_user_meta( (int) $req['id'], self::META, true );
		$base = realpath( MQP_Files::base_dir() );
		$file = $path ? realpath( MQP_Files::base_dir() . '/' . $path ) : false;
		if ( ! $file || ! $base || 0 !== strpos( $file, $base ) || ! is_readable( $file ) ) {
			return MQP_REST::error( 'No photo.', 404 );
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: private, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $file );
		exit;
	}
}
