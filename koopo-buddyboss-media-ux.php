<?php
/**
 * Plugin Name: Koopo BuddyBoss Media UX
 * Description: Improves BuddyBoss avatar/cover UX with on-page modals and modern cropping, plus extensible media features.
 * Version: 0.3.0
 * Author: Koopo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KOOPO_BBMU_VERSION', '0.3.0' );
define( 'KOOPO_BBMU_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOOPO_BBMU_URL', plugin_dir_url( __FILE__ ) );

class Koopo_BuddyBoss_Media_UX {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_koopo_bbmu_prepare_avatar_from_media', array( $this, 'ajax_prepare_avatar_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_set_cover_from_media', array( $this, 'ajax_set_cover_from_media' ) );
	}

	public function enqueue_assets() {
		if ( ! function_exists( 'bp_is_active' ) || ! is_user_logged_in() ) {
			return;
		}

		// Safe default: only load on member pages.
		if ( function_exists( 'bp_is_user' ) && ! bp_is_user() && ! ( function_exists( 'bp_is_members_component' ) && bp_is_members_component() ) ) {
			return;
		}

		// Use WordPress-bundled Jcrop (BuddyBoss/BuddyPress relies on this too).
		wp_enqueue_script( 'jcrop' );
		wp_enqueue_style( 'jcrop' );

		wp_enqueue_style( 'koopo-bbmu', KOOPO_BBMU_URL . 'assets/css/koopo-bbmu.css', array(), KOOPO_BBMU_VERSION );
		wp_enqueue_script( 'koopo-bbmu', KOOPO_BBMU_URL . 'assets/js/koopo-bbmu.js', array( 'jquery', 'jcrop' ), KOOPO_BBMU_VERSION, true );

		wp_localize_script(
			'koopo-bbmu',
			'koopoBBMU',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'userId'          => get_current_user_id(),
				'nonceUpload'     => wp_create_nonce( 'bp-uploader' ),
				'nonceAvatarSet'  => wp_create_nonce( 'bp_avatar_cropstore' ),
				'nonceMediaSet'    => wp_create_nonce( 'koopo_bbmu_media_set' ),
				'strings'         => array(
					'titleAvatar'  => __( 'Update Profile Photo', 'koopo' ),
					'titleCover'   => __( 'Update Cover Photo', 'koopo' ),
					'uploading'    => __( 'Uploading…', 'koopo' ),
					'saving'       => __( 'Saving…', 'koopo' ),
					'chooseFile'   => __( 'Choose a photo', 'koopo' ),
					'setPhoto'     => __( 'Save', 'koopo' ),
					'cancel'       => __( 'Cancel', 'koopo' ),
					'errorGeneric' => __( 'Something went wrong. Please try again.', 'koopo' ),
				),
			)
		);
	}

public function ajax_prepare_avatar_from_media() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'koopo' ) ), 401 );
	}

	check_ajax_referer( 'koopo_bbmu_media_set', 'nonce' );

	$media_id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;
	if ( ! $media_id || ! function_exists( 'bp_media_get_specific' ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid media.', 'koopo' ) ), 400 );
	}

	$items = bp_media_get_specific( array( 'media_ids' => $media_id ) );
	if ( empty( $items['media'] ) || empty( $items['media'][0] ) ) {
		wp_send_json_error( array( 'message' => __( 'Media not found.', 'koopo' ) ), 404 );
	}

	$media = $items['media'][0];

	// Only allow setting from your own media (commit 003 scope).
	$current_user_id = get_current_user_id();
	if ( empty( $media->user_id ) || (int) $media->user_id !== (int) $current_user_id ) {
		wp_send_json_error( array( 'message' => __( 'You can only use your own photos.', 'koopo' ) ), 403 );
	}

	$attachment_id = ! empty( $media->attachment_id ) ? absint( $media->attachment_id ) : 0;
	if ( ! $attachment_id ) {
		wp_send_json_error( array( 'message' => __( 'Attachment not found.', 'koopo' ) ), 404 );
	}

	$file_path = get_attached_file( $attachment_id );
	if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
		wp_send_json_error( array( 'message' => __( 'File not found.', 'koopo' ) ), 404 );
	}

	// Prepare a pseudo-$_FILES array.
	$tmp = wp_tempnam( basename( $file_path ) );
	if ( ! $tmp ) {
		wp_send_json_error( array( 'message' => __( 'Could not prepare upload.', 'koopo' ) ), 500 );
	}
	copy( $file_path, $tmp );

	$mime = wp_check_filetype( $file_path );
	$_POST['action']   = 'bp_avatar_upload';
	$_POST['item_id']  = $current_user_id;
	$_POST['object']   = 'user';
	$_POST['item_type']= '';

	$file = array(
		'name'     => basename( $file_path ),
		'type'     => ! empty( $mime['type'] ) ? $mime['type'] : 'image/jpeg',
		'tmp_name' => $tmp,
		'error'    => 0,
		'size'     => filesize( $tmp ),
	);

	$avatar = function_exists( 'bp_attachments_get_attachment' ) ? bp_attachments_get_attachment( 'avatar' ) : false;
	if ( ! $avatar || ! is_object( $avatar ) || ! method_exists( $avatar, 'upload' ) ) {
		@unlink( $tmp );
		wp_send_json_error( array( 'message' => __( 'Avatar handler unavailable.', 'koopo' ) ), 500 );
	}

	$uploaded = $avatar->upload( $file );
	@unlink( $tmp );

	if ( empty( $uploaded ) || ! empty( $uploaded['error'] ) ) {
		$msg = is_string( $uploaded ) ? $uploaded : __( 'Upload failed.', 'koopo' );
		wp_send_json_error( array( 'message' => $msg ) , 400 );
	}

	$img = @getimagesize( $uploaded['file'] );
	$w = ! empty( $img[0] ) ? (int) $img[0] : 0;
	$h = ! empty( $img[1] ) ? (int) $img[1] : 0;

	wp_send_json_success(
		array(
			'url'    => $uploaded['url'],
			'width'  => $w,
			'height' => $h,
		)
	);
}

public function ajax_set_cover_from_media() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'koopo' ) ), 401 );
	}

	check_ajax_referer( 'koopo_bbmu_media_set', 'nonce' );

	$media_id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;
	if ( ! $media_id || ! function_exists( 'bp_media_get_specific' ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid media.', 'koopo' ) ), 400 );
	}

	$items = bp_media_get_specific( array( 'media_ids' => $media_id ) );
	if ( empty( $items['media'] ) || empty( $items['media'][0] ) ) {
		wp_send_json_error( array( 'message' => __( 'Media not found.', 'koopo' ) ), 404 );
	}

	$media = $items['media'][0];

	$current_user_id = get_current_user_id();
	if ( empty( $media->user_id ) || (int) $media->user_id !== (int) $current_user_id ) {
		wp_send_json_error( array( 'message' => __( 'You can only use your own photos.', 'koopo' ) ), 403 );
	}

	$attachment_id = ! empty( $media->attachment_id ) ? absint( $media->attachment_id ) : 0;
	if ( ! $attachment_id ) {
		wp_send_json_error( array( 'message' => __( 'Attachment not found.', 'koopo' ) ), 404 );
	}

	$file_path = get_attached_file( $attachment_id );
	if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
		wp_send_json_error( array( 'message' => __( 'File not found.', 'koopo' ) ), 404 );
	}

	$tmp = wp_tempnam( basename( $file_path ) );
	if ( ! $tmp ) {
		wp_send_json_error( array( 'message' => __( 'Could not prepare upload.', 'koopo' ) ), 500 );
	}
	copy( $file_path, $tmp );

	$mime = wp_check_filetype( $file_path );
	$_POST['action']    = 'bp_cover_image_upload';
	$_POST['item_id']   = $current_user_id;
	$_POST['object']    = 'user';
	$_POST['item_type'] = '';

	$file = array(
		'name'     => basename( $file_path ),
		'type'     => ! empty( $mime['type'] ) ? $mime['type'] : 'image/jpeg',
		'tmp_name' => $tmp,
		'error'    => 0,
		'size'     => filesize( $tmp ),
	);

	$cover = function_exists( 'bp_attachments_get_attachment' ) ? bp_attachments_get_attachment( 'cover_image' ) : false;
	if ( ! $cover || ! is_object( $cover ) || ! method_exists( $cover, 'upload' ) ) {
		@unlink( $tmp );
		wp_send_json_error( array( 'message' => __( 'Cover handler unavailable.', 'koopo' ) ), 500 );
	}

	$uploaded = $cover->upload( $file );
	@unlink( $tmp );

	if ( empty( $uploaded ) || ! empty( $uploaded['error'] ) ) {
		$msg = is_string( $uploaded ) ? $uploaded : __( 'Upload failed.', 'koopo' );
		wp_send_json_error( array( 'message' => $msg ) , 400 );
	}

	wp_send_json_success(
		array(
			'url' => $uploaded['url'],
		)
	);
}

}

new Koopo_BuddyBoss_Media_UX();
