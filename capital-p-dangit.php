<?php
/**
 * Plugin Name: Capital P Dangit
 * Description: Fixes a completed Gutenberg paragraph's final typed wordpress to WordPress through Gutenberg RTC.
 * Version: 0.1.0
 * Author: Alex Kirk
 * Text Domain: capital-p-dangit
 *
 * @package Capital_P_Dangit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CPDANGIT_OPTION_BOT_USER_ID = 'cpdangit_bot_user_id';
const CPDANGIT_BOT_CLOCK_META_KEY = '_cpdangit_bot_clock';
const CPDANGIT_ROOM_STATE_META_KEY = '_cpdangit_room_state';
const CPDANGIT_AWARENESS_NUDGE_TTL = 20;
const CPDANGIT_ROOM_STATE_SCHEMA_VERSION = 4;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/gutenberg-yjs-update-v2.php';
require_once __DIR__ . '/includes/gutenberg-rtc-paragraphs.php';
require_once __DIR__ . '/includes/gutenberg-rtc-debug-log.php';

add_action( 'admin_init', 'cpdangit_register_settings' );
add_action( 'admin_menu', 'cpdangit_register_settings_page' );
add_filter( 'rest_pre_dispatch', 'cpdangit_log_wp_sync_requests', 10, 3 );
add_filter( 'rest_post_dispatch', 'cpdangit_respond_to_wp_sync_requests', 10, 3 );

/**
 * Registers Capital P Dangit settings.
 */
function cpdangit_register_settings(): void {
	register_setting(
		'cpdangit',
		CPDANGIT_OPTION_BOT_USER_ID,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_section(
		'cpdangit_bot_section',
		__( 'Bot identity', 'capital-p-dangit' ),
		'__return_null',
		'cpdangit'
	);

	add_settings_field(
		CPDANGIT_OPTION_BOT_USER_ID,
		__( 'Bot user', 'capital-p-dangit' ),
		'cpdangit_render_bot_user_field',
		'cpdangit',
		'cpdangit_bot_section'
	);
}

/**
 * Registers the Capital P Dangit settings page.
 */
function cpdangit_register_settings_page(): void {
	add_options_page(
		__( 'Capital P Dangit', 'capital-p-dangit' ),
		__( 'Capital P Dangit', 'capital-p-dangit' ),
		'manage_options',
		'cpdangit',
		'cpdangit_render_settings_page'
	);
}

/**
 * Renders the bot user setting.
 */
function cpdangit_render_bot_user_field(): void {
	wp_dropdown_users(
		array(
			'name'              => CPDANGIT_OPTION_BOT_USER_ID,
			'id'                => CPDANGIT_OPTION_BOT_USER_ID,
			'selected'          => cpdangit_get_bot_user_id(),
			'show_option_none'  => __( 'Select a user', 'capital-p-dangit' ),
			'option_none_value' => 0,
			'role__in'          => array( 'administrator', 'editor', 'author', 'contributor' ),
		)
	);
}

/**
 * Renders the settings page.
 */
function cpdangit_render_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Choose the WordPress user Capital P Dangit should use when emitting PHP-generated Gutenberg RTC updates and awareness.', 'capital-p-dangit' ); ?></p>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'cpdangit' );
			do_settings_sections( 'cpdangit' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Gets the configured bot user ID.
 */
function cpdangit_get_bot_user_id(): int {
	return absint( get_option( CPDANGIT_OPTION_BOT_USER_ID, 0 ) );
}

/**
 * Gets the stable RTC client ID used by Capital P Dangit for a bot user.
 */
function cpdangit_get_bot_client_id( int $bot_user_id ): int {
	return abs( crc32( 'capital-p-dangit-bot-' . $bot_user_id ) );
}

/**
 * Emits the configured bot user's awareness state into the sync room.
 *
 * This is PHP-only RTC awareness. It does not enqueue browser JavaScript.
 *
 * @param int    $post_id      Post ID.
 * @param string $room         Sync room.
 * @param string $changed_text Last bot-authored text, if any.
 * @return true|WP_Error
 */
function cpdangit_emit_bot_awareness( int $post_id, string $room, string $changed_text ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'cpdangit_missing_room', __( 'Missing Capital P Dangit room.', 'capital-p-dangit' ) );
	}

	$bot_user_id = cpdangit_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'cpdangit_missing_bot_user', __( 'No Capital P Dangit bot user is configured.', 'capital-p-dangit' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'cpdangit_bot_cannot_edit', __( 'The configured Capital P Dangit bot user cannot edit this post.', 'capital-p-dangit' ) );
	}

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => array(
						'collaboratorInfo' => array(
							'avatar_urls'  => rest_get_avatar_urls( $bot_user->user_email ),
							'browserType'  => 'Capital P Dangit',
							'enteredAt'    => (int) floor( microtime( true ) * 1000 ),
							'id'           => $bot_user->ID,
							'name'         => $bot_user->display_name,
							'slug'         => $bot_user->user_nicename,
						),
						'editorState'      => array(
							'selection' => array(
								'type' => 'none',
							),
						),
						'cpdangitState'     => array(
							'postId'      => $post_id,
							'changedText' => $changed_text,
						),
					),
					'client_id' => cpdangit_get_bot_client_id( $bot_user_id ),
					'room'      => $room,
					'updates'   => array(),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	return true;
}

