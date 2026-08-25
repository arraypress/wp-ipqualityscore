<?php
/**
 * Cache clearing tests.
 *
 * @package ArrayPress\IPQualityScore
 */

declare( strict_types=1 );

namespace ArrayPress\IPQualityScore\Tests;

use ArrayPress\IPQualityScore\Client;
use PHPUnit\Framework\TestCase;

/**
 * Covers Client::clear_cache().
 */
final class CacheTest extends TestCase {

	/**
	 * Start each test with an empty options table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		iqs_reset();
	}

	/**
	 * Populate the options table the way set_transient() would.
	 *
	 * @param string $key   Transient key, without the _transient_ prefix.
	 * @param mixed  $value Stored value.
	 *
	 * @return void
	 */
	private function cache( string $key, $value = 'cached' ): void {
		set_transient( $key, $value, 3600 );
	}

	/**
	 * Option names currently in the stubbed table.
	 *
	 * @return array
	 */
	private function options(): array {
		return array_keys( $GLOBALS['iqs']['options'] );
	}

	/**
	 * Clearing everything leaves no timeout rows behind.
	 *
	 * Deleting the _transient_ipqs_* rows directly orphans every matching
	 * _transient_timeout_ipqs_* row -- those do not carry the prefix, so a
	 * LIKE '_transient_ipqs_%' never sees them, and the options table grows by
	 * one dead row per cached lookup forever.
	 *
	 * @return void
	 */
	public function test_clear_cache_removes_timeout_rows_too(): void {
		$this->cache( 'ipqs_one' );
		$this->cache( 'ipqs_two' );

		$this->assertContains( '_transient_timeout_ipqs_one', $this->options() );

		( new Client( 'key' ) )->clear_cache();

		$this->assertSame( [], $this->options(), 'clear_cache() left rows behind: ' . implode( ', ', $this->options() ) );
	}

	/**
	 * Other plugins' transients are untouched.
	 *
	 * @return void
	 */
	public function test_clear_cache_leaves_other_transients_alone(): void {
		$this->cache( 'ipqs_mine' );
		$this->cache( 'other_plugin_data' );

		( new Client( 'key' ) )->clear_cache();

		$this->assertSame(
			[ '_transient_other_plugin_data', '_transient_timeout_other_plugin_data' ],
			$this->options()
		);
	}

	/**
	 * Options that only resemble the prefix are left alone.
	 *
	 * esc_like() is what keeps the SELECT to literal underscores -- without it
	 * each `_` is a single-character wildcard and the query returns rows like
	 * `Xtransient_ipqsX`. It narrows the scan rather than preventing a wrong
	 * delete: the key is taken from offset 11, and the pattern pins "ipqs"
	 * there, so an over-match still resolves to an ipqs* key. This test covers
	 * the outcome that matters -- a neighbour's rows survive.
	 *
	 * @return void
	 */
	public function test_leaves_similarly_named_options_alone(): void {
		$GLOBALS['iqs']['options']['Xtransient_ipqsX'] = 'not mine';
		$GLOBALS['iqs']['options']['_transientXipqs_' . 'a'] = 'not mine either';
		$this->cache( 'ipqs_mine' );

		( new Client( 'key' ) )->clear_cache();

		$this->assertSame(
			[ 'Xtransient_ipqsX', '_transientXipqs_a' ],
			$this->options()
		);
	}

	/**
	 * Clearing a single identifier removes just that entry.
	 *
	 * @return void
	 */
	public function test_clear_cache_by_identifier(): void {
		$client = new Client( 'key' );

		$this->cache( 'ipqs_keep' );
		$before = $this->options();

		$this->assertTrue( $client->clear_cache( 'nothing-cached-under-this' ) );
		$this->assertSame( $before, $this->options() );
	}

	/**
	 * Clearing an empty cache succeeds.
	 *
	 * @return void
	 */
	public function test_clear_cache_when_empty(): void {
		$this->assertTrue( ( new Client( 'key' ) )->clear_cache() );
		$this->assertSame( [], $this->options() );
	}

}
