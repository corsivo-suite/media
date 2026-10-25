<?php
/**
 * Non-destructive image processing for uploads created in the current request.
 *
 * No persistent queue or retrospective processing is registered here. Both the
 * uploaded path and the newly inserted attachment are required before a policy
 * can affect WordPress' image editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Request-local provenance. Nothing in this registry survives a request. */
function &corsivo_media_upload_state() {
	static $state = array(
		'uploads'        => array(),
		'attachments'    => array(),
		'active'         => 0,
		'quality_file'   => '',
		'client_managed' => false,
	);
	return $state;
}

function corsivo_media_processing_settings() {
	$settings = corsivo_focal_point_get_settings();
	return array(
		'optimization_enabled' => ! empty( $settings['optimization_enabled'] ),
		'webp_enabled'         => ! empty( $settings['webp_enabled'] ),
		'image_quality'        => max( 40, min( 100, (int) ( $settings['image_quality'] ?? 82 ) ) ),
		'max_dimension'        => max( 256, min( 8192, (int) ( $settings['max_dimension'] ?? 2560 ) ) ),
	);
}

function corsivo_media_file_key( $file ) {
	if ( ! is_string( $file ) || '' === $file || strpos( $file, '://' ) !== false ) {
		return '';
	}
	$resolved = realpath( $file );
	if ( false === $resolved || ! is_file( $resolved ) ) {
		return '';
	}
	$resolved = str_replace( '\\', '/', $resolved );
	return '\\' === DIRECTORY_SEPARATOR ? strtolower( $resolved ) : $resolved;
}

/**
 * Check PNG chunks without loading the compressed pixels into memory.
 * Malformed PNGs are deliberately ineligible, as are APNG animations.
 */
function corsivo_media_is_static_png( $file ) {
	$handle = @fopen( $file, 'rb' );
	if ( false === $handle ) {
		return false;
	}
	try {
		if ( "\x89PNG\r\n\x1a\n" !== fread( $handle, 8 ) ) {
			return false;
		}
		$stat = fstat( $handle );
		$size = is_array( $stat ) ? (int) $stat['size'] : 0;
		while ( ! feof( $handle ) ) {
			$header = fread( $handle, 8 );
			if ( 8 !== strlen( $header ) ) {
				return false;
			}
			$length = unpack( 'Nlength', substr( $header, 0, 4 ) )['length'];
			$type   = substr( $header, 4, 4 );
			if ( 'acTL' === $type || $length > $size - ftell( $handle ) - 4 ) {
				return false;
			}
			// APNG requires acTL before the first IDAT chunk.
			if ( 'IDAT' === $type ) {
				return true;
			}
			if ( 'IEND' === $type || 0 !== fseek( $handle, $length + 4, SEEK_CUR ) ) {
				return false;
			}
		}
		return false;
	} finally {
		fclose( $handle );
	}
}

/** Record only successful, newly moved uploads; never trust the browser MIME. */
function corsivo_media_track_upload( $upload, $context = 'upload' ) {
	$state    = &corsivo_media_upload_state();
	$settings = corsivo_media_processing_settings();
	if ( $state['client_managed'] || ( ! $settings['optimization_enabled'] && ! $settings['webp_enabled'] )
		|| ! in_array( $context, array( 'upload', 'sideload' ), true )
		|| ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
		return $upload;
	}
	$key = corsivo_media_file_key( $upload['file'] );
	if ( '' === $key ) {
		return $upload;
	}
	$mime = wp_get_image_mime( $upload['file'] );
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true )
		|| ( 'image/png' === $mime && ! corsivo_media_is_static_png( $upload['file'] ) ) ) {
		return $upload;
	}
	$state['uploads'][ $key ] = array(
		'file'     => $upload['file'],
		'key'      => $key,
		'mime'     => $mime,
		'settings' => $settings,
	);
	return $upload;
}
add_filter( 'wp_handle_upload', 'corsivo_media_track_upload', 20, 2 );

