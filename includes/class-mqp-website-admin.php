<?php
defined( 'ABSPATH' ) || exit;

/**
 * Before 1.18.1 this was wp-admin → MQ Portal → Website. Its contents (what is waiting, this week,
 * latest requests and uploads, storage) are on MQ Portal → Dashboard now; old links redirect there.
 */
class MQP_Website_Admin {

	const SLUG = 'mq-portal-website';

	public static function url() {
		return MQP_Dev_Admin::url();
	}

	public static function init() {
	}

	public static function waiting() {
		return MQP_Dev_Admin::waiting();
	}
}
