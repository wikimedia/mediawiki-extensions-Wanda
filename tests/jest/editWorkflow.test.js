/* eslint-env jest */
const { mount, flushPromises } = require( '@vue/test-utils' );
global.resetMwMocks();
const ChatApp = require( '../../resources/components/ChatApp.vue' );
const FloatingChat = require( '../../resources/components/FloatingChat.vue' );
const EditReview = require( '../../resources/components/EditReview.vue' );

const plan = ( sources = [] ) => ( {
	batchid: 'batch', scope: 'Pages matching the instruction', truncated: true, maxpages: 2,
	candidates: [ { title: 'A' }, { title: 'B' } ], sources
} );
const preview = ( title ) => ( {
	changed: true, title, proposalid: 'proposal-' + title, newtext: 'server-owned',
	diff: '<table><tbody><tr><td>server diff</td></tr></tbody></table>',
	summary: '<b>summary</b>', explanation: '<img src=x onerror=alert(1)>',
	sources: [ { title: 'Evidence', href: 'https://example.org/evidence' } ]
} );
const success = ( title ) => ( {
	result: 'success', title, new: false, newrevid: 42, diffurl: '/w/index.php?diff=42'
} );
function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( res, rej ) => {
		resolve = res;
		reject = rej;
	} );
	return { promise, resolve, reject };
}