/** add_attachment fires only on insertion, after the attached path is stored. */
function corsivo_media_track_attachment( $attachment_id ) {
	$state = &corsivo_media_upload_state();
	$file  = get_attached_file( $attachment_id, true );
	$key   = corsivo_media_file_key( $file );
	if ( '' !== $key && isset( $state['uploads'][ $key ] ) ) {
		$state['attachments'][ (int) $attachment_id ] = $state['uploads'][ $key ];
		unset( $state['uploads'][ $key ] );
	}
}
add_action( 'add_attachment', 'corsivo_media_track_attachment', 20 );

/**
 * Upload naming happens before the request-local format policy is active.
 * Core cannot reserve alternate WebP names at that point. If a full image or
 * any same-name WebP derivative exists, keep this upload in its source format.
 */
function corsivo_media_has_webp_collision( $file ) {
	$stem = pathinfo( $file, PATHINFO_FILENAME );
	try {
		foreach ( new DirectoryIterator( dirname( $file ) ) as $entry ) {
			if ( ! $entry->isFile() || 'webp' !== strtolower( $entry->getExtension() ) ) {
				continue;
			}
			$name = $entry->getBasename( '.' . $entry->getExtension() );
			if ( 0 === strcasecmp( $stem, $name ) || 0 === strncasecmp( $name, $stem . '-', strlen( $stem ) + 1 ) ) {
				return true;
			}
		}
	} catch ( UnexpectedValueException $error ) {
		return true;
	}
	return false;
}

/** Begin only the first metadata generation for the witnessed upload and ID. */
function corsivo_media_begin_processing( $threshold, $imagesize, $file, $attachment_id ) {
	$state                  = &corsivo_media_upload_state();
	$state['active']        = 0;
	$state['quality_file']  = '';
	$attachment_id          = (int) $attachment_id;
	$record                 = $state['attachments'][ $attachment_id ] ?? null;
	if ( ! $record || $state['client_managed'] || $record['key'] !== corsivo_media_file_key( $file )
		|| $record['key'] !== corsivo_media_file_key( get_attached_file( $attachment_id, true ) )
		|| ! empty( wp_get_attachment_metadata( $attachment_id ) ) ) {
		return $threshold;
	}
	if ( $record['settings']['webp_enabled'] && corsivo_media_has_webp_collision( $file ) ) {
		$state['attachments'][ $attachment_id ]['settings']['webp_enabled'] = false;
	}
	$state['active'] = $attachment_id;
	return $record['settings']['optimization_enabled'] ? $record['settings']['max_dimension'] : $threshold;
}
add_filter( 'big_image_size_threshold', 'corsivo_media_begin_processing', 20, 4 );

/** Convert the witnessed source only; unrelated image editors keep their policy. */
function corsivo_media_output_format( $formats, $filename, $mime_type ) {
	$state                  = &corsivo_media_upload_state();
	$record                 = $state['attachments'][ $state['active'] ] ?? null;
	if ( ! $record ) {
		$state['quality_file'] = '';
		return $formats;
	}
	$key = corsivo_media_file_key( $filename );
	$eligible = $record['key'] === $key;
	// make_subsize() asks for a format without a destination name. Preserve
	// provenance only if the last editor source was this witnessed upload.
	if ( ! $eligible && $state['quality_file'] === $record['key'] ) {
		if ( null === $filename || '' === $filename ) {
			$eligible = true;
		} elseif ( is_string( $filename ) && '' === $key && strpos( $filename, '://' ) === false ) {
			$directory = realpath( dirname( $filename ) );
			$directory = false === $directory ? '' : str_replace( '\\', '/', $directory );
			$directory = '\\' === DIRECTORY_SEPARATOR ? strtolower( $directory ) : $directory;
			$stem      = preg_quote( pathinfo( $record['file'], PATHINFO_FILENAME ), '/' );
			$eligible  = '' !== $directory && $directory === dirname( $record['key'] )
				&& 1 === preg_match( '/^' . $stem . '(?:-(?:scaled|rotated|optimized(?:-\d+)?|\d+x\d+))?\.(?:jpe?g|png|webp)$/i', basename( $filename ) );
		}
	}
	if ( ! $eligible ) {
		$state['quality_file'] = '';
		return $formats;
	}
	$state['quality_file'] = $record['key'];
	if ( $record['settings']['webp_enabled'] && $record['mime'] === $mime_type
		&& wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		$formats[ $mime_type ] = 'image/webp';
	}
	return $formats;
}
add_filter( 'image_editor_output_format', 'corsivo_media_output_format', 20, 3 );