/**
 * Emits bot awareness for solo post-room polls so Gutenberg resumes its update queue.
 */
function cpdangit_maybe_emit_bot_awareness_nudge( int $post_id, string $room, int $client_id, array $updates ): void {
	unset( $updates );

	if ( ! $post_id || '' === $room ) {
		return;
	}

	$bot_user_id = cpdangit_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return;
	}

	$bot_client_id = cpdangit_get_bot_client_id( $bot_user_id );
	if ( $client_id === $bot_client_id ) {
		return;
	}

	$transient_key = 'cpdangit_awareness_nudge_' . md5( $room );
	if ( get_transient( $transient_key ) ) {
		return;
	}

	set_transient( $transient_key, time(), CPDANGIT_AWARENESS_NUDGE_TTL );

	$result = cpdangit_emit_bot_awareness( $post_id, $room, '' );
	cpdangit_log(
		'bot-rtc-awareness-nudge',
		is_wp_error( $result )
			? array(
				'ok'        => false,
				'room'      => $room,
				'post_id'   => $post_id,
				'client_id' => $client_id,
				'code'      => $result->get_error_code(),
				'message'   => $result->get_error_message(),
			)
			: array(
				'ok'            => true,
				'room'          => $room,
				'post_id'       => $post_id,
				'client_id'     => $client_id,
				'bot_client_id' => $bot_client_id,
			)
	);
}

/**
 * Emits a bot-authored last-word replacement into a Gutenberg sync room.
 *
 * @param int                              $post_id     Post ID.
 * @param string                           $room        Sync room.
 * @param string                           $replacement Replacement word.
 * @param Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph $paragraph  Completed paragraph event.
 * @return array<string, mixed>|WP_Error
 */
function cpdangit_emit_bot_last_word_replacement( int $post_id, string $room, string $replacement, Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph $paragraph ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'cpdangit_missing_room', __( 'Missing Capital P Dangit room.', 'capital-p-dangit' ) );
	}

	if ( '' === $replacement ) {
		return new WP_Error( 'cpdangit_missing_text', __( 'Missing replacement text.', 'capital-p-dangit' ) );
	}

	$bot_user_id = cpdangit_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'cpdangit_missing_bot_user', __( 'No Capital P Dangit bot user is configured.', 'capital-p-dangit' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'cpdangit_bot_cannot_edit', __( 'The configured Capital P Dangit bot user cannot edit this post.', 'capital-p-dangit' ) );
	}

	$bot_client_id = cpdangit_get_bot_client_id( $bot_user_id );
	$start_clock   = cpdangit_get_bot_clock( $post_id, $bot_client_id );
	$state         = cpdangit_get_room_state( $post_id );
	$replacement_update = cpdangit_gutenberg_rtc_build_last_word_replacement(
		$state,
		$paragraph,
		$replacement,
		$bot_client_id,
		$start_clock
	);
	if ( ! $replacement_update ) {
		return new WP_Error( 'cpdangit_no_replacement', __( 'Could not build a last-word replacement for this paragraph.', 'capital-p-dangit' ) );
	}
	$update = $replacement_update['update'];

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => cpdangit_build_bot_selection_awareness(
						$bot_user,
						$post_id,
						$replacement,
						$replacement_update['selection']
					),
					'client_id' => $bot_client_id,
					'room'      => $room,
					'updates'   => array(
						array(
							'type' => 'update',
							'data' => base64_encode( $update ),
						),
					),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	try {
		$decoded = cpdangit_gutenberg_yjs_decode_update_v2( $update );
		cpdangit_gutenberg_rtc_apply_decoded_update_to_paragraph_state( $state, $decoded );
		$state['blocks'][ $paragraph->source_block_id() ]['content'] = cpdangit_replace_last_word( $paragraph->text(), $replacement );
		cpdangit_set_room_state( $post_id, $state );
	} catch ( RuntimeException $exception ) {
		cpdangit_log(
			'bot-rtc-state-apply-error',
			array(
				'room'    => $room,
				'message' => $exception->getMessage(),
			)
		);
	}

	cpdangit_set_bot_clock( $post_id, $bot_client_id, (int) $replacement_update['next_clock'] );

	return array(
		'ok'               => true,
		'bot_client_id'    => $bot_client_id,
		'start_clock'      => $start_clock,
		'next_clock'       => (int) $replacement_update['next_clock'],
		'update_bytes'     => strlen( $update ),
		'original_word'    => $replacement_update['original_word'],
		'replacement'      => $replacement,
		'selection'        => $replacement_update['selection'],
		'origin'           => $replacement_update['origin'],
		'right_origin'     => $replacement_update['right_origin'],
		'delete_ranges'    => $replacement_update['delete_ranges'],
		'response_status'  => $response->get_status(),
		'response_payload' => $response->get_data(),
	);
}

