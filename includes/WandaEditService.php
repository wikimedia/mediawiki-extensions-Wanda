<?php

namespace MediaWiki\Extension\Wanda;

use ApiMain;
use IDBAccessObject;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\Wanda\Hooks\ChangeTagHooks;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use RuntimeException;
use WikitextContent;

/** Server-side orchestration. No client-supplied save text, endpoints or API flags. */
class WandaEditService {

	/** @var IContextSource */
	private $context;
	/** @var WandaEditStore */
	private $store;
	/** @var WandaEditHandler */
	private $handler;
	/** @var string */
	private $owner;

	public function __construct( IContextSource $context, WandaEditStore $store, WandaEditHandler $handler ) {
		$this->context = $context;
		$this->store = $store;
		$this->handler = $handler;
		$this->owner = (string)$context->getUser()->getId();
	}

	public function checkAccess(): void {
		if ( !$this->config( 'WandaEnableEditing', false ) ) {
			throw new RuntimeException( 'wanda-edit-disabled' );
		}
		// Anonymous ownership cannot be securely represented by a shared IP address.
		if ( !$this->context->getUser()->isRegistered()
			|| !$this->context->getAuthority()->isAllowed( 'wanda-edit' )
		) {
			throw new RuntimeException( 'wanda-edit-permission' );
		}
		if ( MediaWikiServices::getInstance()->getReadOnlyMode()->isReadOnly() ) {
			throw new RuntimeException( 'wanda-edit-readonly' );
		}
	}

	/** @return array Public batch metadata, never model-generated candidate titles. */
	public function plan( string $message, string $titleText, array $sources ): array {
		$this->checkAccess();
		$this->instruction( $message );
		$single = trim( $titleText ) !== '';
		$title = $single ? $this->target( $titleText ) : null;
		if ( $title ) {
			$this->snapshot( $title );
		}
		$this->rateLimit();
		$plan = $this->createPlanner()->plan( $message, $sources, $single );
		if ( !is_string( $plan['researchQuery'] ?? null ) || !is_array( $plan['sources'] ?? null )
			|| !is_array( $plan['scope'] ?? null )
		) {
			throw new RuntimeException( 'wanda-edit-generation-failed' );
		}
		$plan['instruction'] = $message;
		$required = [];
		if ( trim( $plan['researchQuery'] ) !== '' ) {
			$available = $this->createContext()->availableSources();
			// Never silently discard a requested unavailable source during research.
			foreach ( array_unique( array_merge( $sources, $plan['sources'] ) ) as $source ) {
				if ( !is_string( $source ) || !in_array( $source, $sources, true )
					|| !in_array( $source, $available, true )
				) {
					throw new RuntimeException( 'wanda-edit-source-unsupported' );
				}
				$required[] = $source;
			}
			if ( !$required ) {
				throw new RuntimeException( 'wanda-edit-context-required' );
			}
		}
		$plan['sources'] = $required;
		$ceiling = WandaPageDiscovery::normalizeMaxLimit(
			(int)$this->config( 'WandaBatchEditMaxPagesLimit', WandaPageDiscovery::MAX_LIMIT )
		);
		$maxPages = WandaPageDiscovery::normalizeLimit(
			(int)$this->config( 'WandaBatchEditMaxPages', 10 ), $ceiling
		);
		if ( $title ) {
			$plan['scope'] = [ 'type' => 'single', 'query' => $title->getPrefixedText() ];
			$found = [ 'titles' => [ $title->getPrefixedText() ], 'truncated' => false ];
		} else {
			$plan['scope'] = WandaPageDiscovery::normalizeScope( $plan['scope'] );
			$found = $this->createDiscovery( $maxPages, $ceiling )->discover( $plan['scope'] );
		}
		$candidates = [];
		foreach ( array_slice( $found['titles'], 0, $maxPages ) as $candidate ) {
			$candidateTitle = $this->target( $candidate );
			$snapshot = $this->snapshot( $candidateTitle );
			if ( !$single && !$snapshot['pageid'] ) {
				continue;
			}
			$candidates[$candidateTitle->getPrefixedText()] = [ 'title' => $candidateTitle->getPrefixedText() ];
		}
		if ( !$candidates ) {
			throw new RuntimeException( 'wanda-edit-no-candidates' );
		}
		$batch = [ 'plan' => $plan, 'candidates' => array_keys( $candidates ), 'contexts' => [], 'previews' => [] ];
		$id = $this->store->create( 'batch', $this->owner, $batch );
		return [
			'batchid' => $id,
			'scope' => $plan['scope']['type'] . ': ' . $plan['scope']['query'],
			'truncated' => (bool)$found['truncated'] || count( $found['titles'] ) > $maxPages,
			'maxpages' => $maxPages, 'candidates' => array_values( $candidates ), 'sources' => $required,
		];
	}

