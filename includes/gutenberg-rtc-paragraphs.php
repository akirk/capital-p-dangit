<?php
/**
 * Paragraph-focused helpers for Gutenberg RTC/Yjs documents.
 *
 * @package Capital_P_Dangit_Gutenberg_RTC_Paragraphs
 */

/**
 * Paragraph completion event discovered from incoming RTC updates.
 */
class Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph {
	/**
	 * @param string                    $source_block_id Source paragraph block item key.
	 * @param string                    $source_text     Source paragraph text.
	 * @param array{client:int,clock:int} $left_origin   Source block item.
	 * @param array{client:int,clock:int}|null $right_origin Following empty block item, if known.
	 */
	public function __construct(
		private string $source_block_id,
		private string $source_text,
		private array $left_origin,
		private ?array $right_origin
	) {}

	public function source_block_id(): string {
		return $this->source_block_id;
	}

	public function text(): string {
		return $this->source_text;
	}

	/**
	 * @return array{client:int,clock:int}
	 */
	public function left_origin(): array {
		return $this->left_origin;
	}

	/**
	 * @return array{client:int,clock:int}|null
	 */
	public function right_origin(): ?array {
		return $this->right_origin;
	}

	public function dedupe_key(): string {
		return $this->source_block_id . ':' . md5( $this->source_text );
	}
}

/**
 * Gets an empty paragraph document state.
 *
 * @return array<string, mixed>
 */
function cpdangit_gutenberg_rtc_empty_paragraph_document_state( int $schema_version ): array {
	return array(
		'schema_version'      => $schema_version,
		'blocks'              => array(),
		'attributes_to_block' => array(),
		'root_content'        => '',
		'root_content_items'  => array(),
		'root_content_text'   => null,
		'text_to_block'       => array(),
		'text_items_to_block' => array(),
		'processed'           => array(),
	);
}

/**
 * Applies base64 update entries from one Gutenberg RTC room.
 *
 * @param array<string, mixed> $state State, mutated in place.
 * @param array<int, mixed>    $updates Update entries from `/wp-sync/v1/updates`.
 * @param callable|null        $on_decode_error Optional callback receiving RuntimeException.
 * @return array<int, Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph>
 */
function cpdangit_gutenberg_rtc_apply_paragraph_updates( array &$state, array $updates, ?callable $on_decode_error = null ): array {
	$events = array();

	foreach ( $updates as $update ) {
		if ( ! is_array( $update ) || ( $update['type'] ?? '' ) !== 'update' || empty( $update['data'] ) || ! is_string( $update['data'] ) ) {
			continue;
		}

		$binary = base64_decode( $update['data'], true );
		if ( false === $binary ) {
			continue;
		}

		try {
			$decoded = cpdangit_gutenberg_yjs_decode_update_v2( $binary );
		} catch ( RuntimeException $exception ) {
			if ( null !== $on_decode_error ) {
				$on_decode_error( $exception );
			}
			continue;
		}

		$events = array_merge( $events, cpdangit_gutenberg_rtc_apply_decoded_update_to_paragraph_state( $state, $decoded ) );
	}

	return $events;
}

/**
 * Builds update bytes for inserting a new paragraph after a completed paragraph.
 *
 * @param array<string, mixed> $state State used for optional document.content insertion.
 * @return array<string, mixed>
 */
function cpdangit_gutenberg_rtc_build_paragraph_insert( array $state, Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph $paragraph, string $text, int $client_id, int $start_clock, string $block_client_id ): array {
	$left_origin    = $paragraph->left_origin();
	$content_insert = cpdangit_gutenberg_rtc_build_content_insert_for_paragraph( $state, $text, $paragraph->text() );
	$update         = cpdangit_gutenberg_yjs_encode_paragraph_insert_after_update_v2(
		$client_id,
		$text,
		$block_client_id,
		(int) $left_origin['client'],
		(int) $left_origin['clock'],
		$start_clock,
		$paragraph->right_origin(),
		$content_insert
	);
	$clock_len      = cpdangit_gutenberg_yjs_paragraph_insert_clock_len( $text, $content_insert );

	return array(
		'update'         => $update,
		'content_insert' => $content_insert,
		'clock_len'      => $clock_len,
		'cursor_clock'   => $start_clock + 4,
		'next_clock'     => $start_clock + $clock_len,
		'left_origin'    => $left_origin,
		'right_origin'   => $paragraph->right_origin(),
	);
}