/**
 * Builds bot awareness for a text selection.
 *
 * @param array<string, mixed> $selection Selection metadata from the RTC builder.
 */
function cpdangit_build_bot_selection_awareness( WP_User $bot_user, int $post_id, string $changed_text, array $selection ): array {
	$type         = isset( $selection['type'] ) && is_array( $selection['type'] ) ? $selection['type'] : array();
	$start_item   = isset( $selection['start_item'] ) && is_array( $selection['start_item'] ) ? $selection['start_item'] : null;
	$end_item     = isset( $selection['end_item'] ) && is_array( $selection['end_item'] ) ? $selection['end_item'] : null;
	$start_offset = isset( $selection['start_offset'] ) ? (int) $selection['start_offset'] : 0;
	$end_offset   = isset( $selection['end_offset'] ) ? (int) $selection['end_offset'] : $start_offset;

	return cpdangit_build_bot_awareness_with_selection(
		$bot_user,
		$post_id,
		$changed_text,
		array(
			'type'                => 'selection-in-one-block',
			'cursorStartPosition' => cpdangit_build_bot_cursor_position( $type, $start_item, $start_offset ),
			'cursorEndPosition'   => cpdangit_build_bot_cursor_position( $type, $end_item, $end_offset ),
			'selectionDirection'  => 'f',
		)
	);
}

/**
 * Builds bot awareness with an editor selection payload.
 */
function cpdangit_build_bot_awareness_with_selection( WP_User $bot_user, int $post_id, string $changed_text, array $selection ): array {
	return array(
		'collaboratorInfo' => array(
			'avatar_urls' => rest_get_avatar_urls( $bot_user->user_email ),
			'browserType' => 'Capital P Dangit',
			'enteredAt'   => (int) floor( microtime( true ) * 1000 ),
			'id'          => $bot_user->ID,
			'name'        => $bot_user->display_name,
			'slug'        => $bot_user->user_nicename,
		),
		'editorState'      => array(
			'selection' => $selection,
		),
		'cpdangitState'     => array(
			'postId'      => $post_id,
			'changedText' => $changed_text,
		),
	);
}

/**
 * Builds a Gutenberg cursor position payload from Yjs IDs.
 */
function cpdangit_build_bot_cursor_position( array $type, ?array $item, int $absolute_offset ): array {
	return array(
		'relativePosition' => array(
			'type'  => array(
				'client' => (int) ( $type['client'] ?? 0 ),
				'clock'  => (int) ( $type['clock'] ?? 0 ),
			),
			'tname' => null,
			'item'  => $item
				? array(
					'client' => (int) ( $item['client'] ?? 0 ),
					'clock'  => (int) ( $item['clock'] ?? 0 ),
				)
				: null,
			'assoc' => 0,
		),
		'absoluteOffset'   => $absolute_offset,
		'attributeKey'     => 'content',
	);
}

/**
 * Gets the next bot client clock for a post.
 */
function cpdangit_get_bot_clock( int $post_id, int $bot_client_id ): int {
	$clocks = get_post_meta( $post_id, CPDANGIT_BOT_CLOCK_META_KEY, true );
	if ( ! is_array( $clocks ) ) {
		return 0;
	}

	return isset( $clocks[ (string) $bot_client_id ] ) ? max( 0, (int) $clocks[ (string) $bot_client_id ] ) : 0;
}