	public function retrieveContext( string $batchId, string $source ): array {
		$this->checkAccess();
		return $this->store->withLock( 'batch', $batchId, $this->owner, function ( array $batch ) use (
			$batchId, $source
		) {
			if ( !in_array( $source, $batch['plan']['sources'], true ) ) {
				throw new RuntimeException( 'wanda-edit-source-unsupported' );
			}
			if ( isset( $batch['contexts'][$source] ) ) {
				$snapshot = $batch['contexts'][$source];
				if ( !is_array( $snapshot ) ) {
					throw new RuntimeException( 'wanda-edit-context-failed' );
				}
			} else {
				$this->rateLimit();
				// Reserve budget before work; failed workers cannot enable unbounded retries.
				$batch['contexts'][$source] = false;
				$this->store->update( 'batch', $batchId, $this->owner, $batch );
				$snapshot = $this->createContext()->retrieve( $batch['plan']['researchQuery'], $source );
				if ( trim( $snapshot['content'] ?? '' ) === '' || empty( $snapshot['sources'] ) ) {
					throw new RuntimeException( 'wanda-edit-context-empty' );
				}
				$batch['contexts'][$source] = $snapshot;
				$this->store->update( 'batch', $batchId, $this->owner, $batch );
			}
			$this->checkCitations( $snapshot['sources'] );
			return [ 'source' => $source, 'sources' => $snapshot['sources'], 'complete' => true ];
		} );
	}

	public function preview( string $batchId, string $titleText, string $summary = '' ): array {
		$this->checkAccess();
		return $this->store->withLock( 'batch', $batchId, $this->owner, function ( array $batch ) use (
			$batchId, $titleText, $summary
		) {
			$title = $this->target( $titleText );
			$name = $title->getPrefixedText();
			if ( !in_array( $name, $batch['candidates'], true ) ) {
				throw new RuntimeException( 'wanda-edit-not-candidate' );
			}
			$this->snapshot( $title );
			$contextText = '';
			$citations = [];
			foreach ( $batch['plan']['sources'] as $source ) {
				$context = $batch['contexts'][$source] ?? null;
				if ( !is_array( $context ) ) {
					throw new RuntimeException( 'wanda-edit-context-required' );
				}
				$this->checkCitations( $context['sources'] );
				$contextText .= $context['content'] . "\n\n";
				$citations = array_merge( $citations, $context['sources'] );
			}
			if ( isset( $batch['previews'][$name] ) ) {
				if ( !is_array( $batch['previews'][$name] ) ) {
					throw new RuntimeException( 'wanda-edit-preview-failed' );
				}
				if ( $batch['previews'][$name]['proposalid'] !== '' ) {
					$stored = $this->store->get( 'proposal', $batch['previews'][$name]['proposalid'], $this->owner );
					$this->checkPublicBase( $stored );
				}
				return $batch['previews'][$name];
			}
			$this->rateLimit();
			$batch['previews'][$name] = false;
			$this->store->update( 'batch', $batchId, $this->owner, $batch );
			$result = $this->generate( $title, $batch['plan']['instruction'], $contextText, $citations, $summary );
			$batch['previews'][$name] = $result;
			$this->store->update( 'batch', $batchId, $this->owner, $batch );
			return $result;
		} );
	}

	/** Legacy single-page preview; still produces a server-bound proposal. */
	public function previewSingle( string $message, string $titleText, string $summary = '' ): array {
		$this->checkAccess();
		$this->instruction( $message );
		$title = $this->target( $titleText );
		$this->snapshot( $title );
		$this->rateLimit();
		return $this->generate( $title, $message, '', [], $summary );
	}

