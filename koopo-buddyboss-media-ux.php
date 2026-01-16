<?php
/**
 * Plugin Name: Koopo BuddyBoss Media UX
 * Description: Improves BuddyBoss avatar/cover UX with on-page modals and modern cropping, plus extensible media features.
 * Version: 0.5.0
 * Author: Koopo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KOOPO_BBMU_VERSION', '0.5.0' );
define( 'KOOPO_BBMU_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOOPO_BBMU_URL', plugin_dir_url( __FILE__ ) );

class Koopo_BuddyBoss_Media_UX {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_offload_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_offload_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_koopo_bbmu_rml_backfill', array( $this, 'handle_rml_backfill_request' ) );
		add_action( 'admin_post_koopo_bbmu_webp_backfill', array( $this, 'handle_webp_backfill_request' ) );
		add_action( 'wp_ajax_koopo_bbmu_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_koopo_bbmu_webp_backfill_step', array( $this, 'ajax_webp_backfill_step' ) );
		add_filter( 'manage_upload_columns', array( $this, 'add_media_library_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_library_column' ), 10, 2 );
		add_action( 'add_meta_boxes_attachment', array( $this, 'register_attachment_metabox' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_modal_field' ), 10, 2 );
		add_filter( 'RML/Active', array( $this, 'filter_rml_active' ), 10, 1 );
		add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_threshold' ) );
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_intermediate_sizes' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_editor_quality' ), 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'optimize_attachment_metadata' ), 20, 2 );
		add_action( 'updated_post_meta', array( $this, 'handle_attachment_metadata_updated' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'handle_attachment_metadata_updated' ), 10, 4 );
		add_filter( 'the_content', array( $this, 'filter_content_webp_urls' ), 20 );
		add_action( 'koopo_bbmu_optimize_attachment', array( $this, 'run_attachment_optimization' ), 10, 1 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_koopo_bbmu_prepare_avatar_from_media', array( $this, 'ajax_prepare_avatar_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_set_cover_from_media', array( $this, 'ajax_set_cover_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_list_user_media', array( $this, 'ajax_list_user_media' ) );

		add_action( 'bb_media_upload', array( $this, 'handle_media_upload' ) );
		add_action( 'add_attachment', array( $this, 'handle_attachment_created' ) );
		add_action( 'added_post_meta', array( $this, 'handle_attachment_meta_linked' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'handle_attachment_meta_linked' ), 10, 4 );
		add_action( 'bp_media_add', array( $this, 'handle_bp_media_add' ), 10, 1 );
		add_action( 'koopo_stories_story_created', array( $this, 'handle_story_created' ), 10, 3 );
		add_action( 'koopo_bbmu_offload_uploaded', array( $this, 'handle_offload_uploaded' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_image_src' ), 10, 4 );
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

				$thumb = $this->maybe_offload_url( $attachment_id, 'bb-media-activity-image', $thumb );
				$full  = $this->maybe_offload_url( $attachment_id, 'bb-media-photos-popup-image', $full );
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

	public function register_offload_menu() {
		add_options_page(
			__( 'Koopo Media Offload', 'koopo' ),
			__( 'Koopo Media Offload', 'koopo' ),
			'manage_options',
			'koopo-bbmu-offload',
			array( $this, 'render_offload_settings_page' )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_koopo-bbmu-offload' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'koopo-bbmu-admin', KOOPO_BBMU_URL . 'assets/css/koopo-bbmu-admin.css', array(), KOOPO_BBMU_VERSION );
		wp_enqueue_script( 'koopo-bbmu-admin', KOOPO_BBMU_URL . 'assets/js/koopo-bbmu-admin.js', array( 'jquery' ), KOOPO_BBMU_VERSION, true );
		wp_localize_script(
			'koopo-bbmu-admin',
			'koopoBBMUAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'saveNonce' => wp_create_nonce( 'koopo_bbmu_save_settings' ),
				'webpNonce' => wp_create_nonce( 'koopo_bbmu_webp_backfill' ),
				'messages'  => array(
					'saving' => __( 'Saving…', 'koopo' ),
					'saved'  => __( 'Settings saved.', 'koopo' ),
					'error'  => __( 'Settings could not be saved.', 'koopo' ),
					'webpStarting' => __( 'Starting WebP backfill…', 'koopo' ),
					'webpRunning'  => __( 'Processing WebP backfill…', 'koopo' ),
					'webpDone'     => __( 'WebP backfill complete.', 'koopo' ),
				),
			)
		);
	}

	public function register_offload_settings() {
		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_scope',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_scope' ),
				'default'           => 'buddyboss',
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_provider' ),
				'default'           => 'bunny',
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_bunny_storage_zone',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_bunny_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_rml_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_rml_user_folders_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_rml_user_folder_parent',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_rml_user_folder_template',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Users/{user_login}',
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_rml_folder_map',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_rml_folder_map' ),
				'default'           => $this->get_default_rml_folder_map(),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_rml_folder_create',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_rml_folder_create' ),
				'default'           => array(),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_delete_policy',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_delete_policy' ),
				'default'           => $this->get_default_delete_policy(),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_delete_extensions',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_delete_extensions' ),
				'default'           => $this->get_default_delete_extensions(),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_max_dim',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_opt_max_dim' ),
				'default'           => 2048,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_sizes',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_opt_sizes' ),
				'default'           => array(
					'thumbnail' => 1,
					'large'     => 1,
				),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_strip_exif',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => true,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_jpeg_quality',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quality' ),
				'default'           => 82,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_webp_quality',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quality' ),
				'default'           => 80,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_generate_webp',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_generate_avif',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_keep_original',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => true,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_opt_background',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_media_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_media_types' ),
				'default'           => $this->get_default_media_types(),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => $this->get_default_post_types(),
			)
		);

		register_setting(
			'koopo_bbmu_offload',
			'koopo_bbmu_offload_folders',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_folder_templates' ),
				'default'           => $this->get_default_folder_templates(),
			)
		);

		add_settings_section(
			'koopo_bbmu_offload_main',
			__( 'Offload Settings', 'koopo' ),
			array( $this, 'render_offload_section' ),
			'koopo-bbmu-offload'
		);

		add_settings_section(
			'koopo_bbmu_offload_provider',
			__( 'Provider', 'koopo' ),
			array( $this, 'render_provider_section' ),
			'koopo-bbmu-offload'
		);

		add_settings_section(
			'koopo_bbmu_offload_library',
			__( 'Media Library (Real Media Library)', 'koopo' ),
			array( $this, 'render_rml_section' ),
			'koopo-bbmu-offload'
		);

		add_settings_section(
			'koopo_bbmu_offload_delete',
			__( 'Delete Local Copies', 'koopo' ),
			array( $this, 'render_delete_section' ),
			'koopo-bbmu-offload'
		);

		add_settings_section(
			'koopo_bbmu_offload_optimization',
			__( 'Optimization', 'koopo' ),
			array( $this, 'render_optimization_section' ),
			'koopo-bbmu-offload'
		);

		add_settings_field(
			'koopo_bbmu_offload_enabled',
			__( 'Enable Offload URLs', 'koopo' ),
			array( $this, 'render_offload_enabled_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_main'
		);

		add_settings_field(
			'koopo_bbmu_offload_base_url',
			__( 'Base URL', 'koopo' ),
			array( $this, 'render_offload_base_url_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_main'
		);

		add_settings_field(
			'koopo_bbmu_offload_scope',
			__( 'Apply To', 'koopo' ),
			array( $this, 'render_offload_scope_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_main'
		);

		add_settings_field(
			'koopo_bbmu_offload_media_types',
			__( 'Media Types', 'koopo' ),
			array( $this, 'render_offload_media_types_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_main'
		);

		add_settings_field(
			'koopo_bbmu_offload_post_types',
			__( 'Post Types', 'koopo' ),
			array( $this, 'render_offload_post_types_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_main'
		);

		add_settings_field(
			'koopo_bbmu_offload_folders',
			__( 'Folder Templates', 'koopo' ),
			array( $this, 'render_offload_folders_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_main'
		);

		add_settings_field(
			'koopo_bbmu_offload_provider',
			__( 'Provider', 'koopo' ),
			array( $this, 'render_offload_provider_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_provider'
		);

		add_settings_field(
			'koopo_bbmu_bunny_storage_zone',
			__( 'Bunny Storage Zone', 'koopo' ),
			array( $this, 'render_bunny_storage_zone_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_provider'
		);

		add_settings_field(
			'koopo_bbmu_bunny_api_key',
			__( 'Bunny API Key', 'koopo' ),
			array( $this, 'render_bunny_api_key_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_provider'
		);

		add_settings_field(
			'koopo_bbmu_rml_enabled',
			__( 'Enable RML Integration', 'koopo' ),
			array( $this, 'render_rml_enabled_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_library'
		);

		add_settings_field(
			'koopo_bbmu_rml_folder_map',
			__( 'RML Folder Mapping', 'koopo' ),
			array( $this, 'render_rml_folder_map_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_library'
		);

		add_settings_field(
			'koopo_bbmu_offload_delete_policy',
			__( 'Delete Policy', 'koopo' ),
			array( $this, 'render_delete_policy_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_delete'
		);

		add_settings_field(
			'koopo_bbmu_offload_delete_extensions',
			__( 'Delete Extensions', 'koopo' ),
			array( $this, 'render_delete_extensions_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_delete'
		);

		add_settings_field(
			'koopo_bbmu_opt_max_dim',
			__( 'Max Image Dimension', 'koopo' ),
			array( $this, 'render_opt_max_dim_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_sizes',
			__( 'Allowed Image Sizes', 'koopo' ),
			array( $this, 'render_opt_sizes_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_strip_exif',
			__( 'Strip EXIF Metadata', 'koopo' ),
			array( $this, 'render_opt_strip_exif_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_jpeg_quality',
			__( 'JPEG Quality', 'koopo' ),
			array( $this, 'render_opt_jpeg_quality_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_webp_quality',
			__( 'WebP Quality', 'koopo' ),
			array( $this, 'render_opt_webp_quality_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_generate_webp',
			__( 'Generate WebP', 'koopo' ),
			array( $this, 'render_opt_generate_webp_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_generate_avif',
			__( 'Generate AVIF', 'koopo' ),
			array( $this, 'render_opt_generate_avif_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_keep_original',
			__( 'Keep Original Image', 'koopo' ),
			array( $this, 'render_opt_keep_original_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_background',
			__( 'Background Optimization', 'koopo' ),
			array( $this, 'render_opt_background_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);

		add_settings_field(
			'koopo_bbmu_opt_webp_backfill',
			__( 'WebP Backfill', 'koopo' ),
			array( $this, 'render_opt_webp_backfill_field' ),
			'koopo-bbmu-offload',
			'koopo_bbmu_offload_optimization'
		);
	}

	public function render_offload_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="koopo-admin">
			<div class="koopo-admin__header">
				<div class="koopo-admin__title">
					<?php esc_html_e( 'Koopo BuddyBoss Media UX', 'koopo' ); ?>
					<span class="koopo-admin__version"><?php echo esc_html( 'v' . KOOPO_BBMU_VERSION ); ?></span>
				</div>
				<div class="koopo-admin__actions">
					<a class="button button-primary koopo-admin__button" href="#"><?php esc_html_e( 'Documentation', 'koopo' ); ?></a>
				</div>
			</div>
			<div class="koopo-admin__layout">
				<aside class="koopo-admin__sidebar">
					<div class="koopo-admin__nav">
						<a class="koopo-admin__nav-item is-active" href="#" data-section="section-0"><?php esc_html_e( 'Settings', 'koopo' ); ?></a>
						<a class="koopo-admin__nav-item" href="#" data-section="section-1"><?php esc_html_e( 'Provider', 'koopo' ); ?></a>
						<a class="koopo-admin__nav-item" href="#" data-section="section-2"><?php esc_html_e( 'Media Library', 'koopo' ); ?></a>
						<a class="koopo-admin__nav-item" href="#" data-section="section-3"><?php esc_html_e( 'Delete Local', 'koopo' ); ?></a>
						<a class="koopo-admin__nav-item" href="#" data-section="section-4"><?php esc_html_e( 'Optimization', 'koopo' ); ?></a>
					</div>
				</aside>
				<main class="koopo-admin__main">
					<form method="post" action="options.php" class="koopo-admin__form">
						<?php
						settings_fields( 'koopo_bbmu_offload' );
						settings_errors( 'koopo_bbmu_offload' );
						echo '<input type="hidden" name="page" value="koopo-bbmu-offload" />';
						echo '<div class="koopo-admin__notice-area" aria-live="polite"></div>';
						do_settings_sections( 'koopo-bbmu-offload' );
						echo '<div class="koopo-admin__submit">';
						submit_button();
						echo '<span class="koopo-admin__save-status" aria-live="polite"></span>';
						echo '</div>';
						?>
					</form>
				</main>
			</div>
		</div>
		<?php
	}

	public function render_offload_section() {
		echo '<p>' . esc_html__( 'Configure external media URLs for CDN or object storage. The plugin does not upload files; it only rewrites URLs and exposes hooks for offload adapters.', 'koopo' ) . '</p>';
		echo '<p>' . esc_html__( 'Use folder templates to organize media by post type and media type. Available tokens: {post_type}, {media_type}, {year}, {month}, {day}, {filename}.', 'koopo' ) . '</p>';
	}

	public function render_provider_section() {
		echo '<p>' . esc_html__( 'Select the offload provider and store its credentials. Upload handling is performed by an adapter hooked into koopo_bbmu_offload_attachment.', 'koopo' ) . '</p>';
	}

	public function render_rml_section() {
		echo '<p>' . esc_html__( 'Map uploaded media to Real Media Library folders based on post type and media type. Use the create field to make new folders on save.', 'koopo' ) . '</p>';
	}

	public function handle_webp_backfill_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized.', 'koopo' ) );
		}

		check_admin_referer( 'koopo_bbmu_webp_backfill' );

		$paged = isset( $_REQUEST['paged'] ) ? absint( $_REQUEST['paged'] ) : 1;
		$per_page = 25;

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'fields'         => 'ids',
				'post_mime_type' => 'image',
			)
		);

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $attachment_id ) {
				$metadata = wp_get_attachment_metadata( (int) $attachment_id );
				if ( $this->attachment_has_webp( (int) $attachment_id ) ) {
					continue;
				}
				$this->run_attachment_optimization( (int) $attachment_id, $metadata );
			}
		}

		$total  = (int) $query->found_posts;
		$done   = min( $paged * $per_page, $total );
		$status = ( $done >= $total || empty( $query->posts ) ) ? 'complete' : 'progress';

		$redirect = add_query_arg(
			array(
				'page'                   => 'koopo-bbmu-offload',
				'koopo_bbmu_webp_status' => $status,
				'koopo_bbmu_webp_page'   => $paged,
				'koopo_bbmu_webp_total'  => $total,
				'koopo_bbmu_webp_done'   => $done,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private function attachment_has_webp( $attachment_id ) {
		$formats = $this->get_alt_formats_meta( $attachment_id );
		return ! empty( $formats['webp'] );
	}

	public function ajax_webp_backfill_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'koopo' ) ), 403 );
		}

		check_ajax_referer( 'koopo_bbmu_webp_backfill', 'nonce' );

		$paged = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$per_page = 10;

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'fields'         => 'ids',
				'post_mime_type' => 'image',
			)
		);

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $attachment_id ) {
				$metadata = wp_get_attachment_metadata( (int) $attachment_id );
				if ( $this->attachment_has_webp( (int) $attachment_id ) ) {
					continue;
				}
				$this->run_attachment_optimization( (int) $attachment_id, $metadata );
			}
		}

		$total  = (int) $query->found_posts;
		$done   = min( $paged * $per_page, $total );
		$status = ( $done >= $total || empty( $query->posts ) ) ? 'complete' : 'progress';

		wp_send_json_success(
			array(
				'status' => $status,
				'page'   => $paged,
				'total'  => $total,
				'done'   => $done,
				'next'   => ( 'progress' === $status ) ? ( $paged + 1 ) : $paged,
			)
		);
	}

	public function ajax_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'koopo' ) ), 403 );
		}

		check_ajax_referer( 'koopo_bbmu_save_settings', 'nonce' );

		$data = wp_unslash( $_POST );
		unset( $data['action'], $data['nonce'] );

		$updates = array(
			'koopo_bbmu_offload_enabled' => $this->sanitize_checkbox( $data['koopo_bbmu_offload_enabled'] ?? 0 ),
			'koopo_bbmu_offload_base_url' => esc_url_raw( $data['koopo_bbmu_offload_base_url'] ?? '' ),
			'koopo_bbmu_offload_scope' => $this->sanitize_scope( $data['koopo_bbmu_offload_scope'] ?? '' ),
			'koopo_bbmu_offload_provider' => $this->sanitize_provider( $data['koopo_bbmu_offload_provider'] ?? '' ),
			'koopo_bbmu_bunny_storage_zone' => sanitize_text_field( $data['koopo_bbmu_bunny_storage_zone'] ?? '' ),
			'koopo_bbmu_bunny_api_key' => sanitize_text_field( $data['koopo_bbmu_bunny_api_key'] ?? '' ),
			'koopo_bbmu_rml_enabled' => $this->sanitize_checkbox( $data['koopo_bbmu_rml_enabled'] ?? 0 ),
			'koopo_bbmu_rml_user_folders_enabled' => $this->sanitize_checkbox( $data['koopo_bbmu_rml_user_folders_enabled'] ?? 0 ),
			'koopo_bbmu_rml_user_folder_parent' => absint( $data['koopo_bbmu_rml_user_folder_parent'] ?? 0 ),
			'koopo_bbmu_rml_user_folder_template' => sanitize_text_field( $data['koopo_bbmu_rml_user_folder_template'] ?? '' ),
			'koopo_bbmu_rml_folder_map' => $this->sanitize_rml_folder_map( $data['koopo_bbmu_rml_folder_map'] ?? array() ),
			'koopo_bbmu_rml_folder_create' => $this->sanitize_rml_folder_create( $data['koopo_bbmu_rml_folder_create'] ?? array() ),
			'koopo_bbmu_offload_delete_policy' => $this->sanitize_delete_policy( $data['koopo_bbmu_offload_delete_policy'] ?? array() ),
			'koopo_bbmu_offload_delete_extensions' => $this->sanitize_delete_extensions( $data['koopo_bbmu_offload_delete_extensions'] ?? array() ),
			'koopo_bbmu_opt_max_dim' => $this->sanitize_opt_max_dim( $data['koopo_bbmu_opt_max_dim'] ?? 0 ),
			'koopo_bbmu_opt_sizes' => $this->sanitize_opt_sizes( $data['koopo_bbmu_opt_sizes'] ?? array() ),
			'koopo_bbmu_opt_strip_exif' => $this->sanitize_checkbox( $data['koopo_bbmu_opt_strip_exif'] ?? 0 ),
			'koopo_bbmu_opt_jpeg_quality' => $this->sanitize_quality( $data['koopo_bbmu_opt_jpeg_quality'] ?? 82 ),
			'koopo_bbmu_opt_webp_quality' => $this->sanitize_quality( $data['koopo_bbmu_opt_webp_quality'] ?? 80 ),
			'koopo_bbmu_opt_generate_webp' => $this->sanitize_checkbox( $data['koopo_bbmu_opt_generate_webp'] ?? 0 ),
			'koopo_bbmu_opt_generate_avif' => $this->sanitize_checkbox( $data['koopo_bbmu_opt_generate_avif'] ?? 0 ),
			'koopo_bbmu_opt_keep_original' => $this->sanitize_checkbox( $data['koopo_bbmu_opt_keep_original'] ?? 0 ),
			'koopo_bbmu_opt_background' => $this->sanitize_checkbox( $data['koopo_bbmu_opt_background'] ?? 0 ),
			'koopo_bbmu_offload_media_types' => $this->sanitize_media_types( $data['koopo_bbmu_offload_media_types'] ?? array() ),
			'koopo_bbmu_offload_post_types' => $this->sanitize_post_types( $data['koopo_bbmu_offload_post_types'] ?? array() ),
			'koopo_bbmu_offload_folders' => $this->sanitize_folder_templates( $data['koopo_bbmu_offload_folders'] ?? array() ),
		);

		foreach ( $updates as $option => $value ) {
			update_option( $option, $value );
		}

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'koopo' ) ) );
	}

	public function add_media_library_columns( $columns ) {
		$columns['koopo_bbmu_source'] = __( 'Upload Source', 'koopo' );
		return $columns;
	}

	public function render_media_library_column( $column_name, $attachment_id ) {
		if ( 'koopo_bbmu_source' !== $column_name ) {
			return;
		}

		$data = get_post_meta( $attachment_id, 'koopo_bbmu_upload_source', true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			echo '<span class="description">' . esc_html__( '—', 'koopo' ) . '</span>';
			return;
		}

		$source = isset( $data['source'] ) ? $data['source'] : '';
		$context = isset( $data['context'] ) ? $data['context'] : '';
		$action = isset( $data['action'] ) ? $data['action'] : '';
		$when = isset( $data['timestamp'] ) ? $data['timestamp'] : '';

		$lines = array();
		if ( '' !== $source ) {
			$lines[] = esc_html( $source );
		}
		if ( '' !== $context ) {
			$lines[] = esc_html( $context );
		}
		if ( '' !== $action ) {
			$lines[] = esc_html( $action );
		}
		if ( '' !== $when ) {
			$lines[] = esc_html( $when );
		}

		if ( empty( $lines ) ) {
			echo '<span class="description">' . esc_html__( '—', 'koopo' ) . '</span>';
			return;
		}

		echo '<div class="koopo-media-meta">' . implode( '<br />', $lines ) . '</div>';
	}

	public function register_attachment_metabox() {
		add_meta_box(
			'koopo-bbmu-upload-source',
			__( 'Upload Source', 'koopo' ),
			array( $this, 'render_attachment_metabox' ),
			'attachment',
			'side',
			'default'
		);
	}

	public function render_attachment_metabox( $post ) {
		$data = get_post_meta( $post->ID, 'koopo_bbmu_upload_source', true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			echo '<p class="description">' . esc_html__( 'No upload source data recorded yet.', 'koopo' ) . '</p>';
			return;
		}

		echo $this->format_upload_source_html( $data );
	}

	public function add_attachment_modal_field( $form_fields, $post ) {
		$data = get_post_meta( $post->ID, 'koopo_bbmu_upload_source', true );
		$value = '';
		if ( ! empty( $data ) && is_array( $data ) ) {
			$value = $this->format_upload_source_text( $data );
		}

		$form_fields['koopo_bbmu_upload_source'] = array(
			'label' => __( 'Upload Source', 'koopo' ),
			'input' => 'html',
			'html'  => $value ? '<div class="koopo-media-meta">' . nl2br( esc_html( $value ) ) . '</div>' : '<span class="description">' . esc_html__( 'No upload source data recorded yet.', 'koopo' ) . '</span>',
		);

		return $form_fields;
	}

	private function format_upload_source_text( $data ) {
		$lines = array();
		foreach ( $data as $key => $value ) {
			if ( '' === $value || is_array( $value ) ) {
				continue;
			}
			$lines[] = sprintf( '%s: %s', $key, $value );
		}

		return implode( "\n", $lines );
	}

	private function format_upload_source_html( $data ) {
		$lines = array();
		foreach ( $data as $key => $value ) {
			if ( '' === $value || is_array( $value ) ) {
				continue;
			}
			$lines[] = sprintf( '<strong>%s</strong>: %s', esc_html( $key ), esc_html( $value ) );
		}

		if ( empty( $lines ) ) {
			return '<p class="description">' . esc_html__( 'No upload source data recorded yet.', 'koopo' ) . '</p>';
		}

		return '<div class="koopo-media-meta">' . implode( '<br />', $lines ) . '</div>';
	}

	public function render_delete_section() {
		echo '<p>' . esc_html__( 'If enabled by adapter, local files can be deleted after offload. Deletion is filtered by post type, media type, extension, and mapped folder.', 'koopo' ) . '</p>';
	}

	public function render_optimization_section() {
		echo '<p>' . esc_html__( 'Set global limits for images created on upload. This controls WordPress resizing and the intermediate sizes that are generated.', 'koopo' ) . '</p>';
	}

	public function render_offload_enabled_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_offload_enabled', false );
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_offload_enabled" value="1" <?php checked( $enabled ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Rewrite media URLs to the base URL', 'koopo' ); ?></span>
		</label>
		<?php
	}

	public function render_offload_base_url_field() {
		$value = (string) get_option( 'koopo_bbmu_offload_base_url', '' );
		?>
		<input type="text" class="regular-text" name="koopo_bbmu_offload_base_url" value="<?php echo esc_attr( $value ); ?>" placeholder="https://cdn.example.com" />
		<p class="description"><?php esc_html_e( 'Must map to your uploads directory structure.', 'koopo' ); ?></p>
		<?php
	}

	public function render_offload_scope_field() {
		$value = (string) get_option( 'koopo_bbmu_offload_scope', 'buddyboss' );
		?>
		<select name="koopo_bbmu_offload_scope">
			<option value="buddyboss" <?php selected( $value, 'buddyboss' ); ?>>
				<?php esc_html_e( 'BuddyBoss social media only', 'koopo' ); ?>
			</option>
			<option value="all" <?php selected( $value, 'all' ); ?>>
				<?php esc_html_e( 'All WordPress uploads', 'koopo' ); ?>
			</option>
		</select>
		<?php
	}

	public function render_offload_provider_field() {
		$value = (string) get_option( 'koopo_bbmu_offload_provider', 'bunny' );
		?>
		<select name="koopo_bbmu_offload_provider">
			<option value="bunny" <?php selected( $value, 'bunny' ); ?>><?php esc_html_e( 'Bunny.net', 'koopo' ); ?></option>
			<option value="s3" <?php selected( $value, 's3' ); ?>><?php esc_html_e( 'S3 Compatible', 'koopo' ); ?></option>
			<option value="gdrive" <?php selected( $value, 'gdrive' ); ?>><?php esc_html_e( 'Google Drive', 'koopo' ); ?></option>
			<option value="onedrive" <?php selected( $value, 'onedrive' ); ?>><?php esc_html_e( 'OneDrive', 'koopo' ); ?></option>
		</select>
		<?php
	}

	public function render_bunny_storage_zone_field() {
		$value = (string) get_option( 'koopo_bbmu_bunny_storage_zone', '' );
		?>
		<input type="text" class="regular-text" name="koopo_bbmu_bunny_storage_zone" value="<?php echo esc_attr( $value ); ?>" />
		<p class="description"><?php esc_html_e( 'Storage zone name for Bunny.net uploads.', 'koopo' ); ?></p>
		<?php
	}

	public function render_bunny_api_key_field() {
		$value = (string) get_option( 'koopo_bbmu_bunny_api_key', '' );
		?>
		<input type="password" class="regular-text" name="koopo_bbmu_bunny_api_key" value="<?php echo esc_attr( $value ); ?>" autocomplete="new-password" />
		<p class="description"><?php esc_html_e( 'API key for Bunny.net storage API.', 'koopo' ); ?></p>
		<?php
	}

	public function render_rml_enabled_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_rml_enabled', false );
		$active  = function_exists( 'wp_rml_active' ) && wp_rml_active();
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_rml_enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $active ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Assign attachments to Real Media Library folders', 'koopo' ); ?></span>
		</label>
		<?php if ( ! $active ) : ?>
			<p class="description"><?php esc_html_e( 'Real Media Library is not active for the current user.', 'koopo' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_rml_folder_map_field() {
		if ( ! function_exists( 'wp_rml_active' ) || ! wp_rml_active() || ! function_exists( 'wp_rml_selector' ) ) {
			echo '<p>' . esc_html__( 'Activate Real Media Library to manage folder mappings.', 'koopo' ) . '</p>';
			return;
		}

		$user_enabled = (bool) get_option( 'koopo_bbmu_rml_user_folders_enabled', false );
		$user_parent  = (int) get_option( 'koopo_bbmu_rml_user_folder_parent', 0 );
		$user_template = (string) get_option( 'koopo_bbmu_rml_user_folder_template', 'Users/{user_login}' );

		echo '<div class="koopo-admin__card">';
		echo '<h3>' . esc_html__( 'Per-User Folder', 'koopo' ) . '</h3>';
		echo '<label class="koopo-toggle koopo-toggle--stacked">';
		echo '<input type="checkbox" name="koopo_bbmu_rml_user_folders_enabled" value="1" ' . checked( $user_enabled, true, false ) . ' />';
		echo '<span class="koopo-toggle__track" data-on="ON" data-off="OFF"><span class="koopo-toggle__thumb"></span></span>';
		echo '<span class="koopo-toggle__label">' . esc_html__( 'Create a folder per user and add all their uploads to it', 'koopo' ) . '</span>';
		echo '</label>';
		echo '<p><label>' . esc_html__( 'Parent folder', 'koopo' ) . '</label><br />' . wp_rml_selector(
			array(
				'selected' => $user_parent ? $user_parent : _wp_rml_root(),
				'name'     => 'koopo_bbmu_rml_user_folder_parent',
				'nullable' => true,
			)
		) . '</p>';
		echo '<p><label>' . esc_html__( 'Folder name template', 'koopo' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="koopo_bbmu_rml_user_folder_template" value="' . esc_attr( $user_template ) . '" />';
		echo '<span class="description">' . esc_html__( 'Tokens: {user_id}, {user_login}, {user_nicename}, {display_name}', 'koopo' ) . '</span></p>';
		echo '</div>';

		$map       = $this->get_rml_folder_map();
		$creates   = $this->get_rml_folder_create();
		$contexts  = $this->get_post_type_labels();
		$types     = $this->get_media_type_labels();

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'Context', 'koopo' ) . '</th><th>' . esc_html__( 'Media Type', 'koopo' ) . '</th><th>' . esc_html__( 'Folder', 'koopo' ) . '</th><th>' . esc_html__( 'Create Folder Path', 'koopo' ) . '</th></tr></thead>';
		echo '<tbody>';
		foreach ( $contexts as $context_key => $context_label ) {
			foreach ( $types as $type_key => $type_label ) {
				$selected = isset( $map[ $context_key ][ $type_key ] ) ? (int) $map[ $context_key ][ $type_key ] : '';
				$create   = isset( $creates[ $context_key ][ $type_key ] ) ? (string) $creates[ $context_key ][ $type_key ] : '';
				echo '<tr>';
				echo '<td>' . esc_html( $context_label ) . '</td>';
				echo '<td>' . esc_html( $type_label ) . '</td>';
				echo '<td>' . wp_rml_selector(
					array(
						'selected' => $selected,
						'name'     => 'koopo_bbmu_rml_folder_map[' . esc_attr( $context_key ) . '][' . esc_attr( $type_key ) . ']',
						'nullable' => true,
					)
				) . '</td>';
				echo '<td><input type="text" class="regular-text" name="koopo_bbmu_rml_folder_create[' . esc_attr( $context_key ) . '][' . esc_attr( $type_key ) . ']" value="' . esc_attr( $create ) . '" placeholder="social/photos" /></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		$backfill_status = isset( $_GET['koopo_bbmu_backfill_status'] ) ? sanitize_text_field( wp_unslash( $_GET['koopo_bbmu_backfill_status'] ) ) : '';
		$backfill_page   = isset( $_GET['koopo_bbmu_backfill_page'] ) ? absint( $_GET['koopo_bbmu_backfill_page'] ) : 0;
		$backfill_total  = isset( $_GET['koopo_bbmu_backfill_total'] ) ? absint( $_GET['koopo_bbmu_backfill_total'] ) : 0;
		$backfill_done   = isset( $_GET['koopo_bbmu_backfill_done'] ) ? absint( $_GET['koopo_bbmu_backfill_done'] ) : 0;
		$next_page       = ( 'progress' === $backfill_status && $backfill_page ) ? ( $backfill_page + 1 ) : 1;

		if ( $backfill_status ) {
			$message = sprintf(
				/* translators: 1: processed count, 2: total count */
				__( 'Backfill progress: %1$d / %2$d attachments processed.', 'koopo' ),
				$backfill_done,
				$backfill_total
			);
			if ( 'complete' === $backfill_status ) {
				$message = __( 'Backfill complete.', 'koopo' );
			}
			echo '<div class="koopo-admin__notice">' . esc_html( $message ) . '</div>';
		}

		echo '<div class="koopo-admin__card">';
		echo '<h3>' . esc_html__( 'Backfill Existing Media', 'koopo' ) . '</h3>';
		echo '<p>' . esc_html__( 'Run a background-safe batch process to assign existing attachments to the mapped RML folders and optional per-user folders.', 'koopo' ) . '</p>';
		$backfill_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'koopo_bbmu_rml_backfill',
					'paged'  => $next_page,
				),
				admin_url( 'admin-post.php' )
			),
			'koopo_bbmu_rml_backfill'
		);
		echo '<a class="button button-secondary" href="' . esc_url( $backfill_url ) . '">' . esc_html__( 'Run Backfill', 'koopo' ) . '</a>';
		echo '</div>';
	}

	public function render_delete_policy_field() {
		$policy   = $this->get_delete_policy();
		$contexts = $this->get_post_type_labels();
		$types    = $this->get_media_type_labels();

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'Context', 'koopo' ) . '</th><th>' . esc_html__( 'Media Type', 'koopo' ) . '</th><th>' . esc_html__( 'Delete Local', 'koopo' ) . '</th></tr></thead>';
		echo '<tbody>';
		foreach ( $contexts as $context_key => $context_label ) {
			foreach ( $types as $type_key => $type_label ) {
				$checked = ! empty( $policy[ $context_key ][ $type_key ] );
				echo '<tr>';
				echo '<td>' . esc_html( $context_label ) . '</td>';
				echo '<td>' . esc_html( $type_label ) . '</td>';
				echo '<td><label class="koopo-toggle koopo-toggle--compact"><input type="checkbox" name="koopo_bbmu_offload_delete_policy[' . esc_attr( $context_key ) . '][' . esc_attr( $type_key ) . ']" value="1" ' . checked( $checked, true, false ) . ' /><span class="koopo-toggle__track" data-on="ON" data-off="OFF"><span class="koopo-toggle__thumb"></span></span></label></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Deletion is only applied when the attachment is in the mapped folder and the extension matches the allowed list.', 'koopo' ) . '</p>';
	}

	public function render_delete_extensions_field() {
		$values = $this->get_delete_extensions();
		$types  = $this->get_media_type_labels();
		foreach ( $types as $type_key => $type_label ) {
			$value = isset( $values[ $type_key ] ) ? (string) $values[ $type_key ] : '';
			?>
			<p>
				<label for="koopo-bbmu-delete-ext-<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></label><br />
				<input
					type="text"
					class="regular-text"
					id="koopo-bbmu-delete-ext-<?php echo esc_attr( $type_key ); ?>"
					name="koopo_bbmu_offload_delete_extensions[<?php echo esc_attr( $type_key ); ?>]"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="jpg,png,webp"
				/>
			</p>
			<?php
		}
	}

	public function render_opt_max_dim_field() {
		$value = $this->get_opt_max_dim();
		?>
		<input type="number" class="small-text" name="koopo_bbmu_opt_max_dim" value="<?php echo esc_attr( $value ); ?>" min="0" step="1" />
		<p class="description"><?php esc_html_e( 'Longest edge in pixels. Set to 0 to disable downscaling.', 'koopo' ); ?></p>
		<?php
	}

	public function render_opt_sizes_field() {
		$value = $this->get_opt_sizes_array();
		$sizes = $this->get_registered_image_sizes();
		?>
		<?php if ( empty( $sizes ) ) : ?>
			<p class="description"><?php esc_html_e( 'No registered image sizes were found.', 'koopo' ); ?></p>
		<?php else : ?>
			<div class="koopo-admin__grid">
				<?php foreach ( $sizes as $size_key => $size_label ) : ?>
					<?php $checked = in_array( $size_key, $value, true ); ?>
					<label class="koopo-toggle koopo-toggle--stacked">
						<input type="checkbox" name="koopo_bbmu_opt_sizes[<?php echo esc_attr( $size_key ); ?>]" value="1" <?php checked( $checked ); ?> />
						<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
							<span class="koopo-toggle__thumb"></span>
						</span>
						<span class="koopo-toggle__label"><?php echo esc_html( $size_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Disable sizes to prevent WordPress from generating them. Leave all off to keep every size.', 'koopo' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_opt_strip_exif_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_opt_strip_exif', true );
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_opt_strip_exif" value="1" <?php checked( $enabled ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Remove EXIF metadata from images on upload', 'koopo' ); ?></span>
		</label>
		<?php
	}

	public function render_opt_jpeg_quality_field() {
		$value = $this->get_opt_jpeg_quality();
		?>
		<input type="number" class="small-text" name="koopo_bbmu_opt_jpeg_quality" value="<?php echo esc_attr( $value ); ?>" min="10" max="100" step="1" />
		<p class="description"><?php esc_html_e( 'Applies to JPEG re-encoding and scaled images.', 'koopo' ); ?></p>
		<?php
	}

	public function render_opt_webp_quality_field() {
		$value = $this->get_opt_webp_quality();
		?>
		<input type="number" class="small-text" name="koopo_bbmu_opt_webp_quality" value="<?php echo esc_attr( $value ); ?>" min="10" max="100" step="1" />
		<p class="description"><?php esc_html_e( 'Used when generating WebP variants.', 'koopo' ); ?></p>
		<?php
	}

	public function render_opt_generate_webp_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_opt_generate_webp', false );
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_opt_generate_webp" value="1" <?php checked( $enabled ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Create WebP versions for images', 'koopo' ); ?></span>
		</label>
		<?php
	}

	public function render_opt_generate_avif_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_opt_generate_avif', false );
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_opt_generate_avif" value="1" <?php checked( $enabled ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Create AVIF versions for images (if supported)', 'koopo' ); ?></span>
		</label>
		<?php
	}

	public function render_opt_keep_original_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_opt_keep_original', true );
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_opt_keep_original" value="1" <?php checked( $enabled ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Keep original full-size files when downscaling', 'koopo' ); ?></span>
		</label>
		<?php
	}

	public function render_opt_background_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_opt_background', false );
		$available = function_exists( 'as_enqueue_async_action' );
		?>
		<label class="koopo-toggle">
			<input type="checkbox" name="koopo_bbmu_opt_background" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $available ); ?> />
			<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
				<span class="koopo-toggle__thumb"></span>
			</span>
			<span class="koopo-toggle__label"><?php esc_html_e( 'Process optimization in the background (Action Scheduler)', 'koopo' ); ?></span>
		</label>
		<?php if ( ! $available ) : ?>
			<p class="description"><?php esc_html_e( 'Action Scheduler is not available. Optimization will run immediately on upload.', 'koopo' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_opt_webp_backfill_field() {
		echo '<div class="koopo-admin__card">';
		echo '<h3>' . esc_html__( 'WebP Backfill', 'koopo' ) . '</h3>';
		echo '<p>' . esc_html__( 'Generate WebP files for existing image attachments using the current optimization settings.', 'koopo' ) . '</p>';
		echo '<div class="koopo-admin__progress" aria-hidden="true"><span class="koopo-admin__progress-bar"></span></div>';
		echo '<div class="koopo-admin__progress-text" aria-live="polite"></div>';
		echo '<button type="button" class="button button-secondary koopo-admin__webp-backfill">' . esc_html__( 'Run WebP Backfill', 'koopo' ) . '</button>';
		echo '</div>';
	}

	public function filter_rml_active( $active ) {
		if ( is_admin() ) {
			return $active;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return $active;
		}

		return false;
	}

	public function handle_rml_backfill_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'koopo' ) );
		}

		check_admin_referer( 'koopo_bbmu_rml_backfill' );

		$paged    = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1;
		$per_page = 50;

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
			)
		);

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $attachment_id ) {
				$this->backfill_attachment( (int) $attachment_id );
			}
		}

		$total = (int) $query->found_posts;
		$done  = min( $paged * $per_page, $total );
		$status = ( $done >= $total || empty( $query->posts ) ) ? 'complete' : 'progress';

		$redirect = add_query_arg(
			array(
				'page'                      => 'koopo-bbmu-offload',
				'koopo_bbmu_backfill_status' => $status,
				'koopo_bbmu_backfill_page'   => $paged,
				'koopo_bbmu_backfill_total'  => $total,
				'koopo_bbmu_backfill_done'   => $done,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_offload_media_types_field() {
		$values = $this->get_offload_media_types();
		$types  = $this->get_media_type_labels();
		foreach ( $types as $key => $label ) {
			$checked = ! empty( $values[ $key ] );
			?>
			<label class="koopo-toggle koopo-toggle--stacked">
				<input type="checkbox" name="koopo_bbmu_offload_media_types[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
				<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
					<span class="koopo-toggle__thumb"></span>
				</span>
				<span class="koopo-toggle__label"><?php echo esc_html( $label ); ?></span>
			</label>
			<?php
		}
	}

	public function render_offload_post_types_field() {
		$values = $this->get_offload_post_types();
		$types  = $this->get_post_type_labels();
		foreach ( $types as $key => $label ) {
			$checked = ! empty( $values[ $key ] );
			?>
			<label class="koopo-toggle koopo-toggle--stacked">
				<input type="checkbox" name="koopo_bbmu_offload_post_types[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
				<span class="koopo-toggle__track" data-on="ON" data-off="OFF">
					<span class="koopo-toggle__thumb"></span>
				</span>
				<span class="koopo-toggle__label"><?php echo esc_html( $label ); ?></span>
			</label>
			<?php
		}
		echo '<p class="description">' . esc_html__( 'BuddyBoss media uses the "social" toggle.', 'koopo' ) . '</p>';
	}

	public function render_offload_folders_field() {
		$values = $this->get_offload_folder_templates();
		$types  = $this->get_post_type_labels();
		foreach ( $types as $key => $label ) {
			$template = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
			?>
			<p>
				<label for="koopo-bbmu-folder-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><br />
				<input
					type="text"
					class="regular-text"
					id="koopo-bbmu-folder-<?php echo esc_attr( $key ); ?>"
					name="koopo_bbmu_offload_folders[<?php echo esc_attr( $key ); ?>]"
					value="<?php echo esc_attr( $template ); ?>"
				/>
			</p>
			<?php
		}
	}

	public function sanitize_checkbox( $value ) {
		return ! empty( $value ) ? 1 : 0;
	}

	public function sanitize_scope( $value ) {
		return ( 'all' === $value ) ? 'all' : 'buddyboss';
	}

	public function sanitize_provider( $value ) {
		$allowed = array( 'bunny', 's3', 'gdrive', 'onedrive' );
		return in_array( $value, $allowed, true ) ? $value : 'bunny';
	}

	public function sanitize_media_types( $value ) {
		$allowed = array_keys( $this->get_media_type_labels() );
		$clean   = array();
		if ( is_array( $value ) ) {
			foreach ( $allowed as $key ) {
				$clean[ $key ] = ! empty( $value[ $key ] ) ? 1 : 0;
			}
		}
		return $clean;
	}

	public function sanitize_post_types( $value ) {
		$allowed = array_keys( $this->get_post_type_labels() );
		$clean   = array();
		if ( is_array( $value ) ) {
			foreach ( $allowed as $key ) {
				$clean[ $key ] = ! empty( $value[ $key ] ) ? 1 : 0;
			}
		}
		return $clean;
	}

	public function sanitize_folder_templates( $value ) {
		$allowed = array_keys( $this->get_post_type_labels() );
		$clean   = array();
		if ( is_array( $value ) ) {
			foreach ( $allowed as $key ) {
				$template        = isset( $value[ $key ] ) ? (string) $value[ $key ] : '';
				$template        = sanitize_text_field( $template );
				$template        = trim( $template );
				$clean[ $key ] = $template;
			}
		}
		return $clean;
	}

	public function sanitize_rml_folder_map( $value ) {
		$contexts = array_keys( $this->get_post_type_labels() );
		$types    = array_keys( $this->get_media_type_labels() );
		$clean    = array();

		if ( is_array( $value ) ) {
			foreach ( $contexts as $context ) {
				foreach ( $types as $type ) {
					$folder_id = isset( $value[ $context ][ $type ] ) ? absint( $value[ $context ][ $type ] ) : 0;
					if ( $folder_id ) {
						$clean[ $context ][ $type ] = $folder_id;
					}
				}
			}
		}

		return $clean;
	}

	public function sanitize_rml_folder_create( $value ) {
		$contexts = array_keys( $this->get_post_type_labels() );
		$types    = array_keys( $this->get_media_type_labels() );
		$clean    = array();

		if ( ! function_exists( 'wp_rml_active' ) || ! wp_rml_active() || ! function_exists( 'wp_rml_create_p' ) ) {
			return $clean;
		}

		if ( is_array( $value ) ) {
			foreach ( $contexts as $context ) {
				foreach ( $types as $type ) {
					$path = isset( $value[ $context ][ $type ] ) ? sanitize_text_field( $value[ $context ][ $type ] ) : '';
					$path = trim( $path );
					if ( '' === $path ) {
						continue;
					}

					$parent_id = function_exists( '_wp_rml_root' ) ? _wp_rml_root() : -1;
					$created   = wp_rml_create_p( $path, $parent_id, defined( 'RML_TYPE_FOLDER' ) ? RML_TYPE_FOLDER : 0 );
					if ( is_int( $created ) && $created > 0 ) {
						$map = $this->get_rml_folder_map();
						$map[ $context ][ $type ] = $created;
						update_option( 'koopo_bbmu_rml_folder_map', $map );
					} else {
						$clean[ $context ][ $type ] = $path;
					}
				}
			}
		}

		return $clean;
	}

	public function sanitize_delete_policy( $value ) {
		$contexts = array_keys( $this->get_post_type_labels() );
		$types    = array_keys( $this->get_media_type_labels() );
		$clean    = array();

		if ( is_array( $value ) ) {
			foreach ( $contexts as $context ) {
				foreach ( $types as $type ) {
					$clean[ $context ][ $type ] = ! empty( $value[ $context ][ $type ] ) ? 1 : 0;
				}
			}
		}

		return $clean;
	}

	public function sanitize_delete_extensions( $value ) {
		$types = array_keys( $this->get_media_type_labels() );
		$clean = array();

		if ( is_array( $value ) ) {
			foreach ( $types as $type ) {
				$list = isset( $value[ $type ] ) ? sanitize_text_field( $value[ $type ] ) : '';
				$list = strtolower( trim( $list ) );
				$clean[ $type ] = $list;
			}
		}

		return $clean;
	}

	public function sanitize_opt_max_dim( $value ) {
		return absint( $value );
	}

	public function sanitize_opt_sizes( $value ) {
		$allowed = $this->get_registered_image_size_keys();
		$clean   = array();

		if ( is_array( $value ) ) {
			foreach ( $allowed as $key ) {
				$clean[ $key ] = ! empty( $value[ $key ] ) ? 1 : 0;
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			foreach ( $parts as $part ) {
				$key = sanitize_key( $part );
				if ( in_array( $key, $allowed, true ) ) {
					$clean[ $key ] = 1;
				}
			}
		}

		return $clean;
	}

	public function sanitize_quality( $value ) {
		$value = absint( $value );
		if ( $value < 10 ) {
			return 10;
		}
		if ( $value > 100 ) {
			return 100;
		}
		return $value;
	}

	public function filter_big_image_threshold( $threshold ) {
		$max_dim = $this->get_opt_max_dim();
		if ( $max_dim <= 0 ) {
			return false;
		}
		return $max_dim;
	}

	public function filter_intermediate_sizes( $sizes, $metadata, $attachment_id ) {
		$allowed = $this->get_opt_sizes_array();
		if ( empty( $allowed ) || ! is_array( $sizes ) ) {
			return $sizes;
		}

		$filtered = array();
		foreach ( $sizes as $name => $size ) {
			if ( in_array( $name, $allowed, true ) ) {
				$filtered[ $name ] = $size;
			}
		}

		return $filtered;
	}

	public function filter_editor_quality( $quality, $mime_type ) {
		if ( 'image/jpeg' === $mime_type || 'image/jpg' === $mime_type ) {
			return $this->get_opt_jpeg_quality();
		}
		if ( 'image/webp' === $mime_type ) {
			return $this->get_opt_webp_quality();
		}
		return $quality;
	}

	public function optimize_attachment_metadata( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return $metadata;
		}

		if ( ! $this->is_image_attachment( $attachment_id ) ) {
			return $metadata;
		}

		if ( $this->should_optimize_in_background() ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'koopo_bbmu_optimize_attachment', array( $attachment_id ) );
				return $metadata;
			}
		}

		$this->run_attachment_optimization( $attachment_id, $metadata );
		$this->maybe_delete_original_if_scaled( $attachment_id, $metadata );
		return $metadata;
	}

	public function run_attachment_optimization( $attachment_id, $metadata = null ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || ! $this->is_image_attachment( $attachment_id ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( empty( $file ) || ! file_exists( $file ) ) {
			return;
		}

		$strip_exif = $this->get_opt_strip_exif();
		$generate_webp = $this->get_opt_generate_webp();
		$generate_avif = $this->get_opt_generate_avif();

		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		if ( $strip_exif || $generate_webp || $generate_avif ) {
			if ( $this->is_image_too_large_for_memory( $file ) ) {
				update_post_meta( $attachment_id, 'koopo_bbmu_opt_skipped', 'memory_guard' );
				return;
			}
		}

		if ( $strip_exif || $generate_webp || $generate_avif ) {
			$editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $editor ) ) {
				if ( $strip_exif ) {
					$editor->set_quality( $this->get_opt_jpeg_quality() );
					$editor->save( $file );
				}

				$alt_formats = $this->get_alt_formats_meta( $attachment_id );
				if ( $generate_webp ) {
					$alt_formats = $this->generate_alt_formats_for_metadata( $attachment_id, $metadata, 'webp', $this->get_opt_webp_quality(), $alt_formats );
				}
				if ( $generate_avif ) {
					$alt_formats = $this->generate_alt_formats_for_metadata( $attachment_id, $metadata, 'avif', $this->get_opt_webp_quality(), $alt_formats );
				}

				if ( ! empty( $alt_formats ) ) {
					update_post_meta( $attachment_id, 'koopo_bbmu_alt_formats', $alt_formats );
				}
			}
		}

		$this->maybe_delete_original_if_scaled( $attachment_id, $metadata );
	}

	public function handle_attachment_metadata_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_wp_attachment_metadata' !== $meta_key ) {
			return;
		}

		if ( 'attachment' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( $this->get_opt_keep_original() ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_original_image' ) ) {
			return;
		}

		$already = (int) get_post_meta( $post_id, 'koopo_bbmu_original_deleted', true );
		if ( $already ) {
			return;
		}

		$this->maybe_delete_original_if_scaled( $post_id, $meta_value );
	}

	private function maybe_delete_original_if_scaled( $attachment_id, $metadata ) {
		if ( $this->get_opt_keep_original() ) {
			return;
		}

		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) ) {
			return;
		}

		$already = (int) get_post_meta( $attachment_id, 'koopo_bbmu_original_deleted', true );
		if ( $already ) {
			return;
		}

		if ( function_exists( 'wp_delete_original_image' ) ) {
			wp_delete_original_image( $attachment_id );
		} else {
			$uploads = wp_get_upload_dir();
			$subdir = '';
			if ( ! empty( $metadata['file'] ) ) {
				$subdir = trim( dirname( $metadata['file'] ), '/\\' );
			}
			$original = trailingslashit( $uploads['basedir'] ) . ( $subdir ? trailingslashit( $subdir ) : '' ) . $metadata['original_image'];
			if ( file_exists( $original ) ) {
				wp_delete_file( $original );
			}
		}

		update_post_meta( $attachment_id, 'koopo_bbmu_original_deleted', 1 );
	}

	private function is_image_too_large_for_memory( $file ) {
		$size = @getimagesize( $file );
		if ( empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];
		$channels = ! empty( $size['channels'] ) ? (int) $size['channels'] : 4;

		$needed = $width * $height * $channels;
		$needed = (int) ( $needed * 1.8 ); // Safety overhead for GD/Imagick.

		$limit = $this->get_memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false;
		}

		$usage = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;
		return ( $usage + $needed ) > (int) ( $limit * 0.85 );
	}

	private function get_memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );
		if ( ! $limit || '-1' === $limit ) {
			return -1;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $limit );
		}

		return (int) $limit;
	}

	private function get_alt_formats_meta( $attachment_id ) {
		$existing = get_post_meta( $attachment_id, 'koopo_bbmu_alt_formats', true );
		return is_array( $existing ) ? $existing : array();
	}

	private function generate_alt_formats_for_metadata( $attachment_id, $metadata, $extension, $quality, $alt_formats ) {
		$files = array();
		$file = get_attached_file( $attachment_id );
		if ( $file ) {
			$files['full'] = $file;
		}

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
			$uploads = wp_get_upload_dir();
			$subdir = '';
			if ( ! empty( $metadata['file'] ) ) {
				$subdir = trim( dirname( $metadata['file'] ), '/\\' );
			}
			$base_dir = trailingslashit( $uploads['basedir'] );
			foreach ( $metadata['sizes'] as $size_key => $info ) {
				if ( empty( $info['file'] ) ) {
					continue;
				}
				$files[ $size_key ] = $base_dir . ( $subdir ? trailingslashit( $subdir ) : '' ) . $info['file'];
			}
		}

		foreach ( $files as $size_key => $path ) {
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}

			$alt_formats = $this->maybe_generate_alt_format_for_file( $path, $extension, $quality, $alt_formats, $size_key );
		}

		return $alt_formats;
	}

	private function maybe_generate_alt_format_for_file( $file, $extension, $quality, $alt_formats, $size_key ) {
		if ( isset( $alt_formats[ $extension ][ $size_key ] ) ) {
			return $alt_formats;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) || ! method_exists( $editor, 'supports_mime_type' ) ) {
			return $alt_formats;
		}

		$mime = 'image/' . $extension;
		if ( ! $editor->supports_mime_type( $mime ) ) {
			return $alt_formats;
		}

		$alt_path = $this->build_alt_format_path( $file, $extension );
		if ( '' === $alt_path ) {
			return $alt_formats;
		}

		if ( file_exists( $alt_path ) ) {
			$alt_formats[ $extension ][ $size_key ] = $this->make_upload_relative_path( $alt_path );
			return $alt_formats;
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $alt_path, $mime );
		if ( is_wp_error( $saved ) ) {
			return $alt_formats;
		}

		$alt_formats[ $extension ][ $size_key ] = $this->make_upload_relative_path( $alt_path );
		return $alt_formats;
	}

	private function build_alt_format_path( $file, $extension ) {
		$info = pathinfo( $file );
		if ( empty( $info['dirname'] ) || empty( $info['filename'] ) ) {
			return '';
		}

		return trailingslashit( $info['dirname'] ) . $info['filename'] . '.' . $extension;
	}

	private function make_upload_relative_path( $path ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $path ) ) {
			return $path;
		}

		$relative = ltrim( str_replace( $uploads['basedir'], '', $path ), '/\\' );
		return str_replace( '\\', '/', $relative );
	}

	private function maybe_replace_with_webp_url( $url, $attachment_id, $size_key ) {
		if ( empty( $url ) || ! $this->get_opt_generate_webp() ) {
			return $url;
		}

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $url;
		}

		if ( $this->is_offload_enabled() && '' !== $this->get_offload_base_url() ) {
			return $url;
		}

		if ( ! $this->client_supports_webp() ) {
			return $url;
		}

		$webp_url = $this->get_webp_url_for_size( $attachment_id, $size_key, $url );
		return $webp_url ? $webp_url : $url;
	}

	private function maybe_replace_with_webp_image_src( $image, $attachment_id, $size ) {
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$size_key = $this->resolve_size_key( $attachment_id, $size, $image[0] );
		$image[0] = $this->maybe_replace_with_webp_url( $image[0], $attachment_id, $size_key );
		return $image;
	}

	private function get_webp_url_for_size( $attachment_id, $size_key, $fallback_url ) {
		$formats = $this->get_alt_formats_meta( $attachment_id );
		if ( empty( $formats['webp'] ) || ! is_array( $formats['webp'] ) ) {
			return '';
		}

		$relative = '';
		if ( $size_key && isset( $formats['webp'][ $size_key ] ) ) {
			$relative = $formats['webp'][ $size_key ];
		} elseif ( isset( $formats['webp']['full'] ) ) {
			$relative = $formats['webp']['full'];
		}

		if ( '' === $relative ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['baseurl'] ) . ltrim( $relative, '/' );
	}

	private function resolve_size_key( $attachment_id, $size, $url ) {
		if ( is_string( $size ) ) {
			return $size;
		}

		$relative = $this->url_to_relative( $url );
		if ( ! $relative ) {
			return 'full';
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['sizes'] ) ) {
			return 'full';
		}

		$file = basename( $relative );
		foreach ( $meta['sizes'] as $key => $info ) {
			if ( isset( $info['file'] ) && $info['file'] === $file ) {
				return $key;
			}
		}

		return 'full';
	}

	private function client_supports_webp() {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		return false !== strpos( (string) $_SERVER['HTTP_ACCEPT'], 'image/webp' );
	}

	public function filter_content_webp_urls( $content ) {
		if ( empty( $content ) || ! $this->get_opt_generate_webp() ) {
			return $content;
		}

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $content;
		}

		if ( ! $this->client_supports_webp() ) {
			return $content;
		}

		return $this->replace_content_webp_urls( $content );
	}

	private function replace_content_webp_urls( $content ) {
		$content = preg_replace_callback(
			'/(<img[^>]+src=["\'])([^"\']+)(["\'][^>]*>)/i',
			function ( $matches ) {
				$prefix = $matches[1];
				$src = $matches[2];
				$suffix = $matches[3];
				$tag = $matches[0];

				$attachment_id = $this->extract_attachment_id_from_img( $tag );
				if ( $attachment_id ) {
					$size_key = $this->resolve_size_key( $attachment_id, 'full', $src );
					$webp = $this->get_webp_url_for_size( $attachment_id, $size_key, $src );
				} else {
					$webp = $this->get_webp_url_from_attachment_url( $src );
				}

				if ( $webp ) {
					$src = $webp;
				}
				return $prefix . $src . $suffix;
			},
			$content
		);

		$content = preg_replace_callback(
			'/(srcset=["\'])([^"\']+)(["\'])/i',
			function ( $matches ) {
				$prefix = $matches[1];
				$srcset = $matches[2];
				$suffix = $matches[3];
				$parts = array_map( 'trim', explode( ',', $srcset ) );
				$rebuilt = array();
				foreach ( $parts as $part ) {
					if ( '' === $part ) {
						continue;
					}
					$bits = preg_split( '/\s+/', $part );
					$url = $bits[0];
					$descriptor = isset( $bits[1] ) ? ' ' . $bits[1] : '';
					$webp = $this->get_webp_url_from_attachment_url( $url );
					$rebuilt[] = ( $webp ? $webp : $url ) . $descriptor;
				}
				return $prefix . implode( ', ', $rebuilt ) . $suffix;
			},
			$content
		);

		return $content;
	}

	private function get_webp_url_from_attachment_url( $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		if ( ! $attachment_id ) {
			return '';
		}

		$size_key = $this->resolve_size_key( $attachment_id, 'full', $url );
		return $this->get_webp_url_for_size( $attachment_id, $size_key, $url );
	}

	private function extract_attachment_id_from_img( $tag ) {
		if ( ! preg_match( '/wp-image-(\\d+)/', $tag, $matches ) ) {
			return 0;
		}

		return absint( $matches[1] );
	}

	private function url_to_relative( $url ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $url ) ) {
			return '';
		}

		if ( 0 !== strpos( $url, $uploads['baseurl'] ) ) {
			return '';
		}

		return ltrim( str_replace( $uploads['baseurl'], '', $url ), '/\\' );
	}

	private function ensure_attachment_metadata_generated( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || ! $this->is_image_attachment( $attachment_id ) ) {
			return;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			return;
		}

		$already = (int) get_post_meta( $attachment_id, 'koopo_bbmu_meta_generated', true );
		if ( $already ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( empty( $file ) || ! file_exists( $file ) ) {
			return;
		}

		$generated = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_array( $generated ) ) {
			wp_update_attachment_metadata( $attachment_id, $generated );
			update_post_meta( $attachment_id, 'koopo_bbmu_meta_generated', 1 );
		}
	}

	private function record_upload_source( $attachment_id, $source, $extra = array() ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		$data = $this->build_upload_source_data( $attachment_id, $source, $extra );
		update_post_meta( $attachment_id, 'koopo_bbmu_upload_source', $data );
	}

	private function build_upload_source_data( $attachment_id, $source, $extra = array() ) {
		$attachment = get_post( $attachment_id );
		$request_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$referer = wp_get_referer();

		$data = array(
			'source'      => sanitize_key( $source ),
			'context'     => $this->get_attachment_context( $attachment_id ),
			'media_type'  => $this->get_media_type_key( $attachment_id ),
			'user_id'     => $attachment && $attachment->post_author ? (int) $attachment->post_author : get_current_user_id(),
			'action'      => $request_action,
			'is_admin'    => is_admin() ? 1 : 0,
			'is_ajax'     => ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 1 : 0,
			'request_uri' => $this->sanitize_request_path( $request_uri ),
			'referer'     => $this->sanitize_request_path( $referer ),
			'timestamp'   => current_time( 'mysql' ),
		);

		if ( is_array( $extra ) ) {
			foreach ( $extra as $key => $value ) {
				$data[ sanitize_key( $key ) ] = $value;
			}
		}

		return apply_filters( 'koopo_bbmu_upload_source_data', $data, $attachment_id, $source );
	}

	private function sanitize_request_path( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		$parsed = wp_parse_url( $value );
		if ( empty( $parsed ) ) {
			return '';
		}

		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$query = isset( $parsed['query'] ) ? $parsed['query'] : '';

		if ( '' !== $query ) {
			$path .= '?' . $query;
		}

		return sanitize_text_field( $path );
	}

	public function handle_attachment_created( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		if ( $this->is_buddyboss_media_attachment( $attachment_id ) ) {
			return;
		}

		$context = $this->detect_context_from_request( $attachment_id );
		$this->record_upload_source(
			$attachment_id,
			'wp_attachment',
			array(
				'context' => $context,
			)
		);
		if ( $context ) {
			update_post_meta( $attachment_id, 'koopo_bbmu_context', $context );
			$this->maybe_assign_rml_folder_for_context( $attachment_id, $context );
		} else {
			$this->maybe_assign_rml_folder( $attachment_id );
		}

		$context    = $this->get_attachment_context( $attachment_id );
		$media_type = $this->get_media_type_key( $attachment_id );
		$offload_key = $this->build_offload_key( $attachment_id, 'full' );

		do_action(
			'koopo_bbmu_offload_attachment',
			$attachment_id,
			array(
				'context' => 'wp_attachment',
				'post_type' => $context,
				'media_type' => $media_type,
				'provider' => $this->get_offload_provider(),
				'base_url' => $this->get_offload_base_url(),
				'offload_key' => $offload_key,
			)
		);
	}

	public function handle_offload_uploaded( $attachment_id, $result = array() ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		$this->maybe_delete_local_files( $attachment_id );
	}

	public function handle_bp_media_add( $media ) {
		if ( empty( $media ) || empty( $media->attachment_id ) ) {
			return;
		}

		$attachment_id = (int) $media->attachment_id;
		$this->ensure_attachment_metadata_generated( $attachment_id );
		$this->record_upload_source(
			$attachment_id,
			'buddyboss_media_add',
			array(
				'media_id' => ! empty( $media->id ) ? (int) $media->id : 0,
			)
		);
		$this->maybe_assign_rml_folder( $attachment_id );
	}

	public function handle_story_created( $story_id, $item_id, $user_id ) {
		$attachment_id = (int) get_post_meta( $item_id, 'attachment_id', true );
		if ( ! $attachment_id ) {
			return;
		}

		$this->ensure_attachment_metadata_generated( $attachment_id );
		$this->record_upload_source(
			$attachment_id,
			'buddyboss_story',
			array(
				'story_id' => (int) $story_id,
				'item_id'  => (int) $item_id,
				'user_id'  => (int) $user_id,
			)
		);
		update_post_meta( $attachment_id, 'koopo_bbmu_context', 'social' );
		$this->maybe_assign_rml_folder_for_context( $attachment_id, 'social' );
	}

	public function handle_attachment_meta_linked( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_thumbnail_id' === $meta_key ) {
			$attachment_id = absint( $meta_value );
			if ( ! $attachment_id ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			$context = (string) $post->post_type;
			update_post_meta( $attachment_id, 'koopo_bbmu_context', $context );
			$this->maybe_assign_rml_folder_for_context( $attachment_id, $context );
			return;
		}

		if ( '_product_image_gallery' === $meta_key ) {
			$ids = array();
			if ( is_array( $meta_value ) ) {
				$ids = $meta_value;
			} else {
				$ids = array_filter( array_map( 'absint', explode( ',', (string) $meta_value ) ) );
			}

			if ( empty( $ids ) ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			$context = (string) $post->post_type;
			foreach ( $ids as $attachment_id ) {
				update_post_meta( $attachment_id, 'koopo_bbmu_context', $context );
				$this->maybe_assign_rml_folder_for_context( $attachment_id, $context );
			}
		}
	}

	public function handle_media_upload( $attachment ) {
		if ( empty( $attachment ) || empty( $attachment->ID ) ) {
			return;
		}

		$attachment_id = (int) $attachment->ID;
		$media_id      = (int) get_post_meta( $attachment_id, 'bp_media_id', true );

		$this->ensure_attachment_metadata_generated( $attachment_id );
		$this->record_upload_source(
			$attachment_id,
			'buddyboss_media_upload',
			array(
				'media_id' => $media_id,
			)
		);
		$this->maybe_assign_rml_folder( $attachment_id );

		$context    = $this->get_attachment_context( $attachment_id );
		$media_type = $this->get_media_type_key( $attachment_id );
		$offload_key = $this->build_offload_key( $attachment_id, 'full' );

		/**
		 * Allow offload adapters to move media after upload.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $context       Context data including media_id.
		 */
		do_action(
			'koopo_bbmu_offload_attachment',
			$attachment_id,
			array(
				'media_id' => $media_id,
				'context'  => 'bb_media_upload',
				'post_type' => $context,
				'media_type' => $media_type,
				'provider' => $this->get_offload_provider(),
				'base_url' => $this->get_offload_base_url(),
				'offload_key' => $offload_key,
			)
		);
	}

	private function maybe_assign_rml_folder( $attachment_id ) {
		$context = $this->get_attachment_context( $attachment_id );
		$this->maybe_assign_rml_folder_for_context( $attachment_id, $context );
	}

	private function maybe_assign_rml_folder_for_context( $attachment_id, $context ) {
		if ( ! $this->is_rml_enabled() ) {
			return;
		}

		if ( ! function_exists( 'wp_rml_move' ) || ! function_exists( 'wp_attachment_folder' ) ) {
			return;
		}

		$media_type = $this->get_media_type_key( $attachment_id );
		$map        = $this->get_rml_folder_map();
		$folder_id  = isset( $map[ $context ][ $media_type ] ) ? absint( $map[ $context ][ $media_type ] ) : 0;
		$user_folder_id = $this->get_or_create_user_folder_id( $attachment_id );

		$primary_folder_id = $folder_id ? $folder_id : $user_folder_id;
		if ( ! $primary_folder_id ) {
			return;
		}

		$current_folder = (int) wp_attachment_folder( $attachment_id );
		if ( $current_folder === $primary_folder_id ) {
			return;
		}

		$result = wp_rml_move( $primary_folder_id, array( $attachment_id ), true );
		if ( is_array( $result ) && function_exists( '_wp_rml_synchronize_attachment' ) ) {
			_wp_rml_synchronize_attachment( $attachment_id, $primary_folder_id, false );
		}

		if ( function_exists( 'wp_rml_update_count' ) ) {
			wp_rml_update_count( array( $primary_folder_id ), array( $attachment_id ) );
		}

		if ( $user_folder_id && $user_folder_id !== $primary_folder_id && function_exists( 'wp_rml_create_shortcuts' ) ) {
			wp_rml_create_shortcuts( $user_folder_id, array( $attachment_id ), true );
		}
	}

	private function maybe_delete_local_files( $attachment_id ) {
		if ( ! $this->is_offload_enabled() ) {
			return;
		}

		$context    = $this->get_attachment_context( $attachment_id );
		$media_type = $this->get_media_type_key( $attachment_id );

		if ( ! $this->is_delete_allowed( $attachment_id, $context, $media_type ) ) {
			return;
		}

		$deleted = array();
		$file    = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) ) {
			if ( wp_delete_file( $file ) ) {
				$deleted[] = $file;
			}
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$upload_dir = wp_get_upload_dir();
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = trailingslashit( $upload_dir['basedir'] ) . dirname( $meta['file'] ) . '/' . $size['file'];
				if ( file_exists( $path ) && wp_delete_file( $path ) ) {
					$deleted[] = $path;
				}
			}
		}

		do_action( 'koopo_bbmu_local_files_deleted', $attachment_id, $deleted );
	}

	private function backfill_attachment( $attachment_id ) {
		if ( ! $this->is_rml_enabled() ) {
			return;
		}

		$context = $this->get_attachment_context( $attachment_id );
		if ( 'unassigned' === $context ) {
			$context = $this->find_context_from_meta( $attachment_id );
			if ( $context ) {
				update_post_meta( $attachment_id, 'koopo_bbmu_context', $context );
			}
		}

		if ( ! $context ) {
			$context = 'unassigned';
		}

		$this->maybe_assign_rml_folder_for_context( $attachment_id, $context );
	}

	private function find_context_from_meta( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return '';
		}

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
				$attachment_id
			)
		);
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return (string) $post->post_type;
			}
		}

		$like_exact = (string) $attachment_id;
		$like_start = $attachment_id . ',%';
		$like_mid   = '%,' . $attachment_id . ',%';
		$like_end   = '%,' . $attachment_id;

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s) LIMIT 1",
				$like_exact,
				$like_start,
				$like_mid,
				$like_end
			)
		);
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return (string) $post->post_type;
			}
		}

		return '';
	}

	public function filter_attachment_url( $url, $post_id ) {
		if ( ! $this->is_offload_enabled() || 'all' !== $this->get_offload_scope() ) {
			return $this->maybe_replace_with_webp_url( $url, $post_id, 'full' );
		}

		if ( ! $this->should_offload_attachment( $post_id ) ) {
			return $this->maybe_replace_with_webp_url( $url, $post_id, 'full' );
		}

		$offload_url = $this->build_offload_url( $post_id, 'full' );
		$final_url = $offload_url ? $offload_url : $url;
		return $this->maybe_replace_with_webp_url( $final_url, $post_id, 'full' );
	}

	public function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! $this->is_offload_enabled() || 'all' !== $this->get_offload_scope() ) {
			return $this->maybe_replace_with_webp_image_src( $image, $attachment_id, $size );
		}

		if ( ! $this->should_offload_attachment( $attachment_id ) ) {
			return $this->maybe_replace_with_webp_image_src( $image, $attachment_id, $size );
		}

		$offload_url = $this->build_offload_url( $attachment_id, $size );
		if ( $offload_url && is_array( $image ) ) {
			$image[0] = $offload_url;
		}

		return $this->maybe_replace_with_webp_image_src( $image, $attachment_id, $size );
	}

	private function maybe_offload_url( $attachment_id, $size, $fallback ) {
		if ( ! $this->is_offload_enabled() ) {
			return $fallback;
		}

		if ( ! $this->should_offload_attachment( $attachment_id ) ) {
			return $fallback;
		}

		$offload_url = $this->build_offload_url( $attachment_id, $size );
		return $offload_url ? $offload_url : $fallback;
	}

	private function build_offload_url( $attachment_id, $size ) {
		$base_url = $this->get_offload_base_url();
		if ( '' === $base_url ) {
			return '';
		}

		$key = $this->build_offload_key( $attachment_id, $size );
		if ( '' === $key ) {
			return '';
		}

		$url = trailingslashit( $base_url ) . ltrim( $key, '/' );

		return apply_filters( 'koopo_bbmu_offload_url', $url, $attachment_id, $size, $key );
	}

	private function is_buddyboss_media_attachment( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, 'bp_media_id', true );
	}

	private function should_offload_attachment( $attachment_id ) {
		$scope = $this->get_offload_scope();
		$context = $this->get_attachment_context( $attachment_id );

		if ( 'buddyboss' === $scope && 'social' !== $context ) {
			return false;
		}

		$post_types = $this->get_offload_post_types();
		if ( empty( $post_types[ $context ] ) ) {
			return false;
		}

		$media_type = $this->get_media_type_key( $attachment_id );
		$media_types = $this->get_offload_media_types();
		if ( empty( $media_types[ $media_type ] ) ) {
			return false;
		}

		return true;
	}

	private function is_delete_allowed( $attachment_id, $context, $media_type ) {
		if ( ! $this->is_rml_enabled() ) {
			return false;
		}

		$policy = $this->get_delete_policy();
		if ( empty( $policy[ $context ][ $media_type ] ) ) {
			return false;
		}

		if ( ! $this->is_extension_allowed( $attachment_id, $media_type ) ) {
			return false;
		}

		$map = $this->get_rml_folder_map();
		$folder_id = isset( $map[ $context ][ $media_type ] ) ? absint( $map[ $context ][ $media_type ] ) : 0;
		if ( ! $folder_id ) {
			return false;
		}

		if ( ! function_exists( 'wp_attachment_folder' ) ) {
			return false;
		}

		$current_folder = (int) wp_attachment_folder( $attachment_id );
		if ( $current_folder !== $folder_id ) {
			return false;
		}

		$allowed = true;
		return (bool) apply_filters( 'koopo_bbmu_delete_local_allowed', $allowed, $attachment_id, $context, $media_type, $folder_id );
	}

	private function is_extension_allowed( $attachment_id, $media_type ) {
		$allowed_list = $this->get_delete_extensions();
		$list = isset( $allowed_list[ $media_type ] ) ? (string) $allowed_list[ $media_type ] : '';
		$list = trim( $list );

		if ( '' === $list ) {
			return true;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return false;
		}

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}

		$allowed = array();
		foreach ( explode( ',', $list ) as $item ) {
			$item = strtolower( trim( $item ) );
			if ( '' !== $item ) {
				$allowed[] = $item;
			}
		}

		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $ext, $allowed, true );
	}

	private function get_attachment_context( $attachment_id ) {
		$forced = (string) get_post_meta( $attachment_id, 'koopo_bbmu_context', true );
		if ( '' !== $forced && array_key_exists( $forced, $this->get_post_type_labels() ) ) {
			return $forced;
		}

		if ( get_post_meta( $attachment_id, 'bp_media_upload', true ) ) {
			return 'social';
		}

		if ( $this->is_buddyboss_media_attachment( $attachment_id ) ) {
			return 'social';
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || empty( $attachment->post_parent ) ) {
			return 'unassigned';
		}

		$parent = get_post( $attachment->post_parent );
		if ( ! $parent ) {
			return 'unassigned';
		}

		return (string) $parent->post_type;
	}

	private function detect_context_from_request( $attachment_id ) {
		$keys = array( 'post_id', 'post_ID', 'post', 'parent_id' );
		foreach ( $keys as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) {
				$post_id = absint( $_REQUEST[ $key ] );
				if ( $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						return (string) $post->post_type;
					}
				}
			}
		}

		if ( get_post_meta( $attachment_id, 'bp_media_upload', true ) ) {
			return 'social';
		}

		return '';
	}

	private function get_media_type_key( $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'photos';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		return 'documents';
	}

	private function build_offload_key( $attachment_id, $size ) {
		$template = $this->get_folder_template_for_attachment( $attachment_id );
		$file     = $this->get_attachment_filename( $attachment_id, $size );
		if ( '' === $file ) {
			return '';
		}

		$filename  = basename( $file );
		$timestamp = get_post_time( 'U', true, $attachment_id );
		$year      = gmdate( 'Y', $timestamp );
		$month     = gmdate( 'm', $timestamp );
		$day       = gmdate( 'd', $timestamp );
		$context   = $this->get_attachment_context( $attachment_id );
		$media_type = $this->get_media_type_key( $attachment_id );

		$replacements = array(
			'{post_type}' => $context,
			'{media_type}' => $media_type,
			'{year}' => $year,
			'{month}' => $month,
			'{day}' => $day,
			'{filename}' => $filename,
		);

		$key = $template ? strtr( $template, $replacements ) : '';
		$key = trim( $key );
		$key = trim( $key, '/\\' );
		if ( '' === $key ) {
			$key = $this->get_default_relative_path( $attachment_id, $size );
			return $key;
		}

		if ( false === strpos( $key, $filename ) ) {
			$key = trailingslashit( $key ) . $filename;
		}

		$key = str_replace( '\\', '/', $key );

		return $key;
	}

	private function get_default_relative_path( $attachment_id, $size ) {
		$uploads = wp_get_upload_dir();
		$file    = $this->get_attachment_filename( $attachment_id, $size );

		if ( ! $file || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$relative = ltrim( str_replace( $uploads['basedir'], '', $file ), '/\\' );
		return str_replace( '\\', '/', $relative );
	}

	private function get_attachment_filename( $attachment_id, $size ) {
		$size = $size ? $size : 'full';

		if ( 'full' !== $size ) {
			$uploads = wp_get_upload_dir();
			$intermediate = image_get_intermediate_size( $attachment_id, $size );
			if ( $intermediate && ! empty( $intermediate['path'] ) ) {
				return trailingslashit( $uploads['basedir'] ) . $intermediate['path'];
			}
		}

		$file = get_attached_file( $attachment_id );
		return $file ? $file : '';
	}

	private function get_folder_template_for_attachment( $attachment_id ) {
		$templates = $this->get_offload_folder_templates();
		$context   = $this->get_attachment_context( $attachment_id );
		return isset( $templates[ $context ] ) ? (string) $templates[ $context ] : '';
	}

	private function is_offload_enabled() {
		$enabled = (bool) get_option( 'koopo_bbmu_offload_enabled', false );
		return (bool) apply_filters( 'koopo_bbmu_offload_enabled', $enabled );
	}

	private function get_offload_base_url() {
		$base_url = (string) get_option( 'koopo_bbmu_offload_base_url', '' );
		return untrailingslashit( $base_url );
	}

	private function get_offload_scope() {
		return (string) get_option( 'koopo_bbmu_offload_scope', 'buddyboss' );
	}

	private function get_offload_provider() {
		return (string) get_option( 'koopo_bbmu_offload_provider', 'bunny' );
	}

	private function get_opt_strip_exif() {
		return (bool) get_option( 'koopo_bbmu_opt_strip_exif', true );
	}

	private function get_opt_jpeg_quality() {
		$value = get_option( 'koopo_bbmu_opt_jpeg_quality', 82 );
		return $this->sanitize_quality( $value );
	}

	private function get_opt_webp_quality() {
		$value = get_option( 'koopo_bbmu_opt_webp_quality', 80 );
		return $this->sanitize_quality( $value );
	}

	private function get_opt_generate_webp() {
		return (bool) get_option( 'koopo_bbmu_opt_generate_webp', false );
	}

	private function get_opt_generate_avif() {
		return (bool) get_option( 'koopo_bbmu_opt_generate_avif', false );
	}

	private function get_opt_keep_original() {
		return (bool) get_option( 'koopo_bbmu_opt_keep_original', true );
	}

	private function should_optimize_in_background() {
		return (bool) get_option( 'koopo_bbmu_opt_background', false );
	}

	private function is_image_attachment( $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		return 0 === strpos( $mime, 'image/' );
	}

	private function get_opt_max_dim() {
		$value = get_option( 'koopo_bbmu_opt_max_dim', 2048 );
		return absint( $value );
	}

	private function get_opt_sizes_list() {
		$value = get_option(
			'koopo_bbmu_opt_sizes',
			array(
				'thumbnail' => 1,
				'large'     => 1,
			)
		);
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return 'thumbnail,large';
		}

		$list = array();
		foreach ( $value as $key => $enabled ) {
			if ( ! empty( $enabled ) ) {
				$list[] = $key;
			}
		}

		return implode( ',', $list );
	}

	private function get_opt_sizes_array() {
		$value = get_option(
			'koopo_bbmu_opt_sizes',
			array(
				'thumbnail' => 1,
				'large'     => 1,
			)
		);
		$clean = array();

		if ( is_string( $value ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			foreach ( $parts as $part ) {
				$key = sanitize_key( $part );
				if ( '' !== $key ) {
					$clean[] = $key;
				}
			}
			return array_values( array_unique( $clean ) );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $enabled ) {
				if ( ! empty( $enabled ) ) {
					$clean[] = $key;
				}
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function get_registered_image_sizes() {
		global $_wp_additional_image_sizes;

		$sizes      = array();
		$size_names = get_intermediate_image_sizes();

		foreach ( $size_names as $size ) {
			$width  = 0;
			$height = 0;
			$crop   = false;

			if ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
				$width  = (int) $_wp_additional_image_sizes[ $size ]['width'];
				$height = (int) $_wp_additional_image_sizes[ $size ]['height'];
				$crop   = (bool) $_wp_additional_image_sizes[ $size ]['crop'];
			} else {
				$width  = (int) get_option( "{$size}_size_w" );
				$height = (int) get_option( "{$size}_size_h" );
				$crop   = (bool) get_option( "{$size}_crop" );
			}

			$label = sprintf(
				'%1$s (%2$dx%3$d%4$s)',
				$size,
				$width,
				$height,
				$crop ? ', crop' : ''
			);
			$sizes[ $size ] = $label;
		}

		return $sizes;
	}

	private function get_registered_image_size_keys() {
		return array_keys( $this->get_registered_image_sizes() );
	}

	private function get_media_type_labels() {
		return array(
			'photos'    => __( 'Photos (images)', 'koopo' ),
			'video'     => __( 'Video', 'koopo' ),
			'audio'     => __( 'Audio', 'koopo' ),
			'documents' => __( 'Documents', 'koopo' ),
		);
	}

	private function get_post_type_labels() {
		return array(
			'product'   => __( 'Products', 'koopo' ),
			'post'      => __( 'Posts', 'koopo' ),
			'gd_place'  => __( 'Places (gd_place)', 'koopo' ),
			'gd_event'  => __( 'Events (gd_event)', 'koopo' ),
			'social'    => __( 'Social (BuddyBoss media)', 'koopo' ),
			'unassigned' => __( 'Unassigned', 'koopo' ),
		);
	}

	private function get_default_media_types() {
		return array(
			'photos'    => 1,
			'video'     => 1,
			'audio'     => 1,
			'documents' => 1,
		);
	}

	private function get_default_post_types() {
		return array(
			'product'   => 1,
			'post'      => 1,
			'gd_place'  => 1,
			'gd_event'  => 1,
			'social'    => 1,
			'unassigned' => 0,
		);
	}

	private function get_default_folder_templates() {
		return array(
			'product'   => 'products/{year}/{month}',
			'post'      => 'posts/{year}/{month}',
			'gd_place'  => 'places/{year}/{month}',
			'gd_event'  => 'events/{year}/{month}',
			'social'    => 'social/{media_type}',
			'unassigned' => 'uploads/{year}/{month}',
		);
	}

	private function get_offload_media_types() {
		$value = get_option( 'koopo_bbmu_offload_media_types', $this->get_default_media_types() );
		return is_array( $value ) ? $value : $this->get_default_media_types();
	}

	private function get_offload_post_types() {
		$value = get_option( 'koopo_bbmu_offload_post_types', $this->get_default_post_types() );
		return is_array( $value ) ? $value : $this->get_default_post_types();
	}

	private function get_offload_folder_templates() {
		$value = get_option( 'koopo_bbmu_offload_folders', $this->get_default_folder_templates() );
		return is_array( $value ) ? $value : $this->get_default_folder_templates();
	}

	private function get_or_create_user_folder_id( $attachment_id ) {
		if ( ! $this->is_rml_enabled() ) {
			return 0;
		}

		$enabled = (bool) get_option( 'koopo_bbmu_rml_user_folders_enabled', false );
		if ( ! $enabled ) {
			return 0;
		}

		if ( ! function_exists( 'wp_rml_get_object_by_id' ) || ! function_exists( 'wp_rml_create_p' ) ) {
			return 0;
		}

		$user_id = 0;
		$attachment = get_post( $attachment_id );
		if ( $attachment && ! empty( $attachment->post_author ) ) {
			$user_id = (int) $attachment->post_author;
		}
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return 0;
		}

		$cached = (int) get_user_meta( $user_id, 'koopo_bbmu_rml_user_folder_id', true );
		if ( $cached > 0 ) {
			$folder = wp_rml_get_object_by_id( $cached );
			if ( function_exists( 'is_rml_folder' ) && is_rml_folder( $folder ) ) {
				return $cached;
			}
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return 0;
		}

		$template = (string) get_option( 'koopo_bbmu_rml_user_folder_template', 'Users/{user_login}' );
		$template = trim( $template );
		$replacements = array(
			'{user_id}' => (string) $user_id,
			'{user_login}' => $user->user_login,
			'{user_nicename}' => $user->user_nicename,
			'{display_name}' => $user->display_name,
		);

		$path = strtr( $template, $replacements );
		$path = trim( $path );
		if ( '' === $path ) {
			$path = 'Users/' . $user->user_login;
		}

		$parent = (int) get_option( 'koopo_bbmu_rml_user_folder_parent', 0 );
		if ( ! $parent && function_exists( '_wp_rml_root' ) ) {
			$parent = _wp_rml_root();
		}

		$created = wp_rml_create_p( $path, $parent, defined( 'RML_TYPE_FOLDER' ) ? RML_TYPE_FOLDER : 0 );
		if ( is_int( $created ) && $created > 0 ) {
			update_user_meta( $user_id, 'koopo_bbmu_rml_user_folder_id', $created );
			return $created;
		}

		return 0;
	}

	private function is_rml_enabled() {
		$enabled = (bool) get_option( 'koopo_bbmu_rml_enabled', false );
		if ( ! $enabled ) {
			return false;
		}

		return function_exists( 'wp_rml_move' );
	}

	private function get_default_rml_folder_map() {
		return array();
	}

	private function get_rml_folder_map() {
		$value = get_option( 'koopo_bbmu_rml_folder_map', $this->get_default_rml_folder_map() );
		return is_array( $value ) ? $value : $this->get_default_rml_folder_map();
	}

	private function get_rml_folder_create() {
		$value = get_option( 'koopo_bbmu_rml_folder_create', array() );
		return is_array( $value ) ? $value : array();
	}

	private function get_default_delete_policy() {
		return array();
	}

	private function get_delete_policy() {
		$value = get_option( 'koopo_bbmu_offload_delete_policy', $this->get_default_delete_policy() );
		return is_array( $value ) ? $value : $this->get_default_delete_policy();
	}

	private function get_default_delete_extensions() {
		return array(
			'photos'    => '',
			'video'     => '',
			'audio'     => '',
			'documents' => '',
		);
	}

	private function get_delete_extensions() {
		$value = get_option( 'koopo_bbmu_offload_delete_extensions', $this->get_default_delete_extensions() );
		return is_array( $value ) ? $value : $this->get_default_delete_extensions();
	}

}

new Koopo_BuddyBoss_Media_UX();
