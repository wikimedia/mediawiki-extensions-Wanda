<?php

namespace MediaWiki\Extension\Wikai;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

class APIChat extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();
		$userMessage = $params['message'];

		$apiUrl = $this->getConfig()->get( 'LLMApiEndpoint' );
		$response = $this->callLLMApi( $apiUrl, $userMessage );

		$this->getResult()->addValue( null, 'response', $response );
	}

	/**
	 * Invoke LLM API
	 * @param string $apiUrl
	 * @param string $message
	 * @return string
	 */
	private function callLLMApi( $apiUrl, $message ) {
		$url = $apiUrl . '?' . http_build_query( [ 'query' => $message ] );

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		return json_decode( $response, true );
	}

	/**
	 * Get the parameters allowed for API action
	 * @return array{message: array<bool|string>}
	 */
	public function getAllowedParams() {
		return [
			'message' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}
}