	private function generate(
		Title $title, string $message, string $context, array $sources, string $summary
	): array {
		// Text and base revision come from the SAME public RevisionRecord.
		$base = $this->snapshot( $title );
		$proposal = $this->handler->proposeEdit( $message, $base['text'], $context );
		if ( !empty( $proposal['failed'] ) || !is_bool( $proposal['changed'] ?? null )
			|| ( $proposal['changed'] && !is_string( $proposal['newtext'] ?? null ) )
		) {
			throw new RuntimeException( 'wanda-edit-generation-failed' );
		}
		$newText = $proposal['newtext'] ?? $base['text'];
		$this->checkSize( $newText );
		if ( ( new WikitextContent( $newText ) )->isRedirect() ) {
			throw new RuntimeException( 'wanda-edit-notitle' );
		}
		$changed = $proposal['changed'] && $newText !== $base['text'];
		$result = [
			'changed' => $changed, 'title' => $title->getPrefixedText(), 'proposalid' => '', 'diff' => '',
			'summary' => $this->summary( $summary, $message ),
			'explanation' => is_string( $proposal['explanation'] ?? null ) ? $proposal['explanation'] : '',
			'sources' => $sources,
		];
		if ( !$changed ) {
			return $result;
		}
		$old = new WikitextContent( $base['text'] );
		$rows = $old->getContentHandler()->getSlotDiffRenderer( $this->context )
			->getDiff( $old, new WikitextContent( $newText ) );
		$result['diff'] = '<table class="diff diff-contentalign-left"><colgroup>' .
			'<col class="diff-marker"><col class="diff-content">' .
			'<col class="diff-marker"><col class="diff-content"></colgroup><tbody>' . $rows . '</tbody></table>';
		$result['proposalid'] = $this->store->create( 'proposal', $this->owner, [
			'title' => $title->getPrefixedText(), 'baseRevId' => $base['baseRevId'], 'pageid' => $base['pageid'],
			'starttimestamp' => $base['starttimestamp'], 'newtext' => $newText,
			'summary' => $result['summary'], 'user' => (int)$this->owner,
			'sources' => $sources, 'state' => 'ready',
		] );
		return $result;
	}

	public function confirm( string $proposalId ): array {
		$this->checkAccess();
		return $this->store->withLock( 'proposal', $proposalId, $this->owner, function ( array $proposal ) use (
			$proposalId
		) {
			$title = $this->target( $proposal['title'] );
			$base = $this->snapshot( $title );
			$this->checkPublicBase( $proposal );
			$this->checkCitations( $proposal['sources'] );
			if ( $proposal['state'] === 'saved' ) {
				return $proposal['result'];
			}
			if ( $proposal['state'] === 'saving' ) {
				// Never write again after an uncertain save, even if the base still matches.
				$result = $this->recover( $title, $proposal );
			} elseif ( $proposal['state'] === 'ready' ) {
				$this->assertBase( $base, $proposal );
				$this->checkSize( $proposal['newtext'] );
				$proposal['state'] = 'saving';
				$this->store->update( 'proposal', $proposalId, $this->owner, $proposal );
				$result = $this->save( $title, $proposal );
			} else {
				throw new RuntimeException( 'wanda-edit-invalid-record' );
			}
			$proposal['state'] = 'saved';
			$proposal['result'] = $result;
			$this->store->update( 'proposal', $proposalId, $this->owner, $proposal );
			return $result;
		} );
	}

