<?php
/**
 * Plugin Name: Koopo BuddyBoss Media UX
 * Description: Improves BuddyBoss avatar/cover UX with on-page modals and modern cropping, plus extensible media features.
 * Version: 0.4.1
 * Author: Koopo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KOOPO_BBMU_VERSION', '0.4.1' );
define( 'KOOPO_BBMU_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOOPO_BBMU_URL', plugin_dir_url( __FILE__ ) );

class Koopo_BuddyBoss_Media_UX {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_offload_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_offload_menu' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_koopo_bbmu_prepare_avatar_from_media', array( $this, 'ajax_prepare_avatar_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_set_cover_from_media', array( $this, 'ajax_set_cover_from_media' ) );
		add_action( 'wp_ajax_koopo_bbmu_list_user_media', array( $this, 'ajax_list_user_media' ) );

		add_action( 'bb_media_upload', array( $this, 'handle_media_upload' ) );
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
	}

	public function render_offload_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Koopo Media Offload', 'koopo' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'koopo_bbmu_offload' );
				do_settings_sections( 'koopo-bbmu-offload' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_offload_section() {
		echo '<p>' . esc_html__( 'Configure external media URLs for CDN or object storage. The plugin does not upload files; it only rewrites URLs and exposes hooks for offload adapters.', 'koopo' ) . '</p>';
		echo '<p>' . esc_html__( 'Use folder templates to organize media by post type and media type. Available tokens: {post_type}, {media_type}, {year}, {month}, {day}, {filename}.', 'koopo' ) . '</p>';
	}

	public function render_offload_enabled_field() {
		$enabled = (bool) get_option( 'koopo_bbmu_offload_enabled', false );
		?>
		<label>
			<input type="checkbox" name="koopo_bbmu_offload_enabled" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Rewrite media URLs to the base URL', 'koopo' ); ?>
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

	public function render_offload_media_types_field() {
		$values = $this->get_offload_media_types();
		$types  = $this->get_media_type_labels();
		foreach ( $types as $key => $label ) {
			$checked = ! empty( $values[ $key ] );
			?>
			<label style="display:block;">
				<input type="checkbox" name="koopo_bbmu_offload_media_types[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
				<?php echo esc_html( $label ); ?>
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
			<label style="display:block;">
				<input type="checkbox" name="koopo_bbmu_offload_post_types[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
				<?php echo esc_html( $label ); ?>
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

	public function handle_media_upload( $attachment ) {
		if ( empty( $attachment ) || empty( $attachment->ID ) ) {
			return;
		}

		$attachment_id = (int) $attachment->ID;
		$media_id      = (int) get_post_meta( $attachment_id, 'bp_media_id', true );

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
			)
		);
	}

	public function filter_attachment_url( $url, $post_id ) {
		if ( ! $this->is_offload_enabled() || 'all' !== $this->get_offload_scope() ) {
			return $url;
		}

		if ( ! $this->should_offload_attachment( $post_id ) ) {
			return $url;
		}

		$offload_url = $this->build_offload_url( $post_id, 'full' );
		return $offload_url ? $offload_url : $url;
	}

	public function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! $this->is_offload_enabled() || 'all' !== $this->get_offload_scope() ) {
			return $image;
		}

		if ( ! $this->should_offload_attachment( $attachment_id ) ) {
			return $image;
		}

		$offload_url = $this->build_offload_url( $attachment_id, $size );
		if ( $offload_url && is_array( $image ) ) {
			$image[0] = $offload_url;
		}

		return $image;
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

	private function get_attachment_context( $attachment_id ) {
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

}

new Koopo_BuddyBoss_Media_UX();