/**
 * Builds update bytes for replacing a text range in a paragraph.
 *
 * @param array<string, mixed> $state State used to locate the source text item.
 * @return array<string, mixed>|null
 */
function cpdangit_gutenberg_rtc_build_text_replacement( array $state, Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph $paragraph, int $start, int $length, string $original, string $replacement, int $client_id, int $start_clock ): ?array {
	if ( $start < 0 || $length <= 0 || '' === $replacement || $replacement === $original ) {
		return null;
	}

	$cursor_type = cpdangit_gutenberg_rtc_find_block_text_type_id( $state, $paragraph->source_block_id() );
	if ( ! $cursor_type ) {
		return null;
	}

	$nodes = cpdangit_gutenberg_rtc_text_nodes_for_block( $state, $paragraph->source_block_id() );
	if ( count( $nodes ) < $start + $length ) {
		return null;
	}

	$origin       = $start > 0 ? $nodes[ $start - 1 ]['id'] : null;
	$right_origin = $nodes[ $start ]['id'];
	$end_item     = $nodes[ $start + $length ]['id'] ?? null;
	$delete_ranges = cpdangit_gutenberg_rtc_delete_ranges_from_text_nodes(
		array_slice( $nodes, $start, $length )
	);
	if ( empty( $delete_ranges ) ) {
		return null;
	}

	$update    = cpdangit_gutenberg_yjs_encode_text_replacement_update_v2(
		$client_id,
		$replacement,
		$origin,
		$right_origin,
		null,
		$delete_ranges,
		$start_clock
	);
	$clock_len = cpdangit_gutenberg_yjs_utf16_clock_len( $replacement );

	return array(
		'update'         => $update,
		'clock_len'      => $clock_len,
		'selection'      => array(
			'type'         => $cursor_type,
			'start_item'   => array(
				'client' => $client_id,
				'clock'  => $start_clock,
			),
			'end_item'     => $end_item,
			'start_offset' => $start,
			'end_offset'   => $start + $clock_len,
		),
		'cursor_clock'   => $start_clock + max( 0, $clock_len - 1 ),
		'absoluteOffset' => $start + $clock_len,
		'next_clock'     => $start_clock + $clock_len,
		'original_word'  => $original,
		'replacement'    => $replacement,
		'origin'         => $origin,
		'right_origin'   => $right_origin,
		'delete_ranges'  => $delete_ranges,
	);
}

/**
 * Finds the Y.Text type item ID for a block's content attribute.
 *
 * @return array{client:int,clock:int}|null
 */
function cpdangit_gutenberg_rtc_find_block_text_type_id( array $state, string $block_id ): ?array {
	foreach ( $state['text_to_block'] as $text_id => $mapped_block_id ) {
		if ( (string) $mapped_block_id !== $block_id ) {
			continue;
		}

		return cpdangit_gutenberg_rtc_yjs_id_from_key( (string) $text_id );
	}

	return null;
}

/**
 * Applies decoded Yjs structs to the lightweight paragraph document state.
 *
 * @param array<string, mixed> $state   State, mutated in place.
 * @param array<string, mixed> $decoded Decoded update.
 * @return array<int, Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph>
 */
function cpdangit_gutenberg_rtc_apply_decoded_update_to_paragraph_state( array &$state, array $decoded ): array {
	$events         = array();
	$touched_blocks = array();
	$structs        = isset( $decoded['structs'] ) && is_array( $decoded['structs'] ) ? $decoded['structs'] : array();

	foreach ( $structs as $struct ) {
		if ( ! is_array( $struct ) || ( $struct['kind'] ?? '' ) !== 'item' ) {
			continue;
		}

		$id      = cpdangit_gutenberg_rtc_yjs_id_key( $struct['id'] ?? null );
		$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();

		if ( 'type' === ( $content['type'] ?? '' ) && 'Y.Map' === ( $content['name'] ?? '' ) ) {
			cpdangit_gutenberg_rtc_note_map_item( $state, $struct, $id );
		}

		$parent_sub = isset( $struct['parent_sub'] ) && is_string( $struct['parent_sub'] ) ? $struct['parent_sub'] : null;
		$parent_key = cpdangit_gutenberg_rtc_yjs_parent_key( $struct['parent'] ?? null );

		if ( $parent_sub ) {
			cpdangit_gutenberg_rtc_note_root_field( $state, $struct, $id, $parent_sub );
		}

		if ( $parent_sub && $parent_key ) {
			cpdangit_gutenberg_rtc_note_map_field( $state, $struct, $id, $parent_key, $parent_sub );
		}

		if ( 'string' === ( $content['type'] ?? '' ) ) {
			$touched_block = cpdangit_gutenberg_rtc_note_text_item( $state, $struct, $id );
			if ( $touched_block ) {
				$touched_blocks[ $touched_block ] = true;
			}
			cpdangit_gutenberg_rtc_note_root_content_item( $state, $struct, $id );
		}
	}

	foreach ( array_keys( $touched_blocks ) as $block_id ) {
		$block = $state['blocks'][ $block_id ] ?? null;
		if (
			is_array( $block ) &&
			isset( $block['name'], $block['id'] ) &&
			'core/paragraph' === $block['name'] &&
			cpdangit_gutenberg_rtc_text_ends_with_delimiter( (string) ( $block['content'] ?? '' ) )
		) {
			$events[] = new Capital_P_Dangit_Gutenberg_RTC_Completed_Paragraph(
				$block_id,
				(string) $block['content'],
				$block['id'],
				null
			);
		}
	}

	return $events;
}

