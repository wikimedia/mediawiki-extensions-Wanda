<?php

namespace MediaWiki\Extension\Wanda;

use ApiBase;
use BagOStuff;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;

/** Staged edit API: plan, context, preview, then confirm an opaque proposal ID. */
class APIEdit extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		try {
			if ( !$this->config( 'WandaEnableEditing', false ) ) {
				throw new RuntimeException( 'wanda-edit-disabled' );
			}
			if ( !$this->getUser()->isRegistered() || !$this->getAuthority()->isAllowed( 'wanda-edit' ) ) {
				throw new RuntimeException( 'wanda-edit-permission' );
			}
			$service = $this->createService();
			$service->checkAccess();
			if ( $params['confirm'] ) {
				$data = $service->confirm( $params['proposalid'] );
			} elseif ( $params['stage'] === 'plan' ) {
				$selected = array_map( 'trim', explode( '|', $params['sources'] ) );
				$sources = array_values( array_unique( array_filter( $selected,
					static function ( $source ) {
						return $source !== '';
					}
				) ) );
				$data = $service->plan( $params['message'], $params['title'], $sources );
			} elseif ( $params['stage'] === 'context' ) {
				$data = $service->retrieveContext( $params['batchid'], $params['source'] );
			} elseif ( $params['batchid'] !== '' ) {
				$data = $service->preview( $params['batchid'], $params['title'], $params['summary'] );
			} else {
				$data = $service->previewSingle( $params['message'], $params['title'], $params['summary'] );
			}
		} catch ( RuntimeException $e ) {
			$code = $e->getMessage();
			$messages = self::errorMessages();
			if ( !isset( $messages[$code] ) ) {
				$code = 'wanda-edit-generation-failed';
			}
			$this->dieWithError( $messages[$code], $code === 'wanda-edit-permission' ? 'permissiondenied' : $code );
		}
		foreach ( $data as $key => $value ) {
			$this->getResult()->addValue( null, $key, $value );
		}
	}

	/** Protected seams for network-free tests; no request LLM overrides. */
	protected function createService(): WandaEditService {
		return new WandaEditService( $this->getContext(), $this->createStore(), $this->createHandler() );
	}

	protected function createStore(): WandaEditStore {
		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		if ( $cache->getQoS( BagOStuff::ATTR_DURABILITY ) < BagOStuff::QOS_DURABILITY_SERVICE ) {
			throw new RuntimeException( 'wanda-edit-store-failed' );
		}
		return new WandaEditStore( $cache, WikiMap::getCurrentWikiId(),
			(int)$this->config( 'WandaEditProposalTTL', 1800 ) );
	}

	protected function createHandler(): WandaEditHandler {
		$provider = strtolower( (string)$this->config( 'WandaLLMProvider', 'ollama' ) );
		$endpoint = $provider === 'gemini'
			? 'https://generativelanguage.googleapis.com/v1' : 'http://ollama:11434/api/';
		return new WandaEditHandler(
			$provider, $this->config( 'WandaLLMModel', 'gemma:2b' ), $this->config( 'WandaLLMApiKey', '' ),
			$this->config( 'WandaLLMApiEndpoint', $endpoint ), (int)$this->config( 'WandaLLMTimeout', 30 ),
			max( 4096, (int)$this->config( 'WandaLLMMaxTokens', 4096 ) ), $this->config( 'HTTPProxy', '' )
		);
	}

	private function config( string $key, $default ) {
		return $this->getConfig()->has( $key ) ? ( $this->getConfig()->get( $key ) ?? $default ) : $default;
	}

	/** Explicit allowlist: backend exception text and credentials never escape. */
	public static function errorMessages(): array {
		$messages = [
			'wanda-edit-empty' => 'wanda-api-error-empty-question',
			'wanda-edit-generation-failed' => 'wanda-api-error-generation-failed',
		];
		foreach ( [
			'disabled', 'permission', 'notitle', 'readonly', 'store-failed', 'invalid-record', 'busy',
			'source-unsupported', 'context-required', 'no-candidates', 'context-failed', 'context-empty',
			'not-candidate', 'preview-failed', 'stale', 'save-uncertain', 'namespace', 'too-large', 'rate-limited',
		] as $suffix ) {
			$messages['wanda-edit-' . $suffix] = 'wanda-api-error-edit-' . $suffix;
		}
		foreach ( [
			'wanda-plan-invalid', 'wanda-plan-failed', 'wanda-plan-question', 'wanda-plan-ambiguous',
			'wanda-plan-unsupported', 'wanda-plan-empty', 'wanda-plan-invalid-response',
			'wanda-discovery-invalid-scope', 'wanda-discovery-invalid-category', 'wanda-discovery-category-unreadable',
			'wanda-discovery-query-failed', 'wanda-discovery-invalid-namespaces',
		] as $code ) {
			$messages[$code] = 'wanda-api-error-edit-plan-failed';
		}
		foreach ( [
			'invalid-query', 'query-failed', 'limit-too-small', 'source-disabled', 'source-unsupported',
			'source-permissions-unavailable', 'source-unavailable', 'source-bounds-unavailable', 'source-invalid',
		] as $suffix ) {
			$messages['wanda-context-' . $suffix] = 'wanda-api-error-edit-context-failed';
		}
		return $messages;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		$params = [];
		foreach ( [ 'message', 'title', 'batchid', 'proposalid', 'source', 'summary' ] as $name ) {
			$params[$name] = [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ];
		}
		$params['stage'] = [
			ParamValidator::PARAM_TYPE => [ 'plan', 'context', 'preview' ], ParamValidator::PARAM_DEFAULT => 'preview',
		];
		$params['sources'] = [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => 'wiki' ];
		$params['confirm'] = [ ParamValidator::PARAM_TYPE => 'boolean', ParamValidator::PARAM_DEFAULT => false ];
		return $params;
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=wandaedit&title=John%20Smith&message=Change%20the%20Phone%20number%20field%20to%20555-1212'
				=> 'apihelp-wandaedit-example-preview',
		];
	}
}