function corsivo_media_image_quality( $quality, $mime_type, $size = array() ) {
	$state  = &corsivo_media_upload_state();
	$record = $state['attachments'][ $state['active'] ] ?? null;
	if ( $record && $record['settings']['optimization_enabled'] && $record['key'] === $state['quality_file']
		&& in_array( $mime_type, array( 'image/jpeg', 'image/webp' ), true ) ) {
		return $record['settings']['image_quality'];
	}
	return $quality;
}
add_filter( 'wp_editor_set_quality', 'corsivo_media_image_quality', 20, 3 );

/**
 * Core already handles full-size copies when resizing or converting. For a
 * small JPEG with quality only, save one new full-size copy from its original.
 */
function corsivo_media_optimize_full( $metadata, $attachment_id, $record ) {
	if ( ! $record['settings']['optimization_enabled'] || empty( $metadata['file'] ) ) {
		return $metadata;
	}
	$maximum = $record['settings']['max_dimension'];
	$resize  = (int) ( $metadata['width'] ?? 0 ) > $maximum || (int) ( $metadata['height'] ?? 0 ) > $maximum;
	// Core versions that intentionally skip PNG scaling still need the requested
	// maximum. Otherwise retain the full copy already produced by WordPress.
	if ( ! $resize && ( ! empty( $metadata['original_image'] ) || 'image/jpeg' !== $record['mime'] ) ) {
		return $metadata;
	}
	$previous_file = get_attached_file( $attachment_id, true );
	if ( empty( $metadata['original_image'] ) && $record['key'] !== corsivo_media_file_key( $previous_file ) ) {
		return $metadata;
	}
	$editor = wp_get_image_editor( $record['file'] );
	if ( is_wp_error( $editor ) ) {
		return $metadata;
	}
	if ( is_wp_error( $editor->set_quality( $record['settings']['image_quality'] ) ) ) {
		return $metadata;
	}
	if ( $resize && is_wp_error( $editor->resize( $maximum, $maximum, false ) ) ) {
		return $metadata;
	}
	if ( is_wp_error( $editor->maybe_exif_rotate() ) ) {
		return $metadata;
	}
	$directory = dirname( $record['file'] );
	$mime      = $record['mime'];
	$extension = 'image/png' === $mime ? 'png' : 'jpg';
	$basename  = pathinfo( $record['file'], PATHINFO_FILENAME ) . '-optimized.' . $extension;
	$target    = $directory . '/' . wp_unique_filename( $directory, $basename );
	// This is a quality/size fallback after core processing. Avoid a second WebP
	// attempt if the first encoding failed, preserving an ordinary image instead.
	$state = &corsivo_media_upload_state();
	$state['attachments'][ $attachment_id ]['settings']['webp_enabled'] = false;
	try {
		$saved = $editor->save( $target, $mime );
	} finally {
		$state['attachments'][ $attachment_id ]['settings']['webp_enabled'] = $record['settings']['webp_enabled'];
	}
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return $metadata;
	}
	$saved_key = corsivo_media_file_key( $saved['path'] );
	// Publish only a valid new file in the source directory, with expected dimensions.
	$size = wp_getimagesize( $saved['path'] );
	if ( '' === $saved_key || $saved_key === $record['key']
		|| dirname( $saved_key ) !== dirname( $record['key'] ) || ! is_array( $size )
		|| ( $resize && ( $size[0] > $maximum || $size[1] > $maximum ) )
		|| ( ! $resize && ( (int) $size[0] !== (int) $metadata['width'] || (int) $size[1] !== (int) $metadata['height'] ) )
		|| $mime !== ( $size['mime'] ?? '' ) ) {
		if ( $saved_key !== $record['key'] && dirname( $saved_key ) === dirname( $record['key'] ) ) {
			wp_delete_file( $saved['path'] );
		}
		return $metadata;
	}
	// A quality rewrite that makes the file larger has no useful result.
	if ( ! $resize && wp_filesize( $saved['path'] ) >= wp_filesize( $record['file'] ) ) {
		wp_delete_file( $saved['path'] );
		return $metadata;
	}
	if ( ! update_attached_file( $attachment_id, $saved['path'] ) ) {
		wp_delete_file( $saved['path'] );
		return $metadata;
	}
	$metadata['file']           = _wp_relative_upload_path( $saved['path'] );
	$metadata['filesize']       = wp_filesize( $saved['path'] );
	$metadata['width']          = $size[0];
	$metadata['height']         = $size[1];
	$metadata['original_image'] = wp_basename( $record['file'] );
	return $metadata;
}

