<?php
/**
 * In-app notifications (user-specific and broadcast). Delivered through the API to the iOS/Android apps;
 * push delivery (APNs/FCM via Expo) hooks into `rc_notification_created`.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rc_notifications';
	}

	public static function push( int $user_id, string $title, string $body, string $category = 'general' ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => $user_id,
				'title'      => mb_substr( $title, 0, 191 ),
				'body'       => $body,
				'category'   => sanitize_key( $category ),
			)
		);
		$id = (int) $wpdb->insert_id;
		do_action( 'rc_notification_created', $id, $user_id, $title, $body, $category );
		return $id;
	}

	public static function for_user( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id IN (0, %d) ORDER BY id DESC LIMIT %d', $user_id, $limit ), ARRAY_A ); // phpcs:ignore
		return array_map(
			static fn( $r ) => array(
				'id'         => (int) $r['id'],
				'title'      => $r['title'],
				'body'       => $r['body'],
				'category'   => $r['category'],
				'created_at' => $r['created_at'] . 'Z',
				'read'       => null !== $r['read_at'],
				'broadcast'  => 0 === (int) $r['user_id'],
			),
			$rows
		);
	}

	public static function mark_read( int $user_id, int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL', current_time( 'mysql', true ), $id, $user_id ) ); // phpcs:ignore
	}
}