	/** All parameters are server-built. Core still checks CSRF, hooks and rate limits. */
	protected function save( Title $title, array $proposal ): array {
		$params = [
			'action' => 'edit', 'title' => $proposal['title'], 'text' => $proposal['newtext'],
			'summary' => $proposal['summary'], 'baserevid' => $proposal['baseRevId'],
			'starttimestamp' => $proposal['starttimestamp'], 'contentmodel' => CONTENT_MODEL_WIKITEXT,
			'token' => $this->context->getRequest()->getVal( 'token' ),
			'watchlist' => 'nochange', 'notminor' => true,
		];
		$params[$proposal['pageid'] ? 'nocreate' : 'createonly'] = true;
		$context = new DerivativeContext( $this->context );
		$context->setAuthority( $this->context->getAuthority() );
		$context->setRequest( new DerivativeRequest( $this->context->getRequest(), $params, true ) );
		// REL1_42 has no no-automerge flag. Guard the merged content and base before
		// saving. Do not unregister the whole hook and remove other extensions.
		$active = true;
		MediaWikiServices::getInstance()->getHookContainer()->register( 'EditFilterMergedContent',
			function ( $hookContext, $content, $status ) use ( &$active, $title, $proposal ) {
				if ( !$active ) {
					return true;
				}
				try {
					$this->assertBase( $this->snapshot( $title ), $proposal );
					if ( !( $content instanceof WikitextContent ) || $content->getText() !== $proposal['newtext'] ) {
						throw new RuntimeException( 'wanda-edit-stale' );
					}
				} catch ( RuntimeException $e ) {
					$status->fatal( 'wanda-api-error-edit-stale' );
					return false;
				}
				return true;
			}
		);
		try {
			// RecentChanges rows are inserted in a deferred update after this returns,
			// so provenance tagging happens in the RecentChange_save hook, which arms
			// here and attaches the tag with the correct rc_id/rev_id at insert time.
			ChangeTagHooks::armNextRecentChange();
			$api = new ApiMain( $context, true, true );
			$api->execute();
			$data = $api->getResult()->getResultData( null, [ 'Strip' => 'all' ] );
		} finally {
			$active = false;
		}
		if ( ( $data['edit']['result'] ?? '' ) !== 'Success' || empty( $data['edit']['newrevid'] ) ) {
			throw new RuntimeException( 'wanda-edit-save-uncertain' );
		}
		return $this->success( $title, $proposal, (int)$data['edit']['newrevid'] );
	}

