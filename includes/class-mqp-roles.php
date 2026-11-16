<?php
defined( 'ABSPATH' ) || exit;

/**
 * Portal capabilities and roles. Roles give defaults; admins can grant or deny
 * individual capabilities per user from Admin → Staff (stored as WP user caps).
 */
class MQP_Roles {

	public static function capabilities() {
		return array(
			'mq_access_portal'          => array( 'General', 'Can log in to the portal' ),

			'mq_clock_anywhere'         => array( 'Time clock', 'Can clock in outside the shop' ),
			'mq_bypass_attendance'      => array( 'Time clock', 'Can clock in outside the set hours' ),

			'mq_sales'                  => array( 'Sales', 'Open the Sales tab (Invoice Manager, uploads, own job orders)' ),
			'mq_view_all_sales'         => array( 'Sales', 'See documents and job orders of all sales staff' ),
			'mq_record_payments'        => array( 'Sales', 'Record customer payments' ),
			'mq_assign_sales'           => array( 'Sales', 'Assign a sales person to a job order' ),
			'mq_void_documents'         => array( 'Sales', 'Void quotations, billing statements and invoices' ),
			'mq_price_override'         => array( 'Admin, Finance & Ops', 'Custom prices, discounts and changing invoices after a payment (POS and Invoice Manager)' ),

			'mq_creatives'              => array( 'Creatives', 'Open the Creatives tab; approve files or attach edited designs' ),

			'mq_production'             => array( 'Production & QC', 'Open the Production tab; mark jobs as printed' ),
			'mq_qc'                     => array( 'Production & QC', 'QC: approve as Ready for Delivery or send back' ),
			'mq_view_board'             => array( 'Production & QC', 'View the production monitor board' ),
			'mq_deliver'                => array( 'Production & QC', 'Mark customer notified / delivered' ),

			'mq_pos'                    => array( 'POS', 'Use the POS' ),
			'mq_pos_discount'           => array( 'POS', 'Give discounts in the POS' ),
			'mq_pos_void'               => array( 'POS', 'Void POS receipts' ),

			'mq_view_dashboard'         => array( 'Admin, Finance & Ops', 'Owner dashboard: sales of all brands and branches' ),
			'mq_finance'                => array( 'Admin, Finance & Ops', 'Commissions, attendance reports and audit log' ),
			'mq_manage'                 => array( 'Admin, Finance & Ops', 'Settings: brands, branches, products, staff and permissions' ),
			'mq_reviews'                => array( 'Admin, Finance & Ops', 'Website reviews: approve, hide, add, edit and delete (Admin → Reviews)' ),

		);
	}

	public static function roles() {
		return array(
			'mq_sales_staff'   => array(
				'name' => 'MQ Sales',
				'caps' => array( 'mq_access_portal', 'mq_sales', 'mq_record_payments', 'mq_deliver' ),
			),
			'mq_artist'        => array(
				'name' => 'MQ Creatives',
				'caps' => array( 'mq_access_portal', 'mq_creatives', 'mq_view_board' ),
			),
			// The slug must never equal a capability name: WordPress keeps roles and per-user caps in
			// one list, so resetting the "mq_production" cap used to erase the role (1.31.4).
			'mq_production_staff' => array(
				'name' => 'MQ Production',
				'caps' => array( 'mq_access_portal', 'mq_production', 'mq_view_board' ),
			),
			'mq_qc_officer'    => array(
				'name' => 'MQ QC Officer',
				'caps' => array( 'mq_access_portal', 'mq_production', 'mq_qc', 'mq_view_board', 'mq_deliver' ),
			),
			'mq_cashier'       => array(
				'name' => 'MQ Cashier (POS)',
				'caps' => array( 'mq_access_portal', 'mq_pos' ),
			),
			'mq_finance_ops'   => array(
				'name' => 'MQ Admin / Finance / Ops',
				'caps' => array( 'mq_access_portal', 'mq_sales', 'mq_view_all_sales', 'mq_record_payments', 'mq_assign_sales', 'mq_void_documents', 'mq_view_board', 'mq_deliver', 'mq_finance', 'mq_pos', 'mq_pos_discount', 'mq_pos_void', 'mq_price_override' ),
			),
			'mq_owner'         => array(
				'name' => 'MQ Owner',
				'caps' => array_keys( self::capabilities() ),
			),
			'mq_display'       => array(
				'name' => 'MQ Production Monitor',
				'caps' => array( 'mq_access_portal', 'mq_view_board', 'mq_bypass_attendance', 'mq_clock_anywhere' ),
			),
		);
	}

