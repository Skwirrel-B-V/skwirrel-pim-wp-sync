<?php
/**
 * Skwirrel JSON-RPC 2.0 API Client.
 *
 * Supports Bearer token and X-Skwirrel-Api-Token authentication.
 * All requests require X-Skwirrel-Api-Version: 2 header.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_JsonRpc_Client {

	private string $endpoint;
	private string $auth_type;
	private string $auth_token;
	private int $timeout;
	private int $retries;
	private Skwirrel_WC_Sync_Logger $logger;
	private int $request_id = 0;

	public function __construct(
		string $endpoint,
		string $auth_type,
		string $auth_token,
		int $timeout = 30,
		int $retries = 2
	) {
		$this->endpoint   = rtrim( $endpoint, '/' );
		$this->auth_type  = $auth_type;
		$this->auth_token = $auth_token;
		$this->timeout    = max( 5, min( 120, $timeout ) );
		$this->retries    = max( 0, min( 5, $retries ) );
		$this->logger     = new Skwirrel_WC_Sync_Logger();
	}

	/**
	 * Call a JSON-RPC method.
	 *
	 * @param string $method Method name (e.g. getProducts, getProductsByFilter)
	 * @param array<string, mixed> $params Method parameters
	 * @return array{success: bool, result?: mixed, error?: array{code: int, message: string, data?: mixed}}
	 */
	public function call( string $method, array $params = [] ): array {
		++$this->request_id;
		$body = [
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => $this->request_id,
		];

		$headers = [
			'Content-Type'           => 'application/json',
			'Accept'                 => 'application/json',
			'X-Skwirrel-Api-Version' => '2',
		];

		if ( $this->auth_type === 'bearer' ) {
			$headers['Authorization'] = 'Bearer ' . $this->auth_token;
		} elseif ( $this->auth_type === 'token' ) {
			$headers['X-Skwirrel-Api-Token'] = $this->auth_token;
		}

		$attempt    = 0;
		$last_error = null;

		while ( $attempt <= $this->retries ) {
			$response = wp_remote_post(
				$this->endpoint,
				[
					'timeout'   => $this->timeout,
					'headers'   => $headers,
					'body'      => wp_json_encode( $body ),
					'sslverify' => true,
				]
			);

			$code     = wp_remote_retrieve_response_code( $response );
			$body_raw = wp_remote_retrieve_body( $response );

			if ( is_wp_error( $response ) ) {
				$last_error = [
					'code'    => -1,
					'message' => $response->get_error_message(),
				];
				$this->logger->warning(
					'JSON-RPC request failed',
					[
						'error'   => $last_error,
						'attempt' => $attempt + 1,
					]
				);
				++$attempt;
				if ( $attempt <= $this->retries ) {
					usleep( 500000 * $attempt ); // 0.5s, 1s, 1.5s...
				}
				continue;
			}

			$decoded = json_decode( $body_raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$last_error = [
					'code'    => -32700,
					'message' => 'Invalid JSON response',
				];
				$this->logger->error( 'Invalid JSON response', [ 'body' => substr( $body_raw, 0, 500 ) ] );
				break;
			}

			if ( $code >= 400 ) {
				$last_error = [
					'code'    => $code,
					'message' => $decoded['error']['message'] ?? wp_remote_retrieve_response_message( $response ),
					'data'    => $decoded['error']['data'] ?? null,
				];
				$this->logger->error(
					'API error response',
					[
						'code'  => $code,
						'error' => $last_error,
					]
				);
				break;
			}

			if ( isset( $decoded['error'] ) ) {
				$last_error = [
					'code'    => $decoded['error']['code'] ?? -32603,
					'message' => $decoded['error']['message'] ?? 'Unknown error',
					'data'    => $decoded['error']['data'] ?? null,
				];
				$this->logger->error( 'JSON-RPC error', $last_error );
				return [
					'success' => false,
					'error'   => $last_error,
				];
			}

			return [
				'success' => true,
				'result'  => $decoded['result'] ?? null,
			];
		}

		return [
			'success' => false,
			'error'   => $last_error ?? [
				'code'    => -1,
				'message' => 'Unknown error',
			],
		];
	}

	/**
	 * Test connection with a minimal getProducts call.
	 */
	public function test_connection(): array {
		$result = $this->call(
			'getProducts',
			[
				'page'                         => 1,
				'limit'                        => 1,
				'include_product_status'       => false,
				'include_product_translations' => false,
				'include_attachments'          => false,
				'include_trade_items'          => false,
				'include_categories'           => false,
			]
		);

		if ( $result['success'] ) {
			$this->logger->info( 'Connection test successful' );
		}

		return $result;
	}
}