	private function recover( Title $title, array $proposal ): array {
		$revision = $this->latest( $title );
		$content = $revision ? $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC ) : null;
		if ( !$revision || !( $content instanceof WikitextContent )
			|| $content->getText() !== $proposal['newtext']
			|| (int)$revision->getParentId() !== $proposal['baseRevId']
			|| $revision->getUser( RevisionRecord::FOR_PUBLIC ) === null
			|| (int)$revision->getUser( RevisionRecord::FOR_PUBLIC )->getId() !== $proposal['user']
			|| $revision->getTimestamp() < $proposal['starttimestamp']
			|| ( $proposal['pageid'] && (int)$revision->getPageId() !== $proposal['pageid'] )
			|| $revision->getComment( RevisionRecord::FOR_PUBLIC ) === null
			|| $revision->getComment( RevisionRecord::FOR_PUBLIC )->text !== $proposal['summary']
		) {
			throw new RuntimeException( 'wanda-edit-save-uncertain' );
		}
		return $this->success( $title, $proposal, $revision->getId() );
	}

	private function success( Title $title, array $proposal, int $revision ): array {
		return [
			'result' => 'success', 'title' => $proposal['title'], 'new' => !$proposal['pageid'],
			'newrevid' => $revision,
			'diffurl' => $title->getFullURL( $proposal['pageid'] ? [ 'diff' => $revision ] : [] ),
		];
	}

	private function assertBase( array $base, array $proposal ): void {
		if ( $base['baseRevId'] !== $proposal['baseRevId'] || $base['pageid'] !== $proposal['pageid'] ) {
			throw new RuntimeException( 'wanda-edit-stale' );
		}
	}

	/** Suppressing the preview's base revision also invalidates cached derived text. */
	private function checkPublicBase( array $proposal ): void {
		if ( !$proposal['baseRevId'] ) {
			return;
		}
		$revision = MediaWikiServices::getInstance()->getRevisionLookup()
			->getRevisionById( $proposal['baseRevId'], IDBAccessObject::READ_LATEST );
		if ( !$revision || !( $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC )
			instanceof WikitextContent )
		) {
			throw new RuntimeException( 'wanda-edit-permission' );
		}
	}

	private function target( string $name ): Title {
		$title = Title::newFromText( $name );
		if ( !$title || !$title->canExist() || $title->isExternal() || $title->hasFragment() ) {
			throw new RuntimeException( 'wanda-edit-notitle' );
		}
		$namespaces = WandaPageDiscovery::normalizeNamespaces(
			(array)$this->config( 'WandaBatchEditNamespaces', [ 0 ] ),
			MediaWikiServices::getInstance()->getNamespaceInfo()->getValidNamespaces()
		);
		if ( !in_array( $title->getNamespace(), $namespaces, true ) ) {
			throw new RuntimeException( 'wanda-edit-namespace' );
		}
		$authority = $this->context->getAuthority();
		if ( !$authority->authorizeRead( 'read', $title ) || !$authority->definitelyCan( 'edit', $title ) ) {
			throw new RuntimeException( 'wanda-edit-permission' );
		}
		return $title;
	}

	private function latest( Title $title ): ?RevisionRecord {
		return MediaWikiServices::getInstance()->getRevisionLookup()
			->getRevisionByTitle( $title, 0, IDBAccessObject::READ_LATEST );
	}

	private function snapshot( Title $title ): array {
		$authority = $this->context->getAuthority();
		if ( !$authority->authorizeRead( 'read', $title ) || !$authority->definitelyCan( 'edit', $title ) ) {
			throw new RuntimeException( 'wanda-edit-permission' );
		}
		$started = wfTimestampNow();
		$revision = $this->latest( $title );
		if ( !$revision ) {
			if ( !$authority->definitelyCan( 'create', $title ) ) {
				throw new RuntimeException( 'wanda-edit-permission' );
			}
			if ( $title->getContentModel() !== CONTENT_MODEL_WIKITEXT ) {
				throw new RuntimeException( 'wanda-edit-notitle' );
			}
			return [ 'text' => '', 'baseRevId' => 0, 'pageid' => 0, 'starttimestamp' => $started ];
		}
		if ( $revision->getSize() > $this->maxBytes() ) {
			throw new RuntimeException( 'wanda-edit-too-large' );
		}
		$content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
		if ( !( $content instanceof WikitextContent ) || $content->isRedirect() ) {
			throw new RuntimeException( 'wanda-edit-notitle' );
		}
		$this->checkSize( $content->getText() );
		return [
			'text' => $content->getText(), 'baseRevId' => (int)$revision->getId(),
			'pageid' => (int)$revision->getPageId(), 'starttimestamp' => $started,
		];
	}

	/** Recheck research read permissions before returning cached derived content. */
	private function checkCitations( array $sources ): void {
		foreach ( $sources as $source ) {
			$title = Title::newFromText( $source['title'] );
			if ( !$title || $title->isExternal()
				|| !$this->context->getAuthority()->authorizeRead( 'read', $title )
			) {
				throw new RuntimeException( 'wanda-edit-permission' );
			}
		}
	}

	private function maxBytes(): int {
		$limit = (int)$this->config( 'WandaEditMaxPageBytes', 100000 );
		return $limit > 0 ? min( 1048576, $limit ) : 100000;
	}

	private function checkSize( string $text ): void {
		if ( strlen( $text ) > $this->maxBytes() || !mb_check_encoding( $text, 'UTF-8' ) ) {
			throw new RuntimeException( 'wanda-edit-too-large' );
		}
	}

	private function instruction( string $message ): void {
		if ( trim( $message ) === '' ) {
			throw new RuntimeException( 'wanda-edit-empty' );
		}
		if ( strlen( $message ) > 10000 || !mb_check_encoding( $message, 'UTF-8' ) ) {
			throw new RuntimeException( 'wanda-edit-too-large' );
		}
	}

	private function summary( string $summary, string $message ): string {
		if ( trim( $summary ) !== '' ) {
			return mb_substr( trim( $summary ), 0, 250 );
		}
		return $this->context->msg( 'wanda-edit-summary', mb_substr( trim( $message ), 0, 200 ) )
			->inContentLanguage()->text();
	}

	protected function rateLimit(): void {
		if ( $this->context->getUser()->pingLimiter( 'edit' ) ) {
			throw new RuntimeException( 'wanda-edit-rate-limited' );
		}
	}

	protected function createPlanner(): WandaEditPlanner {
		return new WandaEditPlanner( $this->handler );
	}

	protected function createContext(): WandaEditContext {
		return new WandaEditContext( $this->context );
	}

	protected function createDiscovery(
		int $limit, int $maxLimit = WandaPageDiscovery::MAX_LIMIT
	): WandaPageDiscovery {
		return new WandaPageDiscovery( $this->context, $limit,
			(array)$this->config( 'WandaBatchEditNamespaces', [ 0 ] ), $maxLimit );
	}

	private function config( string $key, $default ) {
		$config = $this->context->getConfig();
		return $config->has( $key ) ? ( $config->get( $key ) ?? $default ) : $default;
	}
}