/**
 * Notes a root document field item.
 */
function cpdangit_gutenberg_rtc_note_root_field( array &$state, array $struct, string $id, string $parent_sub ): void {
	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
	$parent  = isset( $struct['parent'] ) && is_array( $struct['parent'] ) ? $struct['parent'] : null;

	if (
		'content' === $parent_sub &&
		'root' === ( $parent['type'] ?? '' ) &&
		'document' === ( $parent['key'] ?? '' ) &&
		'type' === ( $content['type'] ?? '' ) &&
		'Y.Text' === ( $content['name'] ?? '' )
	) {
		$state['root_content_text'] = cpdangit_gutenberg_rtc_yjs_id_from_key( $id );
	}
}

/**
 * Notes a Y.Map item that might be a block or nested block structure.
 */
function cpdangit_gutenberg_rtc_note_map_item( array &$state, array $struct, string $id ): void {
	if ( '' === $id ) {
		return;
	}

	if ( ! isset( $state['blocks'][ $id ] ) ) {
		$state['blocks'][ $id ] = array(
			'id'      => cpdangit_gutenberg_rtc_yjs_id_from_key( $id ),
			'content' => '',
		);
	}

	if ( isset( $struct['origin'] ) && is_array( $struct['origin'] ) ) {
		$state['blocks'][ $id ]['origin'] = $struct['origin'];
	}

	if ( isset( $struct['right_origin'] ) && is_array( $struct['right_origin'] ) ) {
		$state['blocks'][ $id ]['right_origin'] = $struct['right_origin'];
	}
}

/**
 * Notes a Y.Map field item.
 */
function cpdangit_gutenberg_rtc_note_map_field( array &$state, array $struct, string $id, string $parent_key, string $parent_sub ): void {
	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();

	if ( 'name' === $parent_sub && 'any' === ( $content['type'] ?? '' ) && isset( $content['values'][0] ) ) {
		$state['blocks'][ $parent_key ]['name'] = (string) $content['values'][0];
		return;
	}

	if ( 'clientId' === $parent_sub && 'any' === ( $content['type'] ?? '' ) && isset( $content['values'][0] ) ) {
		$state['blocks'][ $parent_key ]['clientId'] = (string) $content['values'][0];
		return;
	}

	if ( 'attributes' === $parent_sub && 'type' === ( $content['type'] ?? '' ) && 'Y.Map' === ( $content['name'] ?? '' ) ) {
		$state['attributes_to_block'][ $id ] = $parent_key;
		return;
	}

	if ( 'content' === $parent_sub && 'type' === ( $content['type'] ?? '' ) && 'Y.Text' === ( $content['name'] ?? '' ) && isset( $state['attributes_to_block'][ $parent_key ] ) ) {
		$state['text_to_block'][ $id ] = $state['attributes_to_block'][ $parent_key ];
	}
}

/**
 * Notes a Y.Text string item.
 *
 * @return string|null Block ID touched by the item.
 */
