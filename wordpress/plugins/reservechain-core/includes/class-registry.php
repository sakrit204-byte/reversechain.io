<?php
/**
 * Asset Registry: post types, admin field UI, validation, record numbering, field-level audit diffs.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Registry {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_for_registry' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( Schema::types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		}
	}

	public static function register_post_types(): void {
		foreach ( Schema::entities() as $type => $def ) {
			register_post_type(
				$type,
				array(
					'labels'              => array(
						'name'          => $def['label'],
						'singular_name' => $def['singular'],
						'add_new_item'  => 'Add ' . $def['singular'],
						'edit_item'     => 'Edit ' . $def['singular'],
						'menu_name'     => $def['label'],
						'all_items'     => $def['label'],
					),
					'public'              => false,
					'publicly_queryable'  => false,
					'exclude_from_search' => true,
					'show_ui'             => true,
					'show_in_menu'        => 'reservechain-registry',
					'show_in_rest'        => false, // Never expose raw meta through wp/v2 — the curated rc/v1 API is the only interface.
					'supports'            => array( 'title', 'editor', 'revisions', 'author' ),
					'capability_type'     => array( 'rc_entity', 'rc_entities' ),
					'map_meta_cap'        => true,
					'menu_icon'           => $def['icon'],
					'rewrite'             => false,
				)
			);
		}
	}

	public static function classic_for_registry( bool $use, string $type ): bool {
		return in_array( $type, Schema::types(), true ) ? false : $use;
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, Schema::types(), true ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'rc-admin', RC_URL . 'assets/admin.css', array(), RC_VERSION );
		wp_enqueue_script( 'rc-admin', RC_URL . 'assets/admin.js', array( 'jquery' ), RC_VERSION, true );
	}

	public static function meta_boxes(): void {
		foreach ( Schema::entities() as $type => $def ) {
			add_meta_box( 'rc_fields', $def['singular'] . ' — registry fields', array( __CLASS__, 'render_fields' ), $type, 'normal', 'high' );
			add_meta_box( 'rc_record', 'Registry record', array( __CLASS__, 'render_record' ), $type, 'side', 'high' );
		}
	}

	public static function render_record( \WP_Post $post ): void {
		$no = get_post_meta( $post->ID, '_rc_record_no', true );
		echo '<p><strong>Record no.</strong><br><code>' . esc_html( $no ?: 'Assigned on first save' ) . '</code></p>';
		if ( Schema::is_passport_type( $post->post_type ) && $no ) {
			$url = home_url( '/passport/' . rawurlencode( $no ) . '/' );
			echo '<p><a class="button" target="_blank" href="' . esc_url( $url ) . '">View Digital Asset Passport</a></p>';
			$p = Passport::build( $post->ID, true );
			if ( $p ) {
				printf( '<p>Evidence completeness: <strong>%d%%</strong><br><small>%d of %d items present</small></p>', (int) $p['completeness']['percent'], (int) $p['completeness']['present'], (int) $p['completeness']['total'] );
				echo '<p style="word-break:break-all"><small>Evidence Merkle root:<br><code>' . esc_html( $p['merkle_root'] ?? '—' ) . '</code></small></p>';
			}
		}
		if ( 'rc_document' === $post->post_type ) {
			$sha = get_post_meta( $post->ID, '_rc_sha256', true );
			echo '<p style="word-break:break-all"><strong>SHA-256</strong><br><code>' . esc_html( $sha ?: 'Computed on save' ) . '</code></p>';
		}
	}

	public static function render_fields( \WP_Post $post ): void {
		$def = Schema::entity( $post->post_type );
		wp_nonce_field( 'rc_save_fields', 'rc_fields_nonce' );
		echo '<p class="description">Leave a field empty when the information has not been provided. Empty fields are shown publicly as an explicit pending state — never estimate or invent values.</p>';
		echo '<table class="form-table rc-fields" role="presentation"><tbody>';
		foreach ( $def['fields'] as $field ) {
			$key   = $field['key'];
			$name  = 'rc_field[' . $key . ']';
			$value = self::get( $post->ID, $key );
			$req   = ! empty( $field['required'] ) ? ' <span class="rc-req" aria-label="required">*</span>' : '';
			$vis   = isset( $field['public'] ) && ! $field['public'] ? ' <span class="rc-private">internal</span>' : '';
			echo '<tr><th scope="row"><label for="rc_' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . $req . $vis . '</label></th><td>'; // phpcs:ignore
			self::render_input( $field, $name, $value, $post );
			if ( ! empty( $field['help'] ) ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			if ( ! empty( $field['pending'] ) ) {
				echo '<p class="description rc-pending-hint">If empty, shown as: <em>' . esc_html( $field['pending'] ) . '</em></p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_input( array $field, string $name, $value, \WP_Post $post ): void {
		$id = 'rc_' . $field['key'];
		switch ( $field['type'] ) {
			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;
			case 'number':
			case 'money':
				printf( '<input id="%s" type="number" step="any" min="0" name="%s" value="%s" class="regular-text"> %s', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), esc_html( $field['unit'] ?? '' ) );
				break;
			case 'date':
				printf( '<input id="%s" type="date" name="%s" value="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			case 'url':
				printf( '<input id="%s" type="url" name="%s" value="%s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			case 'select':
			case 'status':
				$options = 'status' === $field['type'] ? Schema::claim_statuses() : $field['options'];
				$value   = '' === (string) $value && isset( $field['default'] ) ? $field['default'] : $value;
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $options as $k => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $value, (string) $k, false ), esc_html( $label ) );
				}
				echo '</select>';
				if ( 'status' === $field['type'] ) {
					echo '<p class="description">“Verified” must only be selected when supporting evidence is attached and has passed review.</p>';
				}
				break;
			case 'country':
				printf( '<select id="%s" name="%s"><option value="">— not provided —</option>', esc_attr( $id ), esc_attr( $name ) );
				foreach ( Schema::countries() as $code => $label ) {
					printf( '<option value="%s"%s>%s (%s)</option>', esc_attr( $code ), selected( (string) $value, $code, false ), esc_html( $label ), esc_html( $code ) );
				}
				echo '</select>';
				break;
			case 'relation':
			case 'relations':
				$multi   = 'relations' === $field['type'];
				$targets = (array) $field['target'];
				$items   = get_posts(
					array(
						'post_type'      => $targets,
						'post_status'    => array( 'publish', 'draft', 'rc_review', 'rc_approved', 'rc_unpublished' ),
						'posts_per_page' => 500,
						'orderby'        => 'title',
						'order'          => 'ASC',
						'exclude'        => array( $post->ID ),
					)
				);
				$current = array_map( 'intval', (array) $value );
				printf( '<select id="%s" name="%s%s"%s class="rc-relation">', esc_attr( $id ), esc_attr( $name ), $multi ? '[]' : '', $multi ? ' multiple size="6" style="min-width:420px"' : '' );
				if ( ! $multi ) {
					echo '<option value="">— none —</option>';
				}
				foreach ( $items as $item ) {
					$no    = get_post_meta( $item->ID, '_rc_record_no', true );
					$label = ( $no ? $no . ' · ' : '' ) . $item->post_title . ( count( $targets ) > 1 ? ' (' . Schema::entity( $item->post_type )['singular'] . ')' : '' );
					printf( '<option value="%d"%s>%s</option>', (int) $item->ID, in_array( (int) $item->ID, $current, true ) ? ' selected' : '', esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'file':
				$att = (int) $value;
				$sha = $att ? get_post_meta( $post->ID, '_rc_sha256', true ) : '';
				$locked = $att && 'publish' === $post->post_status;
				printf(
					'<input type="hidden" id="%1$s" name="%2$s" value="%3$s"><span class="rc-file-name">%4$s</span> %5$s',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $att ?: '' ),
					$att ? esc_html( basename( (string) get_attached_file( $att ) ) ) : '<em>No file</em>',
					$locked ? '<p class="description">File is locked because this document is published. Publish a new document version instead.</p>' : '<button type="button" class="button rc-pick-file" data-target="' . esc_attr( $id ) . '">Select / upload file</button>'
				);
				if ( $sha ) {
					echo '<p><code class="rc-hash">' . esc_html( $sha ) . '</code></p>';
				}
				break;
			default:
				printf( '<input id="%s" type="text" name="%s" value="%s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
		}
	}

	public static function get( int $post_id, string $key ) {
		$field = Schema::field( (string) get_post_type( $post_id ), $key );
		if ( $field && 'relations' === $field['type'] ) {
			return array_map( 'intval', get_post_meta( $post_id, Schema::meta_key( $key ), false ) );
		}
		return get_post_meta( $post_id, Schema::meta_key( $key ), true );
	}

	public static function sanitize( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( wp_unslash( (string) $raw ) );
			case 'number':
			case 'money':
				return '' === trim( (string) $raw ) ? '' : (string) max( 0, (float) $raw );
			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw ) ? (string) $raw : '';
			case 'url':
				return esc_url_raw( (string) $raw );
			case 'country':
				$raw = strtoupper( (string) $raw );
				return isset( Schema::countries()[ $raw ] ) ? $raw : '';
			case 'select':
				return array_key_exists( (string) $raw, $field['options'] ) ? (string) $raw : '';
			case 'status':
				return array_key_exists( (string) $raw, Schema::CLAIM_STATUSES ) ? (string) $raw : ( $field['default'] ?? 'in_development' );
			case 'relation':
			case 'file':
				return absint( $raw ) ?: '';
			case 'relations':
				return array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
			default:
				return sanitize_text_field( wp_unslash( (string) $raw ) );
		}
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, Schema::types(), true ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		self::ensure_record_no( $post_id );

		if ( ! isset( $_POST['rc_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rc_fields_nonce'] ), 'rc_save_fields' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = isset( $_POST['rc_field'] ) && is_array( $_POST['rc_field'] ) ? $_POST['rc_field'] : array(); // phpcs:ignore -- sanitised per field below.
		self::write_fields( $post_id, $input );
	}

	/**
	 * Validate and persist field values; append a field-level diff to the audit trail.
	 */
	public static function write_fields( int $post_id, array $input ): array {
		$type    = get_post_type( $post_id );
		$def     = Schema::entity( $type );
		$changes = array();
		$errors  = array();

		foreach ( $def['fields'] as $field ) {
			$key = $field['key'];
			if ( ! array_key_exists( $key, $input ) && 'relations' !== $field['type'] ) {
				continue;
			}
			if ( 'relations' === $field['type'] && ! array_key_exists( $key, $input ) && ! isset( $_POST['rc_field'] ) ) {
				continue;
			}
			$new = self::sanitize( $field, $input[ $key ] ?? array() );
			$old = self::get( $post_id, $key );

			if ( 'file' === $field['type'] && $old && (int) $old !== (int) $new && 'publish' === get_post_status( $post_id ) ) {
				$errors[] = 'The file of a published document cannot be replaced.';
				continue;
			}
			if ( ! empty( $field['required'] ) && ( '' === $new || array() === $new ) ) {
				$errors[] = sprintf( '“%s” is required.', $field['label'] );
			}
			if ( $old == $new ) { // phpcs:ignore -- loose compare intentional ("1" vs 1).
				continue;
			}
			$meta_key = Schema::meta_key( $key );
			if ( 'relations' === $field['type'] ) {
				delete_post_meta( $post_id, $meta_key );
				foreach ( $new as $rel ) {
					add_post_meta( $post_id, $meta_key, $rel );
				}
			} elseif ( '' === $new ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $new );
			}
			$changes[ $key ] = array( 'from' => $old, 'to' => $new );

			if ( 'file' === $field['type'] ) {
				$path = $new ? get_attached_file( (int) $new ) : '';
				$sha  = ( $path && is_readable( $path ) ) ? hash_file( 'sha256', $path ) : '';
				$sha ? update_post_meta( $post_id, '_rc_sha256', $sha ) : delete_post_meta( $post_id, '_rc_sha256' );
				$changes['sha256'] = $sha;
			}
		}

		if ( $changes ) {
			Audit_Log::record( 'registry.updated', $type, $post_id, sprintf( '%s %s updated (%d field%s)', $def['singular'], get_post_meta( $post_id, '_rc_record_no', true ), count( $changes ), 1 === count( $changes ) ? '' : 's' ), $changes );
		}
		if ( $errors ) {
			set_transient( 'rc_wf_notice_' . get_current_user_id(), implode( ' ', $errors ), 60 );
		}
		return $errors;
	}

	/** Human-readable, immutable record number, e.g. RC-CU-LOT-000001. */
	public static function ensure_record_no( int $post_id ): string {
		$existing = get_post_meta( $post_id, '_rc_record_no', true );
		if ( $existing ) {
			return $existing;
		}
		$type = get_post_type( $post_id );
		$def  = Schema::entity( $type );
		if ( ! $def || 'auto-draft' === get_post_status( $post_id ) ) {
			return '';
		}
		$scope = 'RC';
		$prog  = (int) get_post_meta( $post_id, '_rc_program', true );
		if ( ! $prog && ! empty( $_POST['rc_field']['program'] ) ) { // phpcs:ignore
			$prog = absint( $_POST['rc_field']['program'] ); // phpcs:ignore
		}
		if ( 'rc_program' === $type ) {
			$prog = $post_id;
		}
		if ( $prog ) {
			$symbol = get_post_meta( $prog, '_rc_symbol', true );
			if ( ! $symbol && 'rc_program' === $type && ! empty( $_POST['rc_field']['symbol'] ) ) { // phpcs:ignore
				$symbol = sanitize_text_field( wp_unslash( $_POST['rc_field']['symbol'] ) ); // phpcs:ignore
			}
			$scope = $symbol ? strtoupper( preg_replace( '/[^A-Za-z]/', '', $symbol ) ) : 'RC';
		}
		$seq = (int) get_option( 'rc_seq_' . $type, 0 ) + 1;
		update_option( 'rc_seq_' . $type, $seq, false );
		$no = 'RC' === $scope ? sprintf( 'RC-%s-%06d', $def['prefix'], $seq ) : sprintf( 'RC-%s-%s-%06d', $scope, $def['prefix'], $seq );
		update_post_meta( $post_id, '_rc_record_no', $no );
		Audit_Log::record( 'registry.created', $type, $post_id, sprintf( '%s %s created', $def['singular'], $no ), array( 'record_no' => $no ) );
		return $no;
	}

	public static function find_by_record_no( string $no ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => Schema::types(),
				'post_status'    => 'publish',
				'meta_key'       => '_rc_record_no', // phpcs:ignore
				'meta_value'     => sanitize_text_field( $no ), // phpcs:ignore
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/** Records of $types that reference $post_id through any relation field. */
	public static function referencing( int $post_id, array $types, string $status = 'publish' ): array {
		$keys = array();
		foreach ( $types as $type ) {
			foreach ( Schema::entity( $type )['fields'] ?? array() as $f ) {
				if ( in_array( $f['type'], array( 'relation', 'relations' ), true ) ) {
					$keys[ Schema::meta_key( $f['key'] ) ] = true;
				}
			}
		}
		if ( ! $keys ) {
			return array();
		}
		$meta = array( 'relation' => 'OR' );
		foreach ( array_keys( $keys ) as $k ) {
			$meta[] = array( 'key' => $k, 'value' => $post_id, 'compare' => '=' );
		}
		return get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => $status,
				'posts_per_page' => 200,
				'meta_query'     => $meta, // phpcs:ignore
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
	}

	public static function columns( array $cols ): array {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['rc_record']   = 'Record no.';
				$new['rc_vstatus']  = 'Verification';
			}
		}
		return $new;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'rc_record' === $col ) {
			echo '<code>' . esc_html( get_post_meta( $post_id, '_rc_record_no', true ) ?: '—' ) . '</code>';
		}
		if ( 'rc_vstatus' === $col ) {
			$s = get_post_meta( $post_id, '_rc_verification_status', true ) ?: 'in_development';
			echo '<span class="rc-pill rc-pill-' . esc_attr( $s ) . '">' . esc_html( Schema::claim_statuses()[ $s ] ?? $s ) . '</span>';
		}
	}
}
