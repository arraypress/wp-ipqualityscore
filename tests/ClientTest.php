<?php
/**
 * The IPQualityScore client.
 *
 * The IP and email checks run on a checkout with a 15-second timeout. IPQS
 * also bills per lookup, which makes retrying a failing endpoint expensive in
 * a second way: a 429 re-asked on every request burns the next allowance as
 * fast as it is granted.
 *
 * @package ArrayPress\IPQualityScore
 */

declare( strict_types=1 );

namespace ArrayPress\IPQualityScore\Tests;

use ArrayPress\IPQualityScore\Client;
use ArrayPress\IPQualityScore\Response\Email;
use ArrayPress\IPQualityScore\Response\IP;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Class ClientTest
 */
final class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		iqs_reset();
	}

	protected function tearDown(): void {
		iqs_reset();

		parent::tearDown();
	}

	public function test_a_lookup_returns_an_ip_response(): void {
		iqs_will_return( iqs_ok( [ 'success' => true, 'fraud_score' => 75 ] ) );

		$this->assertInstanceOf( IP::class, ( new Client( 'key' ) )->check_ip( '8.8.8.8' ) );
	}

	public function test_an_invalid_ip_is_refused_without_a_request(): void {
		$result = ( new Client( 'key' ) )->check_ip( 'not-an-ip' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_ip', $result->get_error_code() );
		$this->assertSame( 0, iqs_request_count(), 'nothing should reach the network' );
	}

	public function test_a_repeat_lookup_is_served_from_cache(): void {
		iqs_will_return( iqs_ok( [ 'success' => true, 'fraud_score' => 10 ] ) );

		$client = new Client( 'key' );
		$client->check_ip( '8.8.8.8' );
		$client->check_ip( '8.8.8.8' );

		$this->assertSame( 1, iqs_request_count() );
	}

	/** ---------------------------------------------------------------------
	 * Failure handling
	 * -------------------------------------------------------------------- */

	/**
	 * The regression this file exists for. Without a negative cache an outage
	 * costs every visitor the full 15-second timeout, one after another.
	 */
	public function test_a_failed_lookup_is_not_immediately_retried(): void {
		iqs_will_return( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$client = new Client( 'key' );
		$client->check_ip( '8.8.8.8' );
		$second = $client->check_ip( '8.8.8.8' );

		$this->assertSame( 1, iqs_request_count(), 'the dead endpoint must not be hit twice' );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'ipqs_recent_failure', $second->get_error_code() );
	}

	/**
	 * IPQS bills per lookup, so an exhausted allowance retried per request
	 * spends the next one as fast as it arrives.
	 */
	public function test_an_http_error_is_not_immediately_retried(): void {
		iqs_will_return( [ 'code' => 429, 'body' => '{"success":false,"message":"Insufficient credits"}' ] );

		$client = new Client( 'key' );
		$client->check_ip( '8.8.8.8' );
		$client->check_ip( '8.8.8.8' );

		$this->assertSame( 1, iqs_request_count() );
	}

	/**
	 * One bad lookup must not blind the caller to every other address.
	 */
	public function test_a_failure_is_remembered_per_address(): void {
		iqs_will_return( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$client = new Client( 'key' );
		$client->check_ip( '8.8.8.8' );

		iqs_will_return( iqs_ok( [ 'success' => true, 'fraud_score' => 5 ] ) );

		$this->assertInstanceOf( IP::class, $client->check_ip( '1.1.1.1' ) );
	}

	/**
	 * The IP and email endpoints are separate lookups and are billed
	 * separately, so a dead IP check must not suppress email validation.
	 */
	public function test_an_ip_failure_does_not_suppress_an_email_check(): void {
		iqs_will_return( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$client = new Client( 'key' );
		$client->check_ip( '8.8.8.8' );

		iqs_will_return( iqs_ok( [ 'success' => true, 'valid' => true ] ) );

		$this->assertInstanceOf( Email::class, $client->validate_email( 'buyer@example.com' ) );
	}

	/**
	 * The same lookup with different parameters is a different question, so
	 * the two must not share a cached failure either.
	 */
	public function test_different_parameters_are_a_different_lookup(): void {
		iqs_will_return( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$client = new Client( 'key' );
		$client->check_ip( '8.8.8.8' );

		iqs_will_return( iqs_ok( [ 'success' => true, 'fraud_score' => 5 ] ) );

		$this->assertInstanceOf(
			IP::class,
			$client->check_ip( '8.8.8.8', [ 'strictness' => 2 ] ),
			'a different strictness is a different request'
		);
	}

	public function test_failure_caching_can_be_switched_off(): void {
		iqs_will_return( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$client = ( new Client( 'key' ) )->set_failure_ttl( 0 );
		$client->check_ip( '8.8.8.8' );
		$client->check_ip( '8.8.8.8' );

		$this->assertSame( 2, iqs_request_count() );
	}

	/**
	 * With caching off there is nowhere to record a failure, so every lookup
	 * has to go out. The guard must not silently suppress them.
	 */
	public function test_failures_are_not_suppressed_when_caching_is_off(): void {
		iqs_will_return( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$client = new Client( 'key', false );
		$client->check_ip( '8.8.8.8' );
		$client->check_ip( '8.8.8.8' );

		$this->assertSame( 2, iqs_request_count() );
	}

	public function test_the_failure_window_is_configurable(): void {
		$client = new Client( 'key' );

		$this->assertSame( 60, $client->get_failure_ttl(), 'a sensible default' );
		$this->assertSame( 30, $client->set_failure_ttl( 30 )->get_failure_ttl() );
		$this->assertSame( 0, $client->set_failure_ttl( -5 )->get_failure_ttl(), 'never negative' );
	}
}