function cpdangit_gutenberg_rtc_note_text_item( array &$state, array $struct, string $id ): ?string {
	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
	$text    = isset( $content['value'] ) ? (string) $content['value'] : '';
	$block   = null;
	$parent  = cpdangit_gutenberg_rtc_yjs_parent_key( $struct['parent'] ?? null );

	if ( $parent && isset( $state['text_to_block'][ $parent ] ) ) {
		$block = $state['text_to_block'][ $parent ];
	} elseif ( isset( $struct['origin'] ) && is_array( $struct['origin'] ) ) {
		$block = cpdangit_gutenberg_rtc_find_text_item_block_by_origin( $state, $struct['origin'] );
	}

	if ( ! $block ) {
		return null;
	}

	$state['blocks'][ $block ]['content'] = (string) ( $state['blocks'][ $block ]['content'] ?? '' ) . $text;
	$state['text_items_to_block'][ $id ]  = array(
		'block'        => $block,
		'length'       => (int) ( $struct['length'] ?? cpdangit_gutenberg_yjs_utf16_clock_len( $text ) ),
		'origin'       => isset( $struct['origin'] ) && is_array( $struct['origin'] ) ? $struct['origin'] : null,
		'right_origin' => isset( $struct['right_origin'] ) && is_array( $struct['right_origin'] ) ? $struct['right_origin'] : null,
		'text'         => $text,
	);
	$state['blocks'][ $block ]['content'] = cpdangit_gutenberg_rtc_reconstruct_block_text_from_items( $state, $block );

	return $block;
}

/**
 * Checks whether text ends in a word-delimiting character.
 */
function cpdangit_gutenberg_rtc_text_ends_with_delimiter( string $text ): bool {
	if ( '' === $text ) {
		return false;
	}

	return (bool) preg_match( '/[^\p{L}\p{N}_]$/u', $text );
}

/**
 * Reconstructs a block's text by following Yjs item origins.
 */
function cpdangit_gutenberg_rtc_reconstruct_block_text_from_items( array $state, string $block_id ): string {
	$text = '';
	foreach ( cpdangit_gutenberg_rtc_text_nodes_for_block( $state, $block_id ) as $node ) {
		$text .= $node['text'];
	}

	return $text;
}

/**
 * Gets ordered Yjs character nodes for a block's Y.Text content.
 *
 * @return array<int,array{id:array{client:int,clock:int},text:string}>
 */
function cpdangit_gutenberg_rtc_text_nodes_for_block( array $state, string $block_id ): array {
	$children = array();
	$nodes    = array();

	foreach ( $state['text_items_to_block'] as $item_key => $item ) {
		if ( ! is_array( $item ) || (string) ( $item['block'] ?? '' ) !== $block_id || ! isset( $item['text'] ) ) {
			continue;
		}

		$item_id = cpdangit_gutenberg_rtc_yjs_id_from_key( (string) $item_key );
		if ( ! $item_id ) {
			continue;
		}

		$chars = preg_split( '//u', (string) $item['text'], -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) || empty( $chars ) ) {
			continue;
		}

		foreach ( $chars as $index => $char ) {
			$id = array(
				'client' => (int) $item_id['client'],
				'clock'  => (int) $item_id['clock'] + $index,
			);
			$id_key = cpdangit_gutenberg_rtc_yjs_id_key( $id );
			$origin = 0 === $index
				? ( isset( $item['origin'] ) && is_array( $item['origin'] ) ? $item['origin'] : null )
				: array(
					'client' => (int) $item_id['client'],
					'clock'  => (int) $item_id['clock'] + $index - 1,
				);
			$origin_key   = $origin ? cpdangit_gutenberg_rtc_yjs_id_key( $origin ) : '';
			$right_origin = 0 === $index && isset( $item['right_origin'] ) && is_array( $item['right_origin'] )
				? $item['right_origin']
				: null;

			$nodes[ $id_key ]      = array(
				'id'           => $id,
				'right_origin' => $right_origin,
				'text'         => $char,
			);
			$children[ $origin_key ][] = $id_key;
		}
	}

	foreach ( $children as &$child_keys ) {
		usort(
			$child_keys,
			static function ( string $a, string $b ) use ( $nodes ): int {
				$a_id    = $nodes[ $a ]['id'];
				$b_id    = $nodes[ $b ]['id'];
				$a_right = cpdangit_gutenberg_rtc_yjs_id_key( $nodes[ $a ]['right_origin'] ?? null );
				$b_right = cpdangit_gutenberg_rtc_yjs_id_key( $nodes[ $b ]['right_origin'] ?? null );

				if ( $a_right === $b ) {
					return -1;
				}

				if ( $b_right === $a ) {
					return 1;
				}

				return ( (int) $a_id['client'] <=> (int) $b_id['client'] ) ?: ( (int) $a_id['clock'] <=> (int) $b_id['clock'] );
			}
		);
	}
	unset( $child_keys );

	$ordered = array();
	$walk    = static function ( string $origin_key ) use ( &$walk, &$ordered, $children, $nodes ): void {
		foreach ( $children[ $origin_key ] ?? array() as $child_key ) {
			$ordered[] = $nodes[ $child_key ];
			$walk( $child_key );
		}
	};

	$walk( '' );

	return $ordered;
}

