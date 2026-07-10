<?php

namespace MediaWiki\Extension\Wanda;

use ApiBase;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use TextContent;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Write API module that lets authorised users ask Wanda to edit a wiki page.
 *
 * The module is two-phase so edits are always previewed before they are
 * applied (T409213):
 *
 *  - preview (confirm=false, the default): the editing instruction and the
 *    page's current wikitext are sent to the LLM, and the proposed new wikitext
 *    plus a rendered diff are returned. Nothing is saved.
 *  - confirm (confirm=true): the previously previewed wikitext (echoed back by
 *    the client) is saved as a new revision attributed to the requesting user.
 *
 * Editing is gated by both the WandaEnableEditing feature flag and the
 * `wanda-edit` user right, and additionally respects the target page's own edit
 * permissions so Wanda can never bypass protection or access-control extensions.
 *
 * Supports free-text/prose edits as well as infobox / template field edits on a
 * single page. If the target page does not exist yet, Wanda proposes the full
 * wikitext for the new page and creates it on confirmation.
 */
class APIEdit extends ApiBase {
	/** @var string */
	private $llmProvider;
	/** @var string */
	private $llmModel;
	/** @var string */
	private $llmApiKey;
	/** @var string */
	private $llmApiEndpoint;
	/** @var int */
	private $timeout;
	/** @var int */
	private $maxTokens;
	/** @var string */
	private $proxy;

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		$config = $this->getConfig();
		$this->llmProvider = strtolower( $config->get( 'WandaLLMProvider' ) ?? 'ollama' );
		$this->llmModel = $config->get( 'WandaLLMModel' ) ?? 'gemma:2b';
		$this->llmApiKey = $config->get( 'WandaLLMApiKey' ) ?? '';

