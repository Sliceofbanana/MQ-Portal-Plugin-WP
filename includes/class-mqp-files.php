<?php
defined( 'ABSPATH' ) || exit;

/**
 * Private file storage under wp-content/uploads/mq-portal/. Direct web access is
 * blocked; files are only served through the authenticated REST download route.
 */
class MQP_Files {

	public static function base_dir() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . 'mq-portal';
	}

	public static function protect_dir() {
		$dir = self::base_dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( "$dir/.htaccess" ) ) {
			file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( "$dir/index.php" ) ) {
			file_put_contents( "$dir/index.php", "<?php // Silence.\n" );
		}
	}

	public static function allowed_extensions() {
		return array_filter( explode( ',', MQP_Settings::get( 'upload_types' ) ) );
	}

	/** Staff can attach design files of more types than customers. */
	public static function staff_extensions() {
		return array_unique( array_merge( self::allowed_extensions(), array( 'pdf', 'png', 'jpg', 'jpeg', 'ai', 'psd', 'eps', 'svg', 'tif', 'tiff', 'cdr', 'zip' ) ) );
	}

	/**
	 * Validates and moves one entry of $_FILES. Returns the files-table row data or WP_Error.
	 *
	 * @param array $file   Single file array (name, tmp_name, size, error).
	 * @param array $allowed Allowed extensions.
	 */
	public static function store( array $file, array $allowed, $max_bytes ) {
		if ( ! empty( $file['error'] ) ) {
			$msg = in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'File is too large.' : 'Upload failed.';
			return new WP_Error( 'mqp_upload', $msg );
		}
		if ( $file['size'] > $max_bytes ) {
			return new WP_Error( 'mqp_upload', sprintf( '%s is larger than %d MB.', $file['name'], $max_bytes / MB_IN_BYTES ) );
		}
		$name = sanitize_file_name( wp_basename( $file['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'mqp_upload', sprintf( '%s: file type .%s is not allowed.', $file['name'], $ext ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'mqp_upload', 'Invalid upload.' );
		}
		if ( ! self::content_matches( $file['tmp_name'], $ext ) ) {
			return new WP_Error( 'mqp_upload', sprintf( '%s is not a real .%s file (it may be damaged or renamed). Please save it again as .%s and upload it.', $file['name'], $ext, $ext ) );
		}

		self::protect_dir();
		$sub = gmdate( 'Y/m' );
		$dir = self::base_dir() . '/' . $sub;
		// PHP may remember a folder that was deleted meanwhile (e.g. by Start fresh): check for real.
		clearstatcache( true, $dir );
		wp_mkdir_p( $dir );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		$stored = $sub . '/' . wp_generate_password( 12, false ) . '_' . $name;
		if ( ! @move_uploaded_file( $file['tmp_name'], self::base_dir() . '/' . $stored ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			// Recorded for wp-admin → System → Problems (the folder state and PHP's reason).
			$why = error_get_last();
			MQP_Monitor::record( 'request', 'Could not save an uploaded file', wp_json_encode( array( 'dir_exists' => is_dir( $dir ), 'dir_writable' => is_writable( $dir ), 'tmp_exists' => is_file( $file['tmp_name'] ), 'php' => $why ? $why['message'] : '' ) ) );
			return new WP_Error( 'mqp_upload', 'Could not save the file.' );
		}
		$check = wp_check_filetype( $name );
		return array(
			'original_name' => $name,
			'stored_path'   => $stored,
			'mime'          => $check['type'] ? $check['type'] : 'application/octet-stream',
			'size'          => (int) $file['size'],
			'created_at'    => mqp_now(),
		);
	}

	/**
	 * Whether a file's first bytes match its extension (a ".pdf" must really be a PDF, a ".jpg" a
	 * picture…). Types we can't check this way are refused only when they contain web page or
	 * PHP code.
	 */
	public static function content_matches( $path, $ext ) {
		$head = (string) @file_get_contents( $path, false, null, 0, 2048 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( '' === $head ) {
			return false;
		}
		$starts = function ( array $sigs ) use ( $head ) {
			foreach ( $sigs as $sig ) {
				if ( 0 === strncmp( $head, $sig, strlen( $sig ) ) ) {
					return true;
				}
			}
			return false;
		};
		$image = function ( array $types ) use ( $path ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $info && in_array( $info[2], $types, true );
		};
		switch ( $ext ) {
			case 'pdf':
				return false !== strpos( substr( $head, 0, 1024 ), '%PDF-' );
			case 'ai': // PDF-based, or PostScript (older versions).
				return false !== strpos( substr( $head, 0, 1024 ), '%PDF-' ) || $starts( array( '%!PS' ) );
			case 'eps':
			case 'ps':
				return $starts( array( '%!PS', "\xC5\xD0\xD3\xC6" ) );
			case 'jpg':
			case 'jpeg':
				return $image( array( IMAGETYPE_JPEG ) );
			case 'png':
				return $image( array( IMAGETYPE_PNG ) );
			case 'gif':
				return $image( array( IMAGETYPE_GIF ) );
			case 'webp':
				return defined( 'IMAGETYPE_WEBP' ) ? $image( array( IMAGETYPE_WEBP ) ) : $starts( array( 'RIFF' ) );
			case 'tif':
			case 'tiff':
				return $starts( array( "II*\x00", "MM\x00*" ) );
			case 'psd':
				return $starts( array( '8BPS' ) );
			case 'zip':
			case 'docx':
			case 'xlsx':
			case 'pptx':
				return $starts( array( "PK\x03\x04", "PK\x05\x06" ) );
			case 'doc':
			case 'xls':
			case 'ppt':
				return $starts( array( "\xD0\xCF\x11\xE0" ) );
			case 'svg':
				return false !== stripos( $head, '<svg' ) && ! preg_match( '/<script|on[a-z]+\s*=|javascript:/i', (string) @file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		// Anything else (e.g. .cdr): refused only if it is really a web page or PHP code.
		return ! preg_match( '/<\?php|<script|<html|<!doctype html/i', $head );
	}

	/** Normalises $_FILES['x'] with multiple entries into a list of single-file arrays. */
	public static function normalize( $files ) {
		if ( empty( $files ) || empty( $files['name'] ) ) {
			return array();
		}
		if ( ! is_array( $files['name'] ) ) {
			return array( $files );
		}
		$out = array();
		foreach ( $files['name'] as $i => $name ) {
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
		}
		return $out;
	}

	public static function insert( array $row ) {
		global $wpdb;
		$wpdb->insert( mqp_table( 'files' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** Payment screenshots are for staff who handle payments, never for Creatives or Production. */
	public static function sees_payments() {
		return current_user_can( 'mq_record_payments' ) || current_user_can( 'mq_view_dashboard' );
	}

	public static function for_job( $job_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE job_id = %d ORDER BY id', $job_id ), ARRAY_A );
		if ( ! self::sees_payments() ) {
			$rows = array_values( array_filter( $rows, function ( $r ) {
				return 'payment' !== $r['kind'];
			} ) );
		}
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	/**
	 * Whether the current person may open this file: a job file needs access to that job order
	 * (sales staff: their own), a payment screenshot needs payment rights, a customer upload needs
	 * Sales. Files not yet part of an order (drafts) are never opened from the portal.
	 */
	public static function can_open( array $row ) {
		if ( in_array( $row['kind'], array( 'order_draft', 'payment_draft' ), true ) ) {
			return false;
		}
		if ( 'payment' === $row['kind'] && ! self::sees_payments() ) {
			return false;
		}
		if ( ! empty( $row['job_id'] ) ) {
			return ! is_wp_error( MQP_REST_Jobs::load( (int) $row['job_id'] ) );
		}
		if ( ! empty( $row['upload_id'] ) ) {
			return current_user_can( 'mq_sales' );
		}
		return false;
	}

	public static function for_upload( $upload_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE upload_id = %d ORDER BY id', $upload_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function format( $row ) {
		return array(
			'id'          => (int) $row['id'],
			'kind'        => $row['kind'],
			'name'        => $row['original_name'],
			'mime'        => $row['mime'],
			'size'        => (int) $row['size'],
			'uploaded_by' => mqp_user_brief( (int) $row['uploaded_by'] ),
			'created_at'  => $row['created_at'],
			'archived'    => empty( $row['archived_at'] ) ? null : $row['archived_at'],
			'url'         => rest_url( 'mq/v1/files/' . (int) $row['id'] ),
		);
	}

	/** Streams a file to the browser. Caller must have checked permissions. */
	public static function stream( $row, $inline = false ) {
		$path = realpath( self::base_dir() . '/' . $row['stored_path'] );
		$base = realpath( self::base_dir() );
		if ( ! $path || ! $base || 0 !== strpos( $path, $base ) || ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: ' . $row['mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		$disposition = $inline ? 'inline' : 'attachment';
		header( 'Content-Disposition: ' . $disposition . '; filename="' . str_replace( '"', '', $row['original_name'] ) . '"' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path );
		exit;
	}
}