/**
 * Converts ordered character nodes into compact delete ranges.
 *
 * @param array<int,array{id:array{client:int,clock:int},text:string}> $nodes Character nodes.
 * @return array<int,array<int,array{clock:int,length:int}>>
 */
function cpdangit_gutenberg_rtc_delete_ranges_from_text_nodes( array $nodes ): array {
	$ranges = array();

	foreach ( $nodes as $node ) {
		$id = isset( $node['id'] ) && is_array( $node['id'] ) ? $node['id'] : null;
		if ( ! $id ) {
			continue;
		}

		$client = (int) $id['client'];
		$clock  = (int) $id['clock'];
		$last_index = isset( $ranges[ $client ] ) ? count( $ranges[ $client ] ) - 1 : -1;

		if ( $last_index >= 0 ) {
			$last = $ranges[ $client ][ $last_index ];
			if ( (int) $last['clock'] + (int) $last['length'] === $clock ) {
				$ranges[ $client ][ $last_index ]['length']++;
				continue;
			}
		}

		$ranges[ $client ][] = array(
			'clock'  => $clock,
			'length' => 1,
		);
	}

	return $ranges;
}

/**
 * Notes a string item in the root document.content Y.Text.
 */
function cpdangit_gutenberg_rtc_note_root_content_item( array &$state, array $struct, string $id ): void {
	$root_content_text = isset( $state['root_content_text'] ) && is_array( $state['root_content_text'] ) ? $state['root_content_text'] : null;
	$parent            = cpdangit_gutenberg_rtc_yjs_parent_key( $struct['parent'] ?? null );
	$root_content_key  = $root_content_text ? cpdangit_gutenberg_rtc_yjs_id_key( $root_content_text ) : '';

	if ( ! $root_content_key || $parent !== $root_content_key ) {
		return;
	}

	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
	$text    = isset( $content['value'] ) ? (string) $content['value'] : '';
	$length  = (int) ( $struct['length'] ?? cpdangit_gutenberg_yjs_utf16_clock_len( $text ) );

	$state['root_content'] .= $text;
	$state['root_content_items'][ $id ] = array(
		'offset' => cpdangit_gutenberg_yjs_utf16_clock_len( (string) $state['root_content'] ) - $length,
		'length' => $length,
		'text'   => $text,
	);
}

/**
 * Builds a serialized-content insertion descriptor for a new paragraph.
 *
 * @return array<string, mixed>|null
 */
function cpdangit_gutenberg_rtc_build_content_insert_for_paragraph( array $state, string $inserted_text, string $source_text = '' ): ?array {
	$root_content_text = isset( $state['root_content_text'] ) && is_array( $state['root_content_text'] ) ? $state['root_content_text'] : null;
	$root_content      = isset( $state['root_content'] ) ? (string) $state['root_content'] : '';

	if ( ! $root_content_text ) {
		return null;
	}

	if ( '' === $root_content && '' !== $source_text ) {
		return array(
			'parent'       => $root_content_text,
			'origin'       => null,
			'right_origin' => null,
			'text'         => cpdangit_gutenberg_rtc_serialize_paragraph_block( $source_text ) . "\n\n" . cpdangit_gutenberg_rtc_serialize_paragraph_block( $inserted_text ) . "\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
		);
	}

	if ( '' === $root_content ) {
		return null;
	}

	$serialized = "\n\n" . cpdangit_gutenberg_rtc_serialize_paragraph_block( $inserted_text );
	$offset     = strlen( $root_content );

	if ( preg_match( '/\n\n<!-- wp:paragraph(?:\\s+\\/| -->\n<p><\\/p>\n<!-- \\/wp:paragraph -->)\\s*$/', $root_content, $matches, PREG_OFFSET_CAPTURE ) ) {
		$offset = (int) $matches[0][1];
	}

	$position = cpdangit_gutenberg_rtc_root_content_position_to_yjs_ids( $state, $offset );
	if ( ! $position ) {
		return null;
	}

	return array(
		'parent'       => $root_content_text,
		'origin'       => $position['origin'],
		'right_origin' => $position['right_origin'],
		'text'         => $serialized,
	);
}