		$defaultEndpoint = 'http://ollama:11434/api/';
		if ( $this->llmProvider === 'gemini' ) {
			$defaultEndpoint = 'https://generativelanguage.googleapis.com/v1';
		}
		$this->llmApiEndpoint = $config->get( 'WandaLLMApiEndpoint' ) ?? $defaultEndpoint;
		$this->timeout = (int)( $config->get( 'WandaLLMTimeout' ) ?? 30 );
		// Edits echo the whole page back, so allow far more tokens than the chat default.
		$this->maxTokens = max( 4096, (int)( $config->get( 'WandaLLMMaxTokens' ) ?? 4096 ) );
		$this->proxy = MediaWikiServices::getInstance()->getMainConfig()->get( 'HTTPProxy' ) ?? '';
	}

	public function execute() {
		$params = $this->extractRequestParams();

		// Feature flag + dedicated right.
		if ( !$this->getConfig()->get( 'WandaEnableEditing' ) ) {
			$this->dieWithError( 'wanda-api-error-edit-disabled', 'wanda-edit-disabled' );
		}
		if ( !$this->getUser()->isAllowed( 'wanda-edit' ) ) {
			$this->dieWithError( 'wanda-api-error-edit-permission', 'permissiondenied' );
		}

		$instruction = trim( (string)$params['message'] );
		$confirm = !empty( $params['confirm'] );

		$title = Title::newFromText( (string)$params['title'] );
		if ( !$title || !$title->canExist() ) {
			$this->dieWithError( 'wanda-api-error-edit-notitle', 'wanda-edit-notitle' );
		}
		$pageExists = $title->exists();

		// Respect the page's own edit/create permissions (protection, Lockdown, etc.).
		$pm = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$pm->userCan( 'edit', $this->getUser(), $title ) ||
			( !$pageExists && !$pm->userCan( 'create', $this->getUser(), $title ) )
		) {
			$this->dieWithError( 'wanda-api-error-edit-permission', 'permissiondenied' );
		}

		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		if ( $pageExists ) {
			$currentContent = $wikiPage->getContent();
		} else {
			$currentContent = MediaWikiServices::getInstance()->getContentHandlerFactory()
				->getContentHandler( $title->getContentModel() )
				->makeEmptyContent();
		}
		if ( !( $currentContent instanceof TextContent ) ) {
			$this->dieWithError( 'wanda-api-error-edit-notitle', 'wanda-edit-notitle' );
		}
		$currentText = $currentContent->getText();

		$this->overrideLlmParameters( $params );

		if ( $confirm ) {
			$this->doConfirm( $title, $wikiPage, $currentContent, $instruction, (string)$params['newtext'] );
			return;
		}

		$this->doPreview( $title, $currentContent, $currentText, $instruction );
	}

	/**
	 * Preview path: ask the LLM for the proposed wikitext and return a diff.
	 *
	 * @param Title $title
	 * @param TextContent $currentContent
	 * @param string $currentText
	 * @param string $instruction
	 * @return void
	 */
	private function doPreview( Title $title, TextContent $currentContent, string $currentText, string $instruction ) {
		if ( $instruction === '' ) {
			$this->dieWithError( 'wanda-api-error-empty-question', 'wanda-edit-empty' );
		}

		$handler = new WandaEditHandler(
			$this->llmProvider,
			$this->llmModel,
			$this->llmApiKey,
			$this->llmApiEndpoint,
			$this->timeout,
			$this->maxTokens,
			$this->proxy
		);
		$proposal = $handler->proposeEdit( $instruction, $currentText );

		// A failed or unparseable LLM response is an error, not a "no change"
		// answer from the model — tell the user to retry instead.
		if ( !empty( $proposal['failed'] ) ) {
			$this->dieWithError( 'wanda-api-error-generation-failed', 'wanda-edit-generation-failed' );
		}

		$result = $this->getResult();
		if ( empty( $proposal['changed'] ) || trim( $proposal['newtext'] ) === $currentText ) {
			$result->addValue( null, 'changed', false );
			$result->addValue( null, 'explanation', $proposal['explanation'] ?? '' );
			return;
		}

		$newText = $proposal['newtext'];
		$contentHandler = $currentContent->getContentHandler();
		$newContent = $contentHandler->unserializeContent( $newText );

		$diff = $this->renderDiff( $currentContent, $newContent );

		$result->addValue( null, 'changed', true );
		$result->addValue( null, 'title', $title->getPrefixedText() );
		$result->addValue( null, 'newtext', $newText );
		$result->addValue( null, 'diff', $diff );
		$result->addValue( null, 'summary', $this->buildSummary( $instruction ) );
		$result->addValue( null, 'explanation', $proposal['explanation'] ?? '' );
	}

	/**
	 * Confirm path: save the previously previewed wikitext as the requesting user.
	 *
	 * @param Title $title
	 * @param \WikiPage $wikiPage
	 * @param TextContent $currentContent
	 * @param string $instruction
	 * @param string $newText The exact wikitext the user previewed
	 * @return void
	 */
	private function doConfirm(
		Title $title,
		$wikiPage,
		TextContent $currentContent,
		string $instruction,
		string $newText
	) {
		if ( trim( $newText ) === '' ) {
			$this->dieWithError( 'wanda-api-error-edit-failed', 'wanda-edit-nonewtext' );
		}

		$contentHandler = $currentContent->getContentHandler();
		$newContent = $contentHandler->unserializeContent( $newText );

		$isNew = !$title->exists();
		$updater = $wikiPage->newPageUpdater( $this->getUser() );
		$updater->setContent( SlotRecord::MAIN, $newContent );
		$summary = CommentStoreComment::newUnsavedComment( $this->buildSummary( $instruction ) );
		$newRev = $updater->saveRevision( $summary, $isNew ? EDIT_NEW : EDIT_UPDATE );

		if ( !$updater->wasSuccessful() || $newRev === null ) {
			$this->dieWithError( 'wanda-api-error-edit-failed', 'wanda-edit-failed' );
		}

		$diffurl = $isNew
			? $title->getFullURL()
			: $title->getFullURL( [ 'diff' => $newRev->getId() ] );

		$result = $this->getResult();
		$result->addValue( null, 'result', 'success' );
		$result->addValue( null, 'title', $title->getPrefixedText() );
		$result->addValue( null, 'new', $isNew );
		$result->addValue( null, 'newrevid', $newRev->getId() );
		$result->addValue( null, 'diffurl', $diffurl );
	}

	/**
	 * Render a standard MediaWiki diff table between two content objects.
	 *
	 * @param TextContent $oldContent
	 * @param \Content $newContent
	 * @return string HTML diff table (uses the mediawiki.diff.styles module)
	 */
	private function renderDiff( TextContent $oldContent, $newContent ): string {
		$slotDiffRenderer = $oldContent->getContentHandler()->getSlotDiffRenderer( $this->getContext() );
		$rows = $slotDiffRenderer->getDiff( $oldContent, $newContent );

		return '<table class="diff diff-contentalign-left"><colgroup>' .
			'<col class="diff-marker"><col class="diff-content">' .
			'<col class="diff-marker"><col class="diff-content"></colgroup>' .
			'<tbody>' . $rows . '</tbody></table>';
	}

	/**
	 * Build the edit summary, crediting the user and noting the AI assistance.
	 *
	 * @param string $instruction
	 * @return string
	 */
	private function buildSummary( string $instruction ): string {
		$short = trim( $instruction );
		if ( mb_strlen( $short ) > 200 ) {
			$short = mb_substr( $short, 0, 200 ) . '…';
		}
		return $this->msg( 'wanda-edit-summary', $short )->inContentLanguage()->text();
	}

	/**
	 * Apply per-request LLM overrides, mirroring APIChat behaviour.
	 *
	 * @param array $params
	 * @return void
	 */
	private function overrideLlmParameters( array $params ) {
		if ( !empty( $params['provider'] ) ) {
			$this->llmProvider = strtolower( trim( $params['provider'] ) );
		}
		if ( !empty( $params['model'] ) ) {
			$this->llmModel = trim( $params['model'] );
		}
		if ( !empty( $params['apikey'] ) ) {
			$this->llmApiKey = trim( $params['apikey'] );
		}
		if ( !empty( $params['apiendpoint'] ) ) {
			$this->llmApiEndpoint = trim( $params['apiendpoint'] );
		}
		if ( isset( $params['timeout'] ) && is_numeric( $params['timeout'] ) ) {
			$this->timeout = (int)$params['timeout'];
		}
		if ( isset( $params['maxtokens'] ) && is_numeric( $params['maxtokens'] ) ) {
			$this->maxTokens = max( 256, (int)$params['maxtokens'] );
		}
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'message' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'confirm' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				ParamValidator::PARAM_REQUIRED => false,
			],
			'newtext' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_DEFAULT => '',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'provider' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'model' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'apikey' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'apiendpoint' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'timeout' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'maxtokens' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
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
