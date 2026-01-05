<?php
/**
 * Plugin Name: Koopo BuddyBoss Media UX
 * Description: Improves BuddyBoss avatar/cover UX with on-page modals and modern cropping, plus extensible media features.
 * Version: 0.3.4
 * Author: Koopo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KOOPO_BBMU_VERSION', '0.3.4' );
define( 'KOOPO_BBMU_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOOPO_BBMU_URL', plugin_dir_url( __FILE__ ) );

class Koopo_BuddyBoss_Media_UX {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_koopo_bbmu_prepare_avatar_from_media', array( $this, 'ajax_prepare_avatar_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_set_cover_from_media', array( $this, 'ajax_set_cover_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_list_user_media', array( $this, 'ajax_list_user_media' ) );
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
		if ( empty( $items['medias'] ) || empty( $items['medias'][0] ) ) {
			wp_send_json_error( array( 'message' => __( 'Media not found.', 'koopo' ) ), 404 );
		}

		$media = $items['medias'][0];

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
		$_POST['action']    = 'bp_avatar_upload';
		$_POST['item_id']   = $current_user_id;
		$_POST['object']    = 'user';
		$_POST['item_type'] = '';
		$_POST['bp_params'] = array(
			'object'    => 'user',
			'item_id'   => $current_user_id,
			'item_type' => '',
		);
		$_POST['_wpnonce']  = wp_create_nonce( 'bp-uploader' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$file = array(
			'name'     => basename( $file_path ),
			'type'     => ! empty( $mime['type'] ) ? $mime['type'] : 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);

		// Route through BuddyBoss/BuddyPress core AJAX handler to ensure all globals and filters are applied.
		if ( ! function_exists( 'bp_avatar_ajax_upload' ) ) {
			@unlink( $tmp );
			wp_send_json_error( array( 'message' => __( 'Avatar handler unavailable.', 'koopo' ) ), 500 );
		}

		$_FILES = array( 'file' => $file );

		// This function will wp_send_json_* and exit.
		bp_avatar_ajax_upload();

		// Fallback (should not be reached).
		wp_send_json_error( array( 'message' => __( 'Avatar upload failed.', 'koopo' ) ), 500 );
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
		if ( empty( $items['medias'] ) || empty( $items['medias'][0] ) ) {
			wp_send_json_error( array( 'message' => __( 'Media not found.', 'koopo' ) ), 404 );
		}

		$media = $items['medias'][0];

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
		$_POST['bp_params'] = array(
			'object'    => 'user',
			'item_id'   => $current_user_id,
			'item_type' => '',
		);
		$_POST['_wpnonce']  = wp_create_nonce( 'bp-uploader' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$file = array(
			'name'     => basename( $file_path ),
			'type'     => ! empty( $mime['type'] ) ? $mime['type'] : 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);

		// Route through BuddyBoss/BuddyPress core AJAX handler.
		if ( ! function_exists( 'bp_attachments_cover_image_ajax_upload' ) ) {
			@unlink( $tmp );
			wp_send_json_error( array( 'message' => __( 'Cover handler unavailable.', 'koopo' ) ), 500 );
		}

		$_FILES = array( 'file' => $file );

		// This function will wp_send_json_* and exit.
		bp_attachments_cover_image_ajax_upload();

		// Fallback (should not be reached).
		wp_send_json_error( array( 'message' => __( 'Cover upload failed.', 'koopo' ) ), 500 );
	}

	public function ajax_list_user_media() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'koopo' ) ), 401 );
		}

		check_ajax_referer( 'koopo_bbmu_media_set', 'nonce' );

		if ( ! function_exists( 'bp_media_get' ) ) {
			wp_send_json_error( array( 'message' => __( 'Media component unavailable.', 'koopo' ) ), 500 );
		}

		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? min( 50, max( 1, absint( $_POST['per_page'] ) ) ) : 24;

		$current_user_id = get_current_user_id();

		$result = bp_media_get(
			array(
				'user_id'          => $current_user_id,
				'page'             => $page,
				'per_page'         => $per_page,
				'video'            => false,
				'scope'            => 'personal',
				'privacy'          => false,
				'moderation_query' => false,
			)
		);

		$items = array();

		if ( ! empty( $result['medias'] ) ) {
			foreach ( $result['medias'] as $media ) {
				$attachment_id = ! empty( $media->attachment_id ) ? absint( $media->attachment_id ) : 0;
				if ( ! $attachment_id ) {
					continue;
				}

				if ( function_exists( 'bp_media_get_preview_image_url' ) && ! empty( $media->id ) ) {
					$thumb = bp_media_get_preview_image_url( $media->id, $attachment_id, 'bb-media-activity-image' );
					$full  = bp_media_get_preview_image_url( $media->id, $attachment_id, 'bb-media-photos-popup-image' );
				} else {
					$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
					$full  = wp_get_attachment_image_url( $attachment_id, 'large' );
				}
				if ( ! $thumb ) {
					$thumb = wp_get_attachment_url( $attachment_id );
				}
				if ( ! $full ) {
					$full = wp_get_attachment_url( $attachment_id );
				}

				$items[] = array(
					'media_id'      => ! empty( $media->id ) ? (int) $media->id : 0,
					'attachment_id' => $attachment_id,
					'thumb'         => $thumb,
					'full'          => $full,
				);
			}
		}

		wp_send_json_success(
			array(
				'items'      => $items,
				'page'       => $page,
				'per_page'   => $per_page,
				'has_more'   => ! empty( $result['has_more_items'] ),
				'total'      => ! empty( $result['total'] ) ? (int) $result['total'] : 0,
			)
		);
	}

}

new Koopo_BuddyBoss_Media_UX();