	/** Old role slugs that clashed with a capability of the same name. */
	const LEGACY_ROLES = array( 'mq_production' => 'mq_production_staff' );

	public static function install() {
		foreach ( array_keys( self::LEGACY_ROLES ) as $old ) {
			remove_role( $old );
		}
		foreach ( self::roles() as $slug => $role ) {
			remove_role( $slug );
			$caps = array( 'read' => true );
			foreach ( $role['caps'] as $cap ) {
				$caps[ $cap ] = true;
			}
			add_role( $slug, $role['name'], $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::capabilities() ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * 1.31.4: moves Production staff to the new role slug and gives the role back to portal staff
	 * who lost it (saving Access reset the "mq_production" cap, which erased the old role).
	 * Returns how many accounts were repaired.
	 */
	public static function migrate_legacy_roles() {
		global $wpdb;
		$key   = $wpdb->get_blog_prefix() . 'capabilities';
		$roles = self::roles();
		$fixed = 0;
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $uid ) {
			$caps = get_user_meta( (int) $uid, $key, true );
			$caps = is_array( $caps ) ? $caps : array();
			if ( ! empty( $caps['administrator'] ) ) {
				continue;
			}
			$has_role = false;
			foreach ( $roles as $slug => $_ ) {
				if ( ! empty( $caps[ $slug ] ) ) {
					$has_role = true;
				}
			}
			$changed = false;
			foreach ( self::LEGACY_ROLES as $old => $new ) {
				if ( array_key_exists( $old, $caps ) && ! $has_role ) {
					// Was their role. A "false" stays as a per-user deny of that capability.
					if ( $caps[ $old ] ) {
						unset( $caps[ $old ] );
					}
					$caps[ $new ] = true;
					$has_role     = true;
					$changed      = true;
				}
				// With another portal role, the old key is a per-user grant/deny of the capability: left as is.
			}
			// A portal staff account (created in Staff or from a sign-up) with no role left lost it to the bug.
			// Only the Production role could be erased that way.
			if ( ! $has_role && metadata_exists( 'user', (int) $uid, 'mqp_branch_id' ) ) {
				$caps['mq_production_staff'] = true;
				$changed                     = true;
			}
			if ( $changed ) {
				update_user_meta( (int) $uid, $key, $caps );
				clean_user_cache( (int) $uid );
				$u = get_userdata( (int) $uid );
				if ( $u ) {
					$u->update_user_level_from_caps();
				}
				$fixed++;
			}
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'signups' ) . ' SET role = %s WHERE role = %s', 'mq_production_staff', 'mq_production' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $fixed;
	}

	public static function uninstall() {
		foreach ( array_keys( self::LEGACY_ROLES ) as $old ) {
			remove_role( $old );
		}
		foreach ( array_keys( self::roles() ) as $slug ) {
			remove_role( $slug );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::capabilities() ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/** Portal role slug of a user (first MQ role found), or 'administrator'. */
	public static function user_role( WP_User $user ) {
		foreach ( $user->roles as $r ) {
			if ( isset( self::roles()[ $r ] ) || 'administrator' === $r ) {
				return $r;
			}
		}
		return '';
	}

	/** Per-user overrides: cap => true (granted) | false (denied). */
	public static function overrides( WP_User $user ) {
		$out = array();
		foreach ( self::capabilities() as $cap => $_ ) {
			if ( array_key_exists( $cap, $user->caps ) ) {
				$out[ $cap ] = (bool) $user->caps[ $cap ];
			}
		}
		return $out;
	}

	/** Effective capability list for the current user. */
	public static function current_caps() {
		$out = array();
		foreach ( array_keys( self::capabilities() ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				$out[] = $cap;
			}
		}
		return $out;
	}

	public static function is_staff( WP_User $user ) {
		return $user->has_cap( 'mq_access_portal' );
	}
}