/**
 * Serializes plain paragraph text as Gutenberg paragraph block markup.
 */
function cpdangit_gutenberg_rtc_serialize_paragraph_block( string $text ): string {
	return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
}

/**
 * Converts a root content UTF-8 byte offset to approximate Yjs neighbor IDs.
 *
 * @return array{origin:?array<string,int>,right_origin:?array<string,int>}|null
 */
function cpdangit_gutenberg_rtc_root_content_position_to_yjs_ids( array $state, int $offset ): ?array {
	$items = isset( $state['root_content_items'] ) && is_array( $state['root_content_items'] ) ? $state['root_content_items'] : array();
	if ( empty( $items ) ) {
		return array(
			'origin'       => null,
			'right_origin' => null,
		);
	}

	uasort(
		$items,
		static function ( $a, $b ): int {
			return (int) ( $a['offset'] ?? 0 ) <=> (int) ( $b['offset'] ?? 0 );
		}
	);

	$origin        = null;
	$right_origin  = null;
	$utf16_offset  = cpdangit_gutenberg_yjs_utf16_clock_len( substr( (string) ( $state['root_content'] ?? '' ), 0, $offset ) );

	foreach ( $items as $item_key => $item ) {
		$item_id = cpdangit_gutenberg_rtc_yjs_id_from_key( (string) $item_key );
		if ( ! $item_id ) {
			continue;
		}

		$start = (int) ( $item['offset'] ?? 0 );
		$end   = $start + (int) ( $item['length'] ?? 0 );
		if ( $utf16_offset <= $start ) {
			$right_origin = $item_id;
			break;
		}

		if ( $utf16_offset <= $end ) {
			$relative = max( 0, $utf16_offset - $start );
			if ( $relative > 0 ) {
				$origin = array(
					'client' => (int) $item_id['client'],
					'clock'  => (int) $item_id['clock'] + $relative - 1,
				);
			}
			if ( $relative < (int) ( $item['length'] ?? 0 ) ) {
				$right_origin = array(
					'client' => (int) $item_id['client'],
					'clock'  => (int) $item_id['clock'] + $relative,
				);
			}
			break;
		}

		$origin = array(
			'client' => (int) $item_id['client'],
			'clock'  => (int) $item_id['clock'] + (int) ( $item['length'] ?? 0 ) - 1,
		);
	}

	return array(
		'origin'       => $origin,
		'right_origin' => $right_origin,
	);
}

/**
 * Finds the block owning a text item origin.
 */
function cpdangit_gutenberg_rtc_find_text_item_block_by_origin( array $state, array $origin ): ?string {
	$client = isset( $origin['client'] ) ? (int) $origin['client'] : 0;
	$clock  = isset( $origin['clock'] ) ? (int) $origin['clock'] : 0;

	foreach ( $state['text_items_to_block'] as $item_key => $item ) {
		$item_id = cpdangit_gutenberg_rtc_yjs_id_from_key( (string) $item_key );
		if ( ! $item_id || (int) $item_id['client'] !== $client ) {
			continue;
		}

		$start = (int) $item_id['clock'];
		$end   = $start + (int) ( $item['length'] ?? 0 );
		if ( $clock >= $start && $clock < $end ) {
			return isset( $item['block'] ) ? (string) $item['block'] : null;
		}
	}

	return null;
}

/**
 * Builds a stable key for a Yjs ID.
 */
function cpdangit_gutenberg_rtc_yjs_id_key( $id ): string {
	if ( ! is_array( $id ) || ! isset( $id['client'], $id['clock'] ) ) {
		return '';
	}

	return (int) $id['client'] . ':' . (int) $id['clock'];
}

/**
 * Builds a stable key for a Yjs parent ID descriptor.
 */
function cpdangit_gutenberg_rtc_yjs_parent_key( $parent ): string {
	if ( ! is_array( $parent ) || ( $parent['type'] ?? '' ) !== 'id' ) {
		return '';
	}

	return cpdangit_gutenberg_rtc_yjs_id_key( $parent );
}

/**
 * Parses a stable Yjs ID key.
 *
 * @return array{client:int,clock:int}|null
 */
function cpdangit_gutenberg_rtc_yjs_id_from_key( string $key ): ?array {
	if ( ! preg_match( '/^(\d+):(\d+)$/', $key, $matches ) ) {
		return null;
	}

	return array(
		'client' => (int) $matches[1],
		'clock'  => (int) $matches[2],
	);
}
