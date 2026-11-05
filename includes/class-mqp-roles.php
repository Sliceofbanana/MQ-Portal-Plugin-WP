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
			'mq_production'    => array(
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

	public static function install() {
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

	public static function uninstall() {
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
