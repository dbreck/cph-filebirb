<?php
/**
 * PHPUnit bootstrap (runs inside wp-env's tests-cli container).
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

$cphfb_root = dirname( __DIR__ );

require_once $cphfb_root . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $cphfb_root . '/vendor/yoast/phpunit-polyfills' );
}

$cphfb_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $cphfb_tests_dir ) {
	$cphfb_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: $cphfb_root . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $cphfb_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $cphfb_root ): void {
		require $cphfb_root . '/cph-filebird.php';
	}
);

require $cphfb_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';

// Real (non-temporary) tables, created before any test transaction starts.
\CPH\FileBird\Install::create_tables();