describe.each( [ [ 'ChatApp', ChatApp ], [ 'FloatingChat', FloatingChat ] ] )( '%s edit workflow', ( name, App ) => {
	let wrapper, vm, post;
	beforeEach( () => {
		global.resetMwMocks();
		mw.config.set( 'WandaCanEdit', true );
		wrapper = mount( App );
		vm = wrapper.vm;
		vm.editModeEnabled = true;
		post = global.__mwApiMock.postWithToken;
	} );
	afterEach( () => wrapper.unmount() );

	async function planned( sources = [] ) {
		post.mockResolvedValueOnce( plan( sources ) );
		await vm.requestEdit( 'Update pages' );
	}
	async function previews() {
		await planned();
		post.mockResolvedValueOnce( preview( 'A' ) ).mockResolvedValueOnce( preview( 'B' ) );
		await vm.generateEditPreviews();
	}

	test.each( [ '', '  Target page  ' ] )( 'plans title %p and shows candidates before any previews', async ( title ) => {
		vm.editTarget = title;
		vm.selectedValues = [ 'smw', 'RAG:Docs' ];
		vm.inputText = 'Update pages';
		post.mockResolvedValueOnce( plan( [ 'smw' ] ) );
		await vm.sendMessage();
		expect( post ).toHaveBeenCalledTimes( 1 );
		expect( post ).toHaveBeenCalledWith( 'csrf', {
			action: 'wandaedit', format: 'json', formatversion: 2, stage: 'plan',
			message: 'Update pages', title: title.trim(), sources: 'smw|RAG:Docs'
		} );
		expect( wrapper.text() ).toContain( 'wanda-edit-scope' );
		expect( wrapper.text() ).toContain( 'wanda-edit-truncated' );
		expect( wrapper.findAll( '.wanda-edit-candidate' ) ).toHaveLength( 2 );
		expect( wrapper.find( '.wanda-edit-apply-selected' ).element.disabled ).toBe( true );
		expect( global.__mwApiMock.post ).not.toHaveBeenCalled();
	} );

	test( 'defaults empty user sources to wiki but executes only approved sources, sequentially', async () => {
		vm.selectedValues = [];
		await planned( [ 'smw', 'RAG:Docs' ] );
		expect( post.mock.calls[ 0 ][ 1 ].sources ).toBe( 'wiki' );
		const first = deferred();
		const second = deferred();
		const third = deferred();
		post.mockReturnValueOnce( first.promise ).mockReturnValueOnce( second.promise )
			.mockReturnValueOnce( third.promise ).mockResolvedValueOnce( preview( 'B' ) );
		const pending = vm.generateEditPreviews();
		expect( post.mock.calls[ 1 ][ 1 ] ).toMatchObject( { stage: 'context', source: 'smw', batchid: 'batch' } );
		expect( post ).toHaveBeenCalledTimes( 2 );
		await vm.applyEdit();
		await vm.generateEditPreviews();
		expect( post ).toHaveBeenCalledTimes( 2 );
		first.resolve( { sources: [ { title: 'Research', href: '/wiki/Research' } ] } );
		await flushPromises();
		expect( post.mock.calls[ 2 ][ 1 ] ).toMatchObject( { stage: 'context', source: 'RAG:Docs' } );
		expect( post ).toHaveBeenCalledTimes( 3 );
		second.resolve( { sources: [] } );
		await flushPromises();
		expect( post.mock.calls[ 3 ][ 1 ] ).toMatchObject( { stage: 'preview', title: 'A' } );
		expect( post ).toHaveBeenCalledTimes( 4 );
		third.resolve( preview( 'A' ) );
		await pending;
		expect( vm.editPages.map( ( page ) => page.status ) ).toEqual( [ 'ready', 'ready' ] );
		expect( wrapper.text() ).toContain( 'Research' );
		expect( wrapper.text() ).toContain( 'Evidence' );
		post.mock.calls.forEach( ( [ token, params ] ) => {
			expect( token ).toBe( 'csrf' );
			expect( params ).not.toHaveProperty( 'confirm' );
		} );
	} );

	test( 'candidate and proposal deselection prevent preview and save requests', async () => {
		await planned();
		await wrapper.findAll( '.wanda-edit-candidate' )[ 1 ].setValue( false );
		post.mockResolvedValueOnce( preview( 'A' ) );
		await wrapper.find( '.wanda-edit-generate' ).trigger( 'click' );
		await flushPromises();
		expect( post ).toHaveBeenCalledTimes( 2 );
		expect( post.mock.calls[ 1 ][ 1 ] ).toMatchObject( { stage: 'preview', title: 'A' } );
		await wrapper.find( '.wanda-edit-approval' ).setValue( false );
		await vm.applyEdit();
		expect( post ).toHaveBeenCalledTimes( 2 );
		expect( vm.canApplyEdits ).toBe( false );
		await wrapper.find( '.wanda-edit-approval' ).setValue( true );
		await wrapper.findAll( '.wanda-edit-candidate' )[ 0 ].setValue( false );
		expect( vm.canApplyEdits ).toBe( false );
		await wrapper.findAll( '.wanda-edit-candidate' )[ 0 ].setValue( true );
		post.mockResolvedValueOnce( success( 'A' ) );
		await wrapper.find( '.wanda-edit-apply-selected' ).trigger( 'click' );
		await flushPromises();
		expect( post.mock.calls[ 2 ][ 1 ] ).toEqual( {
			action: 'wandaedit', format: 'json', formatversion: 2, confirm: 1, proposalid: 'proposal-A'
		} );
		expect( vm.editPages[ 0 ].status ).toBe( 'applied' );
	} );

	test( 'nochange cannot be approved or applied and page failures remain retryable', async () => {
		await planned();
		post.mockResolvedValueOnce( { changed: false, explanation: 'Already correct' } )
			.mockRejectedValueOnce( new Error( 'Preview failed' ) );
		await vm.generateEditPreviews();
		expect( vm.editPages.map( ( page ) => page.status ) ).toEqual( [ 'nochange', 'preview-failed' ] );
		expect( wrapper.text() ).toContain( 'Already correct' );
		expect( wrapper.text() ).toContain( 'Preview failed' );
		expect( vm.canApplyEdits ).toBe( false );
		post.mockResolvedValueOnce( preview( 'B' ) );
		await vm.generateEditPreviews();
		expect( post ).toHaveBeenCalledTimes( 4 );
		expect( post.mock.calls[ 3 ][ 1 ].title ).toBe( 'B' );
	} );

	test( 'continues after preview failure and rejects changed responses without proposal IDs', async () => {
		await planned();
		post.mockResolvedValueOnce( { changed: true, newtext: 'not a proposal' } )
			.mockResolvedValueOnce( preview( 'B' ) );
		await vm.generateEditPreviews();
		expect( vm.editPages.map( ( page ) => page.status ) ).toEqual( [ 'preview-failed', 'ready' ] );
	} );

	test( 'preserves partial save success and retries only failed proposals after a conflict', async () => {
		await previews();
		const first = deferred();
		post.mockReturnValueOnce( first.promise ).mockRejectedValueOnce( new Error( 'editconflict' ) );
		const applying = vm.applyEdit();
		expect( post ).toHaveBeenCalledTimes( 4 );
		await vm.applyEdit();
		await vm.requestEdit( 'overlap' );
		expect( post ).toHaveBeenCalledTimes( 4 );
		first.resolve( success( 'A' ) );
		await applying;
		expect( vm.editPages.map( ( page ) => page.status ) ).toEqual( [ 'applied', 'save-failed' ] );
		expect( wrapper.text() ).toContain( 'editconflict' );
		expect( wrapper.find( 'a[href="/w/index.php?diff=42"]' ).exists() ).toBe( true );
		expect( wrapper.findAll( '.wanda-edit-approval' )[ 0 ].element.disabled ).toBe( true );
		post.mockResolvedValueOnce( success( 'B' ) );
		await vm.applyEdit();
		expect( post.mock.calls[ 5 ][ 1 ].proposalid ).toBe( 'proposal-B' );
		await vm.applyEdit();
		expect( post ).toHaveBeenCalledTimes( 6 );
		expect( vm.editPages[ 0 ].result.newrevid ).toBe( 42 );
	} );

	test( 'research failures stop previews and retry without repeating completed sources', async () => {
		await planned( [ 'wiki', 'smw' ] );
		post.mockResolvedValueOnce( { sources: [] } ).mockRejectedValueOnce( new Error( 'research failed' ) );
		await vm.generateEditPreviews();
		expect( vm.editResearch.map( ( item ) => item.status ) ).toEqual( [ 'done', 'failed' ] );
		expect( vm.editPages[ 0 ].status ).toBe( 'pending' );
		post.mockResolvedValueOnce( { sources: [] } ).mockResolvedValueOnce( preview( 'A' ) )
			.mockResolvedValueOnce( preview( 'B' ) );
		await vm.generateEditPreviews();
		expect( post.mock.calls[ 3 ][ 1 ].source ).toBe( 'smw' );
		expect( vm.canApplyEdits ).toBe( true );
	} );

	test( 'cancels planning, ignores stale response, holds lock until settlement, clears old results', async () => {
		await previews();
		const next = deferred();
		post.mockReturnValueOnce( next.promise );
		const pending = vm.requestEdit( 'new request' );
		expect( vm.editPages ).toEqual( [] );
		expect( vm.editPlan ).toBeNull();
		vm.dismissEditReview();
		expect( vm.editDialogOpen ).toBe( true );
		expect( vm.editStopped ).toBe( true );
		await vm.requestEdit( 'overlap' );
		expect( post ).toHaveBeenCalledTimes( 4 );
		next.resolve( plan() );
		await pending;
		expect( vm.editPlan ).toBeNull();
		expect( vm.editBusy ).toBe( false );
		vm.dismissEditReview();
		expect( vm.editDialogOpen ).toBe( false );
		await planned();
		expect( vm.editPages ).toHaveLength( 2 );
		expect( vm.editStopped ).toBe( false );
	} );

	test.each( [ 'context', 'preview' ] )( 'cancel during %s stops between requests and ignores stale responses', async ( stage ) => {
		await planned( stage === 'context' ? [ 'wiki', 'smw' ] : [] );
		const next = deferred();
		post.mockReturnValueOnce( next.promise );
		const pending = vm.generateEditPreviews();
		vm.cancelEdit();
		next.resolve( stage === 'context' ? { sources: [ { title: 'stale' } ] } : preview( 'A' ) );
		await pending;
		expect( post ).toHaveBeenCalledTimes( 2 );
		expect( vm.editPages[ 0 ].status ).toBe( 'pending' );
		expect( vm.editPages[ 0 ].proposalid ).toBeNull();
		expect( vm.editResearch.some( ( item ) => item.sources.length ) ).toBe( false );
	} );

	test( 'dialog dismissal during save leaves an unknown outcome, not an undone save', async () => {
		await previews();
		const save = deferred();
		post.mockResolvedValueOnce( success( 'A' ) ).mockReturnValueOnce( save.promise );
		const pending = vm.applyEdit();
		await flushPromises();
		wrapper.findComponent( EditReview ).findComponent( { name: 'CdxDialog' } ).vm.$emit( 'update:open', false );
		expect( vm.editDialogOpen ).toBe( true );
		expect( vm.editPages.map( ( page ) => page.status ) ).toEqual( [ 'applied', 'unknown' ] );
		expect( vm.canApplyEdits ).toBe( false );
		save.resolve( success( 'B' ) );
		await pending;
		expect( vm.editPages[ 1 ].status ).toBe( 'unknown' );
		expect( wrapper.text() ).toContain( 'wanda-edit-status-unknown' );
		post.mockResolvedValueOnce( success( 'B' ) );
		await vm.applyEdit();
		expect( post.mock.calls[ 5 ][ 1 ].proposalid ).toBe( 'proposal-B' );
		expect( vm.editPages[ 0 ].status ).toBe( 'applied' );
	} );

	test( 'displays plan errors and empty candidates without enabling apply', async () => {
		post.mockRejectedValueOnce( new Error( 'Plan failed' ) );
		await vm.requestEdit( 'request' );
		expect( wrapper.text() ).toContain( 'Plan failed' );
		post.mockResolvedValueOnce( Object.assign( {}, plan(), { candidates: [] } ) );
		await vm.requestEdit( 'request' );
		expect( vm.editError ).toBe( '' );
		expect( wrapper.text() ).toContain( 'wanda-edit-no-candidates' );
		expect( vm.canGenerateEdits ).toBe( false );
		expect( vm.canApplyEdits ).toBe( false );
	} );

	test( 'ignores late errors after cancellation and responses after unmount', async () => {
		const failed = deferred();
		post.mockReturnValueOnce( failed.promise );
		const pending = vm.requestEdit( 'request' );
		vm.cancelEdit();
		failed.reject( new Error( 'stale error' ) );
		await pending;
		expect( vm.editError ).toBe( '' );
		const late = deferred();
		post.mockReturnValueOnce( late.promise );
		const unmounted = vm.requestEdit( 'request' );
		wrapper.unmount();
		late.resolve( plan() );
		await unmounted;
		expect( vm.editPlan ).toBeNull();
	} );
} );
