<?php
namespace FluentAuthKakao;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static ?self $instance = null;

	public static function getInstance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		( new KakaoHandler() )->register();
		( new Settings() )->register();
	}
}