function corsivo_media_finish_processing( $metadata, $attachment_id, $context = 'create' ) {
	$state         = &corsivo_media_upload_state();
	$attachment_id = (int) $attachment_id;
	$record        = $state['attachments'][ $attachment_id ] ?? null;
	if ( ! $record ) {
		return $metadata;
	}
	try {
		if ( 'create' === $context && $state['active'] === $attachment_id && is_array( $metadata ) && ! $state['client_managed'] ) {
			$attached_file = get_attached_file( $attachment_id, true );
			// Core's full-size replacement does not check update_attached_file().
			// If that write failed, publish metadata matching the retained original.
			if ( ! empty( $metadata['file'] ) && $record['key'] === corsivo_media_file_key( $attached_file )
				&& $metadata['file'] !== _wp_relative_upload_path( $attached_file ) ) {
				$size = wp_getimagesize( $attached_file );
				if ( is_array( $size ) ) {
					$metadata['file']     = _wp_relative_upload_path( $attached_file );
					$metadata['width']    = $size[0];
					$metadata['height']   = $size[1];
					$metadata['filesize'] = wp_filesize( $attached_file );
					unset( $metadata['original_image'] );
				}
				return $metadata;
			}
			$metadata = corsivo_media_optimize_full( $metadata, $attachment_id, $record );
		}
		return $metadata;
	} finally {
		unset( $state['attachments'][ $attachment_id ] );
		if ( $state['active'] === $attachment_id ) {
			$state['active']       = 0;
			$state['quality_file'] = '';
		}
	}
}
add_filter( 'wp_generate_attachment_metadata', 'corsivo_media_finish_processing', 20, 3 );

/** Keep the original server upload when this site's image policies are enabled. */
function corsivo_media_client_processing_enabled( $enabled ) {
	$settings = corsivo_media_processing_settings();
	return ( $settings['optimization_enabled'] || $settings['webp_enabled'] ) ? false : $enabled;
}
add_filter( 'wp_client_side_media_processing_enabled', 'corsivo_media_client_processing_enabled' );

/** Respect explicit REST uploads handled by an already-open client-side editor. */
function corsivo_media_before_rest_upload( $response, $handler, $request ) {
	if ( 'POST' === $request->get_method() && '/wp/v2/media' === rtrim( $request->get_route(), '/' ) ) {
		$state                   = &corsivo_media_upload_state();
		$state['client_managed'] = false === $request->get_param( 'generate_sub_sizes' ) || false === $request->get_param( 'convert_format' );
	}
	return $response;
}
add_filter( 'rest_request_before_callbacks', 'corsivo_media_before_rest_upload', 10, 3 );

function corsivo_media_after_rest_upload( $response, $handler, $request ) {
	if ( 'POST' === $request->get_method() && '/wp/v2/media' === rtrim( $request->get_route(), '/' ) ) {
		$state                   = &corsivo_media_upload_state();
		$state['client_managed'] = false;
	}
	return $response;
}
add_filter( 'rest_request_after_callbacks', 'corsivo_media_after_rest_upload', 10, 3 );
