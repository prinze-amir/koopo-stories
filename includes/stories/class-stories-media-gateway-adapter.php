<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stories control-plane adapter for gateway-owned media.
 *
 * Binary bytes never enter WordPress. This adapter only creates scoped upload
 * sessions and projects completed remote assets into the Stories data model.
 */
class Koopo_Stories_Media_Gateway_Adapter implements Koopo_Media_Gateway_Adapter {
	const MODULE_KEY   = 'stories';
	const REST_NS      = 'koopo/v1';
	const RELEASE_HOOK = 'koopo_stories_release_gateway_reference';
	const STATUS_HOOK  = 'koopo_stories_refresh_gateway_asset';

	private $coordinator;
	private $policy;

	public function __construct( $coordinator, $policy ) {
		$this->coordinator = $coordinator;
		$this->policy      = $policy;
	}

	public function key() {
		return self::MODULE_KEY;
	}

	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'koopo_stories_direct_upload_config', array( $this, 'frontend_config' ) );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 5 );
		add_action( self::RELEASE_HOOK, array( $this, 'release_reference' ), 10, 4 );
		add_action( self::STATUS_HOOK, array( $this, 'refresh_processing_asset' ), 10, 4 );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_image_src' ), PHP_INT_MAX, 4 );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/stories/upload-sessions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'require_user' ),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/stories/upload-sessions/(?P<id>[a-f0-9-]{36})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_session' ),
					'permission_callback' => array( $this, 'require_user' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'cancel_session' ),
					'permission_callback' => array( $this, 'require_user' ),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/stories/upload-sessions/(?P<id>[a-f0-9-]{36})/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_session' ),
				'permission_callback' => array( $this, 'require_user' ),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/stories/upload-sessions/(?P<id>[a-f0-9-]{36})/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_session' ),
				'permission_callback' => array( $this, 'require_user' ),
			)
		);
	}

	public function require_user() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'koopo_stories_auth_required', __( 'Authentication is required.', 'koopo-stories' ), array( 'status' => 401 ) );
		}
		if ( ! $this->policy->is_module_enabled( self::MODULE_KEY ) ) {
			return new WP_Error( 'koopo_stories_direct_upload_disabled', __( 'Direct Story uploads are disabled.', 'koopo-stories' ), array( 'status' => 503 ) );
		}
		return true;
	}

	public function create_session( WP_REST_Request $request ) {
		$upload_error = Koopo_Stories_Utils::ensure_can_upload();
		if ( $upload_error ) {
			return $upload_error;
		}
		$limit_error = Koopo_Stories_Utils::enforce_daily_upload_limit( get_current_user_id() );
		if ( $limit_error ) {
			return $limit_error;
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$kind = sanitize_key( (string) ( $body['kind'] ?? '' ) );
		$mime = sanitize_mime_type( (string) ( $body['mimeType'] ?? '' ) );
		if ( ! in_array( $kind, array( 'image', 'video' ), true ) || 0 !== strpos( $mime, $kind . '/' ) ) {
			return new WP_Error( 'koopo_stories_invalid_media', __( 'Stories accept image or video media only.', 'koopo-stories' ), array( 'status' => 422 ) );
		}
		if ( ! in_array( $mime, Koopo_Stories_Utils::get_allowed_upload_mimes(), true ) ) {
			return new WP_Error( 'koopo_stories_invalid_file_type', __( 'This Story file type is not allowed.', 'koopo-stories' ), array( 'status' => 422 ) );
		}
		$size_bytes = absint( $body['sizeBytes'] ?? 0 );
		if ( $size_bytes <= 0 || $size_bytes > Koopo_Stories_Utils::get_direct_max_upload_size_bytes() ) {
			return new WP_Error( 'koopo_stories_file_too_large', __( 'This Story file exceeds the configured size limit.', 'koopo-stories' ), array( 'status' => 413 ) );
		}
		if ( ! $this->policy->supports_media_kind( self::MODULE_KEY, $kind ) ) {
			return new WP_Error( 'koopo_stories_media_kind_disabled', __( 'This Story media type is disabled.', 'koopo-stories' ), array( 'status' => 422 ) );
		}

		$body['kind']    = $kind;
		$body['context'] = 'story';
		$key             = sanitize_text_field( (string) ( $request->get_header( 'idempotency-key' ) ?: ( $body['clientRequestId'] ?? '' ) ) );
		return $this->gateway_response( $this->coordinator->create_session( get_current_user_id(), $body, $key ) );
	}

	public function get_session( WP_REST_Request $request ) {
		return $this->gateway_response( $this->coordinator->get_session( get_current_user_id(), $request['id'] ) );
	}

	public function refresh_session( WP_REST_Request $request ) {
		return $this->gateway_response( $this->coordinator->refresh_session( get_current_user_id(), $request['id'] ) );
	}

	public function cancel_session( WP_REST_Request $request ) {
		return $this->gateway_response( $this->coordinator->cancel_session( get_current_user_id(), $request['id'] ) );
	}

	public function complete_session( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$result  = $this->coordinator->complete_session( $user_id, $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload  = is_array( $result['body'] ?? null ) ? $result['body'] : array();
		$asset    = is_array( $payload['asset'] ?? null ) ? $payload['asset'] : array();
		$metadata = is_array( $asset['metadata'] ?? null ) ? $asset['metadata'] : array();
		$kind     = sanitize_key( (string) ( $asset['kind'] ?? '' ) );
		$status   = sanitize_key( (string) ( $asset['status'] ?? '' ) );
		if ( ! in_array( $kind, array( 'image', 'video' ), true ) || 'story' !== sanitize_key( (string) ( $metadata['context'] ?? '' ) ) ) {
			return new WP_Error( 'koopo_stories_asset_mismatch', __( 'This upload session does not belong to Stories.', 'koopo-stories' ), array( 'status' => 409 ) );
		}
		$playback_url = $this->playback_url( $asset );
		if ( ( 'image' === $kind && 'ready' !== $status ) || ( 'video' === $kind && ! in_array( $status, array( 'ready', 'processing' ), true ) ) || ! $playback_url ) {
			return new WP_Error( 'koopo_stories_asset_not_ready', __( 'The Story media is not ready for playback.', 'koopo-stories' ), array( 'status' => 409 ) );
		}

		$limit_error = Koopo_Stories_Utils::enforce_daily_upload_limit( $user_id );
		if ( $limit_error ) {
			return $limit_error;
		}

		$attachment_id = $this->project_attachment( $asset, $user_id, $playback_url );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_post_meta( $attachment_id, '_koopo_media_upload_session_id', sanitize_text_field( (string) $request['id'] ) );

		if ( ! get_post_meta( $attachment_id, '_koopo_media_reference_registered', true ) ) {
			$reference = $this->coordinator->add_reference( $user_id, $asset['id'], 'wp_attachment', $attachment_id );
			if ( is_wp_error( $reference ) ) {
				wp_delete_attachment( $attachment_id, true );
				return $reference;
			}
			update_post_meta( $attachment_id, '_koopo_media_reference_registered', 1 );
		}

		$finalize_request = new WP_REST_Request( 'POST', '/koopo/v1/stories' );
		$body             = $request->get_json_params();
		$body             = is_array( $body ) ? $body : array();
		$finalize_request->set_param( 'privacy', $body['privacy'] ?? null );
		$finalize_request->set_param( 'stickers', $body['stickers'] ?? array() );
		$response = Koopo_Stories_REST_Story::finalize_story_attachment(
			$finalize_request,
			$attachment_id,
			$kind,
			$user_id,
			array(
				'upload_session_id' => (string) $request['id'],
				'asset_id'          => (string) ( $asset['id'] ?? '' ),
			)
		);
		if ( ! $response instanceof WP_REST_Response || $response->get_status() >= 400 ) {
			wp_delete_attachment( $attachment_id, true );
			return $response;
		}

		$data = (array) $response->get_data();
		$data['asset'] = $asset;
		$data['session'] = $payload['session'] ?? null;
		$data['wordpress_attachment_id'] = $attachment_id;
		if ( 'video' === $kind && 'processing' === $status ) {
			$this->schedule_status_refresh( (string) $request['id'], $attachment_id, $user_id, 1 );
		}
		return new WP_REST_Response( $data, 200 );
	}

	public function frontend_config( $config ) {
		if ( ! is_user_logged_in() ) {
			return $config;
		}
		return array(
			'enabled' => true,
			'restUrl' => esc_url_raw( rest_url( self::REST_NS . '/stories/upload-sessions' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'maxBytes' => Koopo_Stories_Utils::get_direct_max_upload_size_bytes(),
		);
	}

	public function filter_attachment_url( $url, $attachment_id ) {
		$remote = esc_url_raw( (string) get_post_meta( $attachment_id, '_koopo_media_delivery_url', true ) );
		return (int) get_post_meta( $attachment_id, '_koopo_media_remote_only', true ) && $remote ? $remote : $url;
	}

	public function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		$remote = $this->filter_attachment_url( '', $attachment_id );
		if ( ! $remote || ! wp_attachment_is_image( $attachment_id ) ) {
			return $image;
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		return array(
			$remote,
			absint( $metadata['width'] ?? 0 ),
			absint( $metadata['height'] ?? 0 ),
			false,
		);
	}

	public function on_delete_attachment( $attachment_id ) {
		if ( 'story' !== get_post_meta( $attachment_id, '_koopo_media_context', true ) ) {
			return;
		}
		$asset_id = sanitize_text_field( (string) get_post_meta( $attachment_id, '_koopo_media_asset_id', true ) );
		$owner_id = absint( get_post_field( 'post_author', $attachment_id ) );
		if ( $asset_id && $owner_id && get_post_meta( $attachment_id, '_koopo_media_reference_registered', true ) ) {
			$this->release_reference( $asset_id, absint( $attachment_id ), $owner_id, 1 );
		}
	}

	public function release_reference( $asset_id, $attachment_id, $owner_id, $attempt = 1 ) {
		$result = $this->coordinator->release_reference( $owner_id, $asset_id, 'wp_attachment', $attachment_id );
		if ( ! is_wp_error( $result ) ) {
			delete_post_meta( $attachment_id, '_koopo_media_reference_registered' );
			return;
		}
		$attempt = max( 1, absint( $attempt ) );
		if ( $attempt >= 5 ) {
			return;
		}
		$args = array( sanitize_text_field( $asset_id ), absint( $attachment_id ), absint( $owner_id ), $attempt + 1 );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + ( 30 * $attempt ), self::RELEASE_HOOK, $args, 'koopo-media-gateway', true );
		} elseif ( ! wp_next_scheduled( self::RELEASE_HOOK, $args ) ) {
			wp_schedule_single_event( time() + ( 30 * $attempt ), self::RELEASE_HOOK, $args );
		}
	}

	public function refresh_processing_asset( $session_id, $attachment_id, $owner_id, $attempt = 1 ) {
		$attachment_id = absint( $attachment_id );
		$owner_id      = absint( $owner_id );
		$attempt       = max( 1, absint( $attempt ) );
		if (
			! $attachment_id
			|| 'story' !== get_post_meta( $attachment_id, '_koopo_media_context', true )
			|| (string) get_post_meta( $attachment_id, '_koopo_media_upload_session_id', true ) !== (string) $session_id
		) {
			return;
		}

		$result = $this->coordinator->get_session( $owner_id, $session_id );
		if ( is_wp_error( $result ) ) {
			if ( $attempt < 10 ) {
				$this->schedule_status_refresh( $session_id, $attachment_id, $owner_id, $attempt + 1 );
			}
			return;
		}
		$asset = is_array( $result['body']['asset'] ?? null ) ? $result['body']['asset'] : array();
		if ( (string) ( $asset['id'] ?? '' ) !== (string) get_post_meta( $attachment_id, '_koopo_media_asset_id', true ) ) {
			return;
		}

		$metadata = is_array( $asset['metadata'] ?? null ) ? $asset['metadata'] : array();
		$status   = sanitize_key( (string) ( $asset['status'] ?? '' ) );
		update_post_meta( $attachment_id, '_koopo_media_status', $status );
		update_post_meta( $attachment_id, '_koopo_media_final_delivery_url', esc_url_raw( (string) ( $metadata['hlsUrl'] ?? $asset['deliveryUrl'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_koopo_media_thumbnail_url', esc_url_raw( (string) ( $metadata['thumbnailUrl'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_koopo_media_width', absint( $metadata['width'] ?? 0 ) );
		update_post_meta( $attachment_id, '_koopo_media_height', absint( $metadata['height'] ?? 0 ) );
		if ( in_array( $status, array( 'ready', 'failed' ), true ) ) {
			Koopo_Stories_REST::bump_global_feed_salt();
			Koopo_Stories_REST::bump_user_feed_salt( $owner_id );
			return;
		}
		if ( $attempt < 10 ) {
			$this->schedule_status_refresh( $session_id, $attachment_id, $owner_id, $attempt + 1 );
		}
	}

	private function playback_url( array $asset ) {
		$metadata = is_array( $asset['metadata'] ?? null ) ? $asset['metadata'] : array();
		return esc_url_raw( (string) ( $asset['deliveryUrl'] ?? $metadata['originalUrl'] ?? '' ) );
	}

	private function schedule_status_refresh( $session_id, $attachment_id, $owner_id, $attempt ) {
		$args  = array( sanitize_text_field( (string) $session_id ), absint( $attachment_id ), absint( $owner_id ), absint( $attempt ) );
		$delay = min( 300, 30 * max( 1, absint( $attempt ) ) );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::STATUS_HOOK, $args, 'koopo-media-gateway', true );
		} elseif ( ! wp_next_scheduled( self::STATUS_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::STATUS_HOOK, $args );
		}
	}

	private function project_attachment( array $asset, $user_id, $playback_url ) {
		if ( (string) absint( $user_id ) !== (string) ( $asset['ownerId'] ?? '' ) ) {
			return new WP_Error( 'koopo_stories_asset_forbidden', __( 'You do not own this Story media.', 'koopo-stories' ), array( 'status' => 403 ) );
		}
		$asset_id = sanitize_text_field( (string) ( $asset['id'] ?? '' ) );
		if ( ! $asset_id ) {
			return new WP_Error( 'koopo_stories_invalid_asset', __( 'The gateway asset is missing its identity.', 'koopo-stories' ), array( 'status' => 409 ) );
		}
		$existing = get_posts([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_key'       => '_koopo_media_asset_id',
			'meta_value'     => $asset_id,
		]);
		$filename = sanitize_file_name( (string) ( $asset['originalFilename'] ?? 'story-media' ) );
		$mime     = sanitize_mime_type( (string) ( $asset['mimeType'] ?? '' ) );
		$post_id  = ! empty( $existing ) ? absint( $existing[0] ) : wp_insert_attachment(
			array(
				'guid'           => $playback_url,
				'post_author'    => absint( $user_id ),
				'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_mime_type' => $mime,
				'post_status'    => 'inherit',
			),
			false,
			0,
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$metadata = is_array( $asset['metadata'] ?? null ) ? $asset['metadata'] : array();
		$hls_url  = esc_url_raw( (string) ( $metadata['hlsUrl'] ?? $asset['deliveryUrl'] ?? '' ) );
		update_post_meta( $post_id, '_koopo_media_asset_id', $asset_id );
		update_post_meta( $post_id, '_koopo_media_context', 'story' );
		update_post_meta( $post_id, '_koopo_media_provider', sanitize_key( (string) ( $asset['provider'] ?? '' ) ) );
		update_post_meta( $post_id, '_koopo_media_provider_object_id', sanitize_text_field( (string) ( $asset['providerObjectId'] ?? '' ) ) );
		update_post_meta( $post_id, '_koopo_media_storage_key', sanitize_text_field( (string) ( $asset['storageKey'] ?? '' ) ) );
		update_post_meta( $post_id, '_koopo_media_delivery_url', $playback_url );
		update_post_meta( $post_id, '_koopo_media_final_delivery_url', $hls_url ?: $playback_url );
		update_post_meta( $post_id, '_koopo_media_status', sanitize_key( (string) ( $asset['status'] ?? '' ) ) );
		update_post_meta( $post_id, '_koopo_media_size_bytes', absint( $asset['sizeBytes'] ?? 0 ) );
		update_post_meta( $post_id, '_koopo_media_original_filename', $filename );
		update_post_meta( $post_id, '_koopo_media_remote_only', 1 );
		update_post_meta( $post_id, '_koopo_media_width', absint( $metadata['width'] ?? 0 ) );
		update_post_meta( $post_id, '_koopo_media_height', absint( $metadata['height'] ?? 0 ) );
		update_post_meta( $post_id, '_koopo_media_thumbnail_url', esc_url_raw( (string) ( $metadata['thumbnailUrl'] ?? '' ) ) );
		update_post_meta( $post_id, 'koopo_bbmu_offload_status', 'done' );
		wp_update_attachment_metadata(
			$post_id,
			array(
				'width'  => absint( $metadata['width'] ?? 0 ),
				'height' => absint( $metadata['height'] ?? 0 ),
				'file'   => '',
				'sizes'  => array(),
			)
		);
		clean_post_cache( $post_id );
		return (int) $post_id;
	}

	private function gateway_response( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result['body'], (int) $result['status'] );
	}
}