/**
 * Stores the next bot client clock for a post.
 */
function cpdangit_set_bot_clock( int $post_id, int $bot_client_id, int $clock ): void {
	$clocks = get_post_meta( $post_id, CPDANGIT_BOT_CLOCK_META_KEY, true );
	if ( ! is_array( $clocks ) ) {
		$clocks = array();
	}

	$clocks[ (string) $bot_client_id ] = max( 0, $clock );
	update_post_meta( $post_id, CPDANGIT_BOT_CLOCK_META_KEY, $clocks );
}

/**
 * Gets the final word in paragraph text.
 */
function cpdangit_get_last_word( string $text ): string {
	if ( ! preg_match( '/([\p{L}\p{N}_]+)([^\p{L}\p{N}_]*\s*)$/u', $text, $matches ) ) {
		return '';
	}

	return (string) $matches[1];
}

/**
 * Replaces the final word in paragraph text.
 */
function cpdangit_replace_last_word( string $text, string $replacement ): string {
	return preg_replace( '/([\p{L}\p{N}_]+)([^\p{L}\p{N}_]*\s*)$/u', $replacement . '$2', $text, 1 ) ?? $text;
}

/**
 * Gets the replacement produced by capital_P_dangit() for a paragraph's final word.
 */
function cpdangit_get_wordpress_replacement( string $paragraph_text, string $word ): string {
	if ( '' === $word ) {
		return '';
	}

	if ( function_exists( 'capital_P_dangit' ) ) {
		$fixed_text = capital_P_dangit( $paragraph_text );
		if ( $fixed_text !== $paragraph_text ) {
			$fixed_word = cpdangit_get_last_word( $fixed_text );
			if ( '' !== $fixed_word && $fixed_word !== $word ) {
				return $fixed_word;
			}
		}
	}

	return 'wordpress' === strtolower( $word ) && 'WordPress' !== $word ? 'WordPress' : '';
}

/**
 * Passively logs real Gutenberg sync requests without intercepting them.
 *
 * @param mixed           $result  Response to replace requested version with.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Unchanged response.
 */
function cpdangit_log_wp_sync_requests( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $result;
	}

	$rooms = cpdangit_gutenberg_rtc_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		cpdangit_log(
			'wp-sync-request',
			array(
				'route'        => $request->get_route(),
				'method'       => $request->get_method(),
				'content_type' => $request->get_header( 'content-type' ),
				'body_length'  => strlen( (string) $request->get_body() ),
				'error'        => 'missing_rooms',
			)
		);
		return $result;
	}

	cpdangit_log(
		'wp-sync-request',
		array(
			'route'        => $request->get_route(),
			'method'       => $request->get_method(),
			'content_type' => $request->get_header( 'content-type' ),
			'body_length'  => strlen( (string) $request->get_body() ),
			'room_count'   => count( $rooms ),
			'rooms'        => cpdangit_gutenberg_rtc_summarize_rooms( $rooms ),
		)
	);

	cpdangit_maybe_emit_bot_awareness_nudges_for_rooms( $rooms );
	cpdangit_gutenberg_rtc_decode_rooms_for_logging( $rooms, 'cpdangit_log' );

	return $result;
}

/**
 * Emits immediate bot awareness for post rooms in a sync payload.
 *
 * @param array<int, mixed> $rooms Rooms payload.
 */
function cpdangit_maybe_emit_bot_awareness_nudges_for_rooms( array $rooms ): void {
	$bot_user_id = cpdangit_get_bot_user_id();
	$bot_client  = $bot_user_id ? cpdangit_get_bot_client_id( $bot_user_id ) : 0;

	foreach ( $rooms as $room_request ) {
		if ( ! is_array( $room_request ) ) {
			continue;
		}

		$client_id = isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0;
		if ( $bot_client && $client_id === $bot_client ) {
			continue;
		}

		$room    = isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '';
		$post_id = cpdangit_get_post_id_from_room( $room );
		$updates = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();

		if ( $post_id ) {
			cpdangit_maybe_emit_bot_awareness_nudge( $post_id, $room, $client_id, $updates );
		}
	}
}

/**
 * Responds to accepted Gutenberg sync updates with PHP-generated bot RTC updates.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param WP_REST_Server                                   $server   Server instance.
 * @param WP_REST_Request                                  $request  Request.
 * @return mixed Unchanged response.
 */
