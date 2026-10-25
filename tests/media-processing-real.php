<?php
/**
 * Real GD integration coverage, without loading WordPress or touching its DB.
 *
 * Run from an installed WordPress tree: php tests/media-processing-real.php
 * Uses WordPress' actual metadata generator and image editors. Only storage,
 * environment and option helpers are replaced with an in-memory test store.
 */

define( 'ABSPATH', str_replace( '\\', '/', dirname( __DIR__, 4 ) ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WP_RUN_CORE_TESTS', true );

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "SKIP: PHP GD is required for real image processing coverage.\n" );
	exit( 2 );
}

require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-error.php';

function __( $text, $domain = null ) { return $text; }
function add_shortcode( $tag, $callback ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_raise_memory_limit( $context = '' ) { return false; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function wp_basename( $path, $suffix = '' ) { return basename( wp_normalize_path( $path ), $suffix ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_is_stream( $path ) { return strpos( $path, '://' ) !== false; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_filesize( $path ) { clearstatcache( true, $path ); return filesize( $path ); }
function wp_kses_post_deep( $value ) { return $value; }
function wp_is_valid_utf8( $value ) { return true; }
function wp_fuzzy_number_match( $expected, $actual, $precision = 1 ) { return abs( $expected - $actual ) <= $precision; }
function wp_get_mime_types() { return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ); }
function wp_get_default_extension_for_mime_type( $mime ) { return array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' )[ $mime ] ?? false; }
function wp_check_filetype( $path ) {
	$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	foreach ( wp_get_mime_types() as $extensions => $mime ) {
		if ( in_array( $extension, explode( '|', $extensions ), true ) ) {
			return array( 'ext' => $extension, 'type' => $mime );
		}
	}
	return array( 'ext' => false, 'type' => false );
}
function wp_get_image_mime( $path ) { $info = @getimagesize( $path ); return is_array( $info ) ? $info['mime'] : false; }
function wp_cache_get( $key, $group = '' ) { return $GLOBALS['media_test_cache'][ $group ][ $key ] ?? false; }
function wp_cache_set( $key, $value, $group = '', $expiration = 0 ) { $GLOBALS['media_test_cache'][ $group ][ $key ] = $value; return true; }
function get_option( $key, $default = false ) {
	return array( 'thumbnail_size_w' => 150, 'thumbnail_size_h' => 150, 'thumbnail_crop' => false,
		'medium_size_w' => 300, 'medium_size_h' => 300, 'large_size_w' => 1024, 'large_size_h' => 1024 )[ $key ] ?? $default;
}
function corsivo_focal_point_get_settings() { return $GLOBALS['media_test_settings']; }
function get_attached_file( $id, $unfiltered = false ) { return $GLOBALS['media_test_posts'][ $id ]['file'] ?? false; }
function update_attached_file( $id, $file ) {
	if ( ! empty( $GLOBALS['media_test_fail_update'] ) ) { return false; }
	$GLOBALS['media_test_posts'][ $id ]['file'] = $file;
	return true;
}
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_mime_type' => $GLOBALS['media_test_posts'][ $id ]['mime'] ?? '' ); }
function get_post_mime_type( $post ) { return is_object( $post ) ? $post->post_mime_type : get_post( $post )->post_mime_type; }
function wp_get_attachment_metadata( $id, $unfiltered = false ) { return $GLOBALS['media_test_posts'][ $id ]['metadata'] ?? false; }
function wp_update_attachment_metadata( $id, $metadata ) { $GLOBALS['media_test_posts'][ $id ]['metadata'] = $metadata; return true; }
function get_post_meta( $id, $key, $single = false ) {
	if ( '_wp_attachment_metadata' === $key ) { return wp_get_attachment_metadata( $id ); }
	if ( '_wp_attached_file' === $key ) { return get_attached_file( $id ); }
	return $single ? '' : array();
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['media_test_meta'][ $id ][ $key ] = $value; return true; }
function _wp_relative_upload_path( $file ) { return wp_basename( $file ); }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['media_test_dir'], 'path' => $GLOBALS['media_test_dir'], 'baseurl' => 'https://example.invalid/uploads', 'url' => 'https://example.invalid/uploads' ); }
function wp_get_upload_dir() { return wp_upload_dir(); }
function wp_unique_filename( $directory, $filename ) {
	$extension = pathinfo( $filename, PATHINFO_EXTENSION );
	$stem = pathinfo( $filename, PATHINFO_FILENAME );
	$candidate = $filename;
	for ( $number = 1; file_exists( $directory . '/' . $candidate ); ++$number ) {
		$candidate = $stem . '-' . $number . '.' . $extension;
	}
	return $candidate;
}
function wp_delete_file( $file ) {
	$resolved = realpath( $file );
	if ( false !== $resolved && wp_normalize_path( dirname( $resolved ) ) === wp_normalize_path( $GLOBALS['media_test_dir'] ) ) {
		return unlink( $resolved );
	}
	throw new RuntimeException( 'Test attempted to delete outside its own fixture directory.' );
}

require_once ABSPATH . WPINC . '/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
require_once dirname( __DIR__ ) . '/includes/media-processing.php';

class Corsivo_Media_Test_No_WebP_GD extends WP_Image_Editor_GD {
	public static function supports_mime_type( $mime_type ) {
		return 'image/webp' !== $mime_type && parent::supports_mime_type( $mime_type );
	}
}
class Corsivo_Media_Test_Save_Failure_GD extends WP_Image_Editor_GD {
	public function save( $destfilename = null, $mime_type = null ) {
		return new WP_Error( 'test_save_error', 'Injected encoder save failure.' );
	}
}

add_filter( 'wp_image_editors', function () { return array( $GLOBALS['media_test_editor'] ?? 'WP_Image_Editor_GD' ); } );

$GLOBALS['media_test_dir'] = wp_normalize_path( sys_get_temp_dir() ) . '/corsivo-media-gd-' . bin2hex( random_bytes( 8 ) );
mkdir( $GLOBALS['media_test_dir'] );
$GLOBALS['media_test_assertions'] = 0;

function media_real_assert( $condition, $message ) {
	++$GLOBALS['media_test_assertions'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function media_real_settings( $overrides = array() ) {
	$GLOBALS['media_test_settings'] = array_replace( array(
		'optimization_enabled' => true, 'image_quality' => 60, 'max_dimension' => 512, 'webp_enabled' => false,
	), $overrides );
}
function media_real_reset() {
	$state = &corsivo_media_upload_state();
	$state = array( 'uploads' => array(), 'attachments' => array(), 'active' => 0, 'quality_file' => '', 'client_managed' => false );
	$GLOBALS['media_test_posts'] = array();
	$GLOBALS['media_test_meta'] = array();
	$GLOBALS['media_test_cache'] = array();
	$GLOBALS['media_test_editor'] = 'WP_Image_Editor_GD';
	$GLOBALS['media_test_fail_update'] = false;
	media_real_settings();
}
function media_real_fixture( $name, $width = 640, $height = 400, $png = false ) {
	$path = $GLOBALS['media_test_dir'] . '/' . $name;
	$image = imagecreatetruecolor( $width, $height );
	if ( $png ) {
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
		imagefilledrectangle( $image, (int) ( $width / 3 ), (int) ( $height / 3 ), (int) ( $width * 2 / 3 ), (int) ( $height * 2 / 3 ), imagecolorallocatealpha( $image, 240, 80, 30, 0 ) );
		imagepng( $image, $path );
	} else {
		// Deterministic texture makes the lossless-original/quality-copy comparison meaningful.
		for ( $y = 0; $y < $height; ++$y ) {
			for ( $x = 0; $x < $width; ++$x ) {
				$color = ( ( ( $x * 17 + $y * 23 ) % 256 ) << 16 ) | ( ( ( $x * 7 + $y * 13 ) % 256 ) << 8 ) | ( ( $x * 31 + $y * 3 ) % 256 );
				imagesetpixel( $image, $x, $y, $color );
			}
		}
		imagejpeg( $image, $path, 100 );
	}
	return $path;
}
function media_real_generate( $id, $path, $new_upload = true ) {
	$GLOBALS['media_test_posts'][ $id ] = array( 'file' => $path, 'mime' => wp_get_image_mime( $path ) );
	if ( $new_upload ) {
		$upload = array( 'file' => $path, 'url' => 'https://example.invalid/uploads/' . wp_basename( $path ), 'type' => wp_get_image_mime( $path ) );
		media_real_assert( $upload === apply_filters( 'wp_handle_upload', $upload, 'upload' ), 'Upload result is not rewritten before the attachment exists.' );
		do_action( 'add_attachment', $id );
	}
	$metadata = wp_generate_attachment_metadata( $id, $path );
	wp_update_attachment_metadata( $id, $metadata );
	return $metadata;
}
function media_real_check_files( $id, $metadata ) {
	$full = get_attached_file( $id );
	media_real_assert( is_file( $full ), 'Published full-size file exists.' );
	media_real_assert( wp_basename( $full ) === $metadata['file'], 'Published path and metadata agree.' );
	$info = getimagesize( $full );
	media_real_assert( $info[0] === $metadata['width'] && $info[1] === $metadata['height'], 'Full-size dimensions match real encoded data.' );
	foreach ( $metadata['sizes'] as $size ) {
		$path = $GLOBALS['media_test_dir'] . '/' . $size['file'];
		$info = getimagesize( $path );
		media_real_assert( $info[0] === $size['width'] && $info[1] === $size['height'] && $info['mime'] === $size['mime-type'], 'Responsive derivative metadata matches its real file.' );
	}
}

try {
	media_real_reset();
	media_real_settings( array( 'max_dimension' => 2560 ) );
	$source = media_real_fixture( 'quality-only.jpg' );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 1, $source );
	media_real_assert( $metadata['original_image'] === 'quality-only.jpg', 'Quality-only optimization retains the original reference.' );
	media_real_assert( get_attached_file( 1 ) !== $source, 'Quality-only optimization produces a separate full-size copy.' );
	media_real_assert( wp_filesize( get_attached_file( 1 ) ) < wp_filesize( $source ), 'Quality-only copy is smaller than the original.' );
	media_real_assert( hash_file( 'sha256', $source ) === $hash, 'Quality-only optimization leaves original bytes intact.' );
	media_real_check_files( 1, $metadata );
	media_real_assert( 0 === corsivo_media_upload_state()['active'], 'Completed metadata generation releases its context.' );
	media_real_assert( 81 === apply_filters( 'wp_editor_set_quality', 81, 'image/jpeg', array() ), 'Completed upload does not leak its quality into a later editor.' );

	media_real_reset();
	$source = media_real_fixture( 'resize.jpg', 1200, 800 );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 2, $source );
	media_real_assert( 512 === $metadata['width'] && 341 === $metadata['height'], 'Core scales the new full-size image to the configured limit.' );
	media_real_assert( hash_file( 'sha256', $source ) === $hash, 'Resize leaves original bytes intact.' );
	media_real_check_files( 2, $metadata );

	media_real_reset();
	$source = media_real_fixture( 'resize-transparent.png', 800, 600, true );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 21, $source );
	media_real_assert( 512 === $metadata['width'] && 384 === $metadata['height'], 'Static PNG is resized to the configured limit without WebP.' );
	$decoded = imagecreatefrompng( get_attached_file( 21 ) );
	media_real_assert( 127 === imagecolorsforindex( $decoded, imagecolorat( $decoded, 0, 0 ) )['alpha'], 'PNG resize preserves its transparent corner.' );
	media_real_assert( 0 === imagecolorsforindex( $decoded, imagecolorat( $decoded, 256, 192 ) )['alpha'], 'PNG resize preserves opaque content.' );
	media_real_assert( hash_file( 'sha256', $source ) === $hash, 'PNG resize preserves original bytes.' );
	media_real_check_files( 21, $metadata );

	if ( WP_Image_Editor_GD::supports_mime_type( 'image/webp' ) ) {
		media_real_reset();
		media_real_settings( array( 'webp_enabled' => true ) );
		$source = media_real_fixture( 'resize-webp.jpg', 1200, 800 );
		$hash = hash_file( 'sha256', $source );
		$metadata = media_real_generate( 3, $source );
		media_real_assert( 'image/webp' === wp_get_image_mime( get_attached_file( 3 ) ), 'Resize and format conversion produce a real WebP full-size image.' );
		foreach ( $metadata['sizes'] as $size ) {
			media_real_assert( 'image/webp' === $size['mime-type'], 'New responsive sizes also use the selected WebP format.' );
		}
		media_real_assert( hash_file( 'sha256', $source ) === $hash, 'WebP conversion leaves original JPEG bytes intact.' );
		media_real_check_files( 3, $metadata );

		media_real_reset();
		media_real_settings( array( 'webp_enabled' => true, 'optimization_enabled' => false ) );
		$source = media_real_fixture( 'transparent.png', 320, 240, true );
		$hash = hash_file( 'sha256', $source );
		$metadata = media_real_generate( 4, $source );
		media_real_assert( 'image/webp' === wp_get_image_mime( get_attached_file( 4 ) ), 'WebP can run with optimization disabled.' );
		$decoded = imagecreatefromwebp( get_attached_file( 4 ) );
		media_real_assert( 127 === imagecolorsforindex( $decoded, imagecolorat( $decoded, 0, 0 ) )['alpha'], 'Transparent PNG corner remains transparent in WebP.' );
		media_real_assert( 0 === imagecolorsforindex( $decoded, imagecolorat( $decoded, 160, 120 ) )['alpha'], 'Opaque PNG content remains opaque in WebP.' );
		media_real_assert( hash_file( 'sha256', $source ) === $hash, 'PNG original remains byte-identical.' );
		media_real_check_files( 4, $metadata );
	}

	media_real_reset();
	media_real_settings( array( 'webp_enabled' => true ) );
	$source = media_real_fixture( 'existing.jpg', 800, 600 );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 5, $source, false );
	media_real_assert( $metadata['width'] === 800 && empty( $metadata['original_image'] ), 'Regenerating an existing attachment does not apply the new-upload policy.' );
	media_real_assert( get_attached_file( 5 ) === $source && hash_file( 'sha256', $source ) === $hash, 'Existing full image and URL remain unchanged.' );

	media_real_reset();
	media_real_settings( array( 'webp_enabled' => true ) );
	$source = media_real_fixture( 'animation.png', 320, 240, true );
	$png_bytes = file_get_contents( $source );
	$animation_data = pack( 'NN', 2, 0 );
	$animation_chunk = pack( 'N', strlen( $animation_data ) ) . 'acTL' . $animation_data . pack( 'N', crc32( 'acTL' . $animation_data ) );
	// The valid acTL chunk is enough to identify APNG; pixel decoding still uses real GD.
	file_put_contents( $source, substr( $png_bytes, 0, 33 ) . $animation_chunk . substr( $png_bytes, 33 ) );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 51, $source );
	media_real_assert( $source === get_attached_file( 51 ) && hash_file( 'sha256', $source ) === $hash, 'An APNG upload is excluded from the plugin policy and keeps its original bytes.' );

	media_real_reset();
	media_real_settings( array( 'optimization_enabled' => false, 'webp_enabled' => false ) );
	$source = media_real_fixture( 'disabled.jpg', 800, 600 );
	$metadata = media_real_generate( 52, $source );
	media_real_assert( $source === get_attached_file( 52 ) && $metadata['width'] === 800, 'Disabled modules preserve normal core upload behavior.' );
	media_real_assert( true === apply_filters( 'wp_client_side_media_processing_enabled', true )
		&& false === apply_filters( 'wp_client_side_media_processing_enabled', false ), 'Disabled modules preserve the existing client-processing policy.' );

	media_real_reset();
	media_real_settings( array( 'webp_enabled' => true ) );
	$request = new class {
		public function get_method() { return 'POST'; }
		public function get_route() { return '/wp/v2/media'; }
		public function get_param( $name ) { return 'generate_sub_sizes' === $name ? false : null; }
	};
	apply_filters( 'rest_request_before_callbacks', null, null, $request );
	$source = media_real_fixture( 'client-managed.jpg', 800, 600 );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 53, $source );
	media_real_assert( $source === get_attached_file( 53 ) && $metadata['width'] === 800
		&& hash_file( 'sha256', $source ) === $hash, 'An already-open client-managed upload is not processed again by the plugin.' );
	media_real_assert( false === apply_filters( 'wp_client_side_media_processing_enabled', true ), 'Active modules select server-side processing for subsequent editors.' );
	apply_filters( 'rest_request_after_callbacks', null, null, $request );
	media_real_assert( false === corsivo_media_upload_state()['client_managed'], 'REST request completion releases the client-managed guard.' );

	media_real_reset();
	$source = media_real_fixture( 'untracked-insert.jpg', 800, 600 );
	$GLOBALS['media_test_posts'][54] = array( 'file' => $source, 'mime' => 'image/jpeg' );
	do_action( 'add_attachment', 54 );
	$metadata = wp_generate_attachment_metadata( 54, $source );
	media_real_assert( $source === get_attached_file( 54 ) && $metadata['width'] === 800, 'A new attachment ID alone cannot process a file that did not pass through upload tracking.' );

	media_real_reset();
	media_real_settings( array( 'webp_enabled' => true, 'optimization_enabled' => false ) );
	$GLOBALS['media_test_editor'] = 'Corsivo_Media_Test_No_WebP_GD';
	$source = media_real_fixture( 'unsupported-webp.jpg' );
	$metadata = media_real_generate( 6, $source );
	media_real_assert( $source === get_attached_file( 6 ) && 'image/jpeg' === wp_get_image_mime( $source ), 'A backend without WebP preserves the original format.' );
	media_real_check_files( 6, $metadata );

	media_real_reset();
	media_real_settings( array( 'max_dimension' => 2560 ) );
	$GLOBALS['media_test_editor'] = 'Corsivo_Media_Test_Save_Failure_GD';
	$source = media_real_fixture( 'save-failure.jpg' );
	$hash = hash_file( 'sha256', $source );
	$metadata = media_real_generate( 7, $source );
	media_real_assert( $source === get_attached_file( 7 ) && empty( $metadata['original_image'] ), 'Encoder failure does not publish a new full-size path.' );
	media_real_assert( hash_file( 'sha256', $source ) === $hash && 0 === corsivo_media_upload_state()['active'], 'Encoder failure preserves original bytes and releases context.' );

	media_real_reset();
	media_real_settings( array( 'max_dimension' => 2560 ) );
	$GLOBALS['media_test_fail_update'] = true;
	$source = media_real_fixture( 'update-failure.jpg' );
	$metadata = media_real_generate( 8, $source );
	media_real_assert( $source === get_attached_file( 8 ) && empty( $metadata['original_image'] ), 'Storage failure does not publish optimized metadata.' );
	media_real_assert( ! file_exists( $GLOBALS['media_test_dir'] . '/update-failure-optimized.jpg' ), 'Rejected optimized copy is removed after storage failure.' );

	if ( WP_Image_Editor_GD::supports_mime_type( 'image/webp' ) ) {
		media_real_reset();
		media_real_settings( array( 'webp_enabled' => true, 'optimization_enabled' => false ) );
		$GLOBALS['media_test_fail_update'] = true;
		$source = media_real_fixture( 'core-update-failure.jpg' );
		$hash = hash_file( 'sha256', $source );
		$metadata = media_real_generate( 10, $source );
		media_real_assert( wp_basename( get_attached_file( 10 ) ) === $metadata['file'], 'Failed core conversion path update leaves metadata and attached file consistent.' );
		media_real_assert( hash_file( 'sha256', $source ) === $hash, 'Failed core conversion preserves original bytes.' );
		media_real_check_files( 10, $metadata );

		foreach ( array( 'full', 'derivative' ) as $collision_kind ) {
			media_real_reset();
			media_real_settings( array( 'webp_enabled' => true, 'optimization_enabled' => false ) );
			$basename = 'collision-' . $collision_kind;
			$source = media_real_fixture( $basename . '.jpg' );
			$collision_path = $GLOBALS['media_test_dir'] . '/' . $basename . ( 'full' === $collision_kind ? '' : '-300x188' ) . '.webp';
			$existing = imagecreatetruecolor( 32, 24 );
			imagefill( $existing, 0, 0, imagecolorallocate( $existing, 10, 200, 40 ) );
			imagewebp( $existing, $collision_path, 90 );
			$existing_hash = hash_file( 'sha256', $collision_path );
			$metadata = media_real_generate( 9, $source );
			media_real_assert( hash_file( 'sha256', $collision_path ) === $existing_hash, 'A pre-existing ' . $collision_kind . ' WebP file is never overwritten.' );
			media_real_assert( get_attached_file( 9 ) !== $collision_path, 'New upload does not adopt another attachment\'s WebP file.' );
			media_real_check_files( 9, $metadata );
		}

	}

	fwrite( STDOUT, 'PASS: ' . $GLOBALS['media_test_assertions'] . " real GD/core image assertions.\n" );
} finally {
	// The directory was generated above; remove only its direct fixture files.
	foreach ( glob( $GLOBALS['media_test_dir'] . '/*' ) as $fixture ) {
		if ( is_file( $fixture ) ) { wp_delete_file( $fixture ); }
	}
	rmdir( $GLOBALS['media_test_dir'] );
}
