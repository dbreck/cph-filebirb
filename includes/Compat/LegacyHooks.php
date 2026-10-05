<?php
/**
 * Legacy FileBird hook compatibility.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Compat;

use CPH\FileBirb\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Thin owner of the legacy hook map. The map itself lives in Hooks::ACTIONS / Hooks::FILTERS,
 * and every mutation fires through Hooks so the `fbv_*` twins always run after `cphfb_*`.
 */
final class LegacyHooks {

	/**
	 * Singleton instance.
	 *
	 * @var LegacyHooks|null
	 */
	private static ?LegacyHooks $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return LegacyHooks
	 */
	public static function get_instance(): LegacyHooks {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Nothing to register; twins fire inline from Hooks.
	 */
	private function __construct() {}

	/**
	 * The full cphfb short name => legacy name map.
	 *
	 * @return array<string,string>
	 */
	public function map(): array {
		return array_merge( Hooks::ACTIONS, Hooks::FILTERS );
	}
}