function cpdangit_respond_to_wp_sync_requests( $response, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $response;
	}

	$rooms = cpdangit_gutenberg_rtc_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		return $response;
	}

	$bot_user_id = cpdangit_get_bot_user_id();
	$bot_client  = $bot_user_id ? cpdangit_get_bot_client_id( $bot_user_id ) : 0;

	foreach ( $rooms as $room_request ) {
		if ( ! is_array( $room_request ) ) {
			continue;
		}

		$client_id = isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0;
		if ( $bot_client && $client_id === $bot_client ) {
			continue;
		}

		$room    = isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '';
		$post_id = cpdangit_get_post_id_from_room( $room );
		$updates = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();

		if ( ! $post_id || empty( $updates ) ) {
			continue;
		}

		$state    = cpdangit_get_room_state( $post_id );
		$paragraphs = cpdangit_gutenberg_rtc_apply_paragraph_updates(
			$state,
			$updates,
			static function ( RuntimeException $exception ) use ( $room ): void {
				cpdangit_log(
					'bot-rtc-decode-error',
					array(
						'room'    => $room,
						'message' => $exception->getMessage(),
					)
				);
			}
		);

		cpdangit_set_room_state( $post_id, $state );

		cpdangit_replace_last_word_in_completed_paragraphs( $post_id, $room, $state, $paragraphs );
	}

	return $response;
}

/**
 * Applies capital_P_dangit() to completed paragraph events.
 *
 * @param array<string, mixed>                      $state      Current paragraph document state.
 * @param array<int, Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph> $paragraphs Completed paragraph events.
 */
function cpdangit_replace_last_word_in_completed_paragraphs( int $post_id, string $room, array &$state, array $paragraphs ): void {
	foreach ( $paragraphs as $paragraph ) {
		if ( ! $paragraph instanceof Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph ) {
			continue;
		}

		$dedupe_key = $paragraph->dedupe_key();
		if ( isset( $state['processed'][ $dedupe_key ] ) ) {
			continue;
		}

		$state['processed'][ $dedupe_key ] = time();
		cpdangit_set_room_state( $post_id, $state );

		$last_word   = cpdangit_get_last_word( $paragraph->text() );
		$replacement = cpdangit_get_wordpress_replacement( $paragraph->text(), $last_word );

		if ( '' === $replacement ) {
			cpdangit_log(
				'bot-rtc-skip',
				array(
					'room'      => $room,
					'post_id'   => $post_id,
					'last_word' => $last_word,
					'reason'    => 'no_capital_p_dangit_replacement',
				)
			);
			continue;
		}

		$result = cpdangit_emit_bot_last_word_replacement(
			$post_id,
			$room,
			$replacement,
			$paragraph
		);

		cpdangit_log(
			'bot-rtc-auto-capital-p-dangit',
			is_wp_error( $result )
				? array(
					'ok'      => false,
					'room'    => $room,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: array_merge( array( 'room' => $room ), $result )
		);
	}
}

/**
 * Extracts a post ID from a Gutenberg post sync room.
 */
function cpdangit_get_post_id_from_room( string $room ): int {
	if ( preg_match( '/^postType\/post:(\d+)$/', $room, $matches ) ) {
		return (int) $matches[1];
	}

	return 0;
}

/**
 * Gets Capital P Dangit's lightweight CRDT room state.
 *
 * @return array<string, mixed>
 */
function cpdangit_get_room_state( int $post_id ): array {
	$state = get_post_meta( $post_id, CPDANGIT_ROOM_STATE_META_KEY, true );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	if ( (int) ( $state['schema_version'] ?? 0 ) !== CPDANGIT_ROOM_STATE_SCHEMA_VERSION ) {
		$state = array();
	}

	return array_merge(
		cpdangit_gutenberg_rtc_empty_paragraph_document_state( CPDANGIT_ROOM_STATE_SCHEMA_VERSION ),
		$state
	);
}

/**
 * Stores Capital P Dangit's lightweight CRDT room state.
 */
function cpdangit_set_room_state( int $post_id, array $state ): void {
	update_post_meta( $post_id, CPDANGIT_ROOM_STATE_META_KEY, $state );
}

/**
 * Logs a probe event.
 *
 * @param string $event Event name.
 * @param mixed  $data  Event payload.
 */
function cpdangit_log( string $event, $data ): void {
	$message = '[Capital P Dangit] ' . gmdate( 'c' ) . ' ' . $event . ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
