<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use MediaWiki\Extension\Wanda\APIChat;

/**
 * Test stub for APIChat that mocks LLM response without calling external services.
 */
class StubAPIChat extends APIChat {
	/** @var array|false */
	private $llmResult = false;

	/** @var \User|null */
	private $testUser = null;

	/**
	 * Set the LLM result that generateLLMResponse() will return.
	 *
	 * @param array|false $result
	 */
	public function setLlmResult( $result ): void {
		$this->llmResult = $result;
	}

	/**
	 * Set the test user to use for this stub.
	 *
	 * @param \User $user
	 */
	public function setTestUser( \User $user ): void {
		$this->testUser = $user;
	}

	/**
	 * Override getUser to return the test user if set.
	 *
	 * @return \User
	 */
	public function getUser() {
		return $this->testUser ?? parent::getUser();
	}

	/**
	 * Override to return the canned LLM result without calling external services.
	 *
	 * @return array|false
	 */
	protected function generateLLMResponse( ...$args ) {
		return $this->llmResult;
	}

	/**
	 * Override to skip provider validation.
	 *
	 * @return bool
	 */
	protected function validateProviderConfig() {
		return true;
	}

	/**
	 * Override to skip Elasticsearch index detection.
	 *
	 * @return null
	 */
	protected function detectElasticsearchIndex() {
		return null;
	}

	/**
	 * Override to skip Elasticsearch queries.
	 *
	 * @param string $queryText
	 * @return null
	 */
	protected function queryElasticsearch( $queryText ) {
		return null;
	}
}
