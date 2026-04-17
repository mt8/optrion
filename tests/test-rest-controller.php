<?php
/**
 * REST controller smoke tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Quarantine;
use Optrion\Rest_Controller;
use Optrion\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Verifies route registration, permission gating, and a few end-to-end responses.
 *
 * @coversDefaultClass \Optrion\Rest_Controller
 */
class RestControllerTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Bootstraps schema, registers routes, and creates an admin fixture.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		// Accessing rest_get_server() triggers rest_api_init, which the plugin
		// already hooked into during Plugin::boot() at bootstrap time.
		rest_get_server();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * All optrion routes are registered on rest_api_init.
	 */
	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . Rest_Controller::NAMESPACE_V1 . '/options', $routes );
		$this->assertArrayHasKey( '/' . Rest_Controller::NAMESPACE_V1 . '/stats', $routes );
		$this->assertArrayHasKey( '/' . Rest_Controller::NAMESPACE_V1 . '/export', $routes );
		$this->assertArrayHasKey( '/' . Rest_Controller::NAMESPACE_V1 . '/quarantine', $routes );
	}

	/**
	 * Unauthenticated callers are rejected.
	 */
	public function test_permission_denied_without_manage_options(): void {
		wp_set_current_user( 0 );
		$req      = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE_V1 . '/options' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * GET /options returns items with the raw signal fields.
	 */
	public function test_list_options_returns_items_with_signal_fields(): void {
		wp_set_current_user( $this->admin_id );
		add_option( 'rest_test_opt', 'hello', '', 'no' );
		$req = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE_V1 . '/options' );
		$req->set_param( 'search', 'rest_test_opt' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'autoload_total_size', $data );
		$names = array_column( $data['items'], 'option_name' );
		$this->assertContains( 'rest_test_opt', $names );
		$row = array_values( array_filter( $data['items'], static fn ( $item ) => 'rest_test_opt' === $item['option_name'] ) )[0];
		$this->assertArrayNotHasKey( 'score', $row );
		$this->assertArrayHasKey( 'accessor', $row );
		$this->assertArrayHasKey( 'autoload', $row );
		$this->assertArrayHasKey( 'is_autoload', $row );
		$this->assertArrayHasKey( 'size', $row );
		$this->assertArrayHasKey( 'tracking', $row );
		$this->assertArrayHasKey( 'active', $row['accessor'] );
	}

	/**
	 * GET /options filters out Transient API rows (_transient_* / _site_transient_*).
	 */
	public function test_list_options_excludes_transients(): void {
		wp_set_current_user( $this->admin_id );
		set_transient( 'optrion_rest_test_transient', 'x', 300 );
		set_site_transient( 'optrion_rest_test_site_transient', 'x', 300 );
		$req = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE_V1 . '/options' );
		$req->set_param( 'per_page', 200 );
		$req->set_param( 'search', 'optrion_rest_test' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );
		$names = array_column( $response->get_data()['items'], 'option_name' );
		foreach ( $names as $name ) {
			$this->assertStringStartsNotWith( '_transient_', (string) $name );
			$this->assertStringStartsNotWith( '_site_transient_', (string) $name );
		}
	}

	/**
	 * GET /options/{name} returns 404 for unknown names.
	 */
	public function test_option_detail_404(): void {
		wp_set_current_user( $this->admin_id );
		$req      = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE_V1 . '/options/never_ever_existed_xyz' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * GET /stats returns totals and autoload size.
	 */
	public function test_stats_endpoint(): void {
		wp_set_current_user( $this->admin_id );
		$req      = new WP_REST_Request( 'GET', '/' . Rest_Controller::NAMESPACE_V1 . '/stats' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_options', $data );
		$this->assertArrayHasKey( 'autoload_total_size', $data );
		$this->assertGreaterThan( 0, $data['total_options'] );
	}

	/**
	 * DELETE /options deletes rows and returns a backup path.
	 */
	public function test_delete_options_endpoint(): void {
		wp_set_current_user( $this->admin_id );
		add_option( 'rest_delete_me', 'x', '', 'no' );
		$req = new WP_REST_Request( 'DELETE', '/' . Rest_Controller::NAMESPACE_V1 . '/options' );
		$req->set_param( 'names', array( 'rest_delete_me' ) );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['deleted'] );
		$this->assertFalse( get_option( 'rest_delete_me', false ) );
	}

	/**
	 * POST /quarantine creates manifest entries.
	 */
	public function test_create_quarantine_endpoint(): void {
		wp_set_current_user( $this->admin_id );
		add_option( 'rest_q_opt', 'y', '', 'no' );
		$req = new WP_REST_Request( 'POST', '/' . Rest_Controller::NAMESPACE_V1 . '/quarantine' );
		$req->set_param( 'names', array( 'rest_q_opt' ) );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['quarantined'] );
		$this->assertSame( 'rest_q_opt', $data['quarantined'][0]['option_name'] );
	}

	/**
	 * POST /import/preview surfaces add/overwrite counts.
	 */
	public function test_import_preview_endpoint(): void {
		wp_set_current_user( $this->admin_id );
		$payload = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'rest_import_preview_new',
						'option_value' => 'v',
						'autoload'     => 'no',
					),
				),
			)
		);
		$req     = new WP_REST_Request( 'POST', '/' . Rest_Controller::NAMESPACE_V1 . '/import/preview' );
		$req->set_param( 'payload', $payload );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['add'] );
	}
}
