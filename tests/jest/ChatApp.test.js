/* eslint-env jest */
const { mount } = require( '@vue/test-utils' );
const ChatApp = require( '../../resources/components/ChatApp.vue' );

function factory() {
	return mount( ChatApp );
}

beforeEach( () => {
	global.resetMwMocks();
} );

describe( 'ChatApp - text helpers', () => {
	test( 'escapeHtml escapes all HTML-significant characters', () => {
		const vm = factory().vm;
		expect( vm.escapeHtml( '<a href="x">&\'' ) )
			.toBe( '&lt;a href=&quot;x&quot;&gt;&amp;&#39;' );
	} );

	test( 'applyInlineMarkdown converts bold and italic', () => {
		const vm = factory().vm;
		expect( vm.applyInlineMarkdown( 'a **b** c' ) ).toBe( 'a <b>b</b> c' );
		expect( vm.applyInlineMarkdown( 'a *i* b' ) ).toBe( 'a <i>i</i> b' );
	} );

	test( 'stripFilePrefix removes a leading File: prefix only', () => {
		const vm = factory().vm;
		expect( vm.stripFilePrefix( 'File:Cat.png' ) ).toBe( 'Cat.png' );
		expect( vm.stripFilePrefix( 'Profile:Cat.png' ) ).toBe( 'Profile:Cat.png' );
	} );

	test( 'sourceLabel maps known sources, strips RAG prefix, passes through unknown', () => {
		const vm = factory().vm;
		expect( vm.sourceLabel( 'wiki' ) ).toBe( 'Wiki' );
		expect( vm.sourceLabel( 'publicknowledge' ) ).toBe( 'Public knowledge' );
		expect( vm.sourceLabel( 'RAG:Handbook' ) ).toBe( 'Handbook' );
		expect( vm.sourceLabel( 'mystery' ) ).toBe( 'mystery' );
	} );

	test( 'formatFileSize formats across unit boundaries', () => {
		const vm = factory().vm;
		expect( vm.formatFileSize( 0 ) ).toBe( '0 B' );
		expect( vm.formatFileSize( 500 ) ).toBe( '500 B' );
		expect( vm.formatFileSize( 1024 ) ).toBe( '1 KB' );
		expect( vm.formatFileSize( 5242880 ) ).toBe( '5 MB' );
	} );
} );

describe( 'ChatApp - markdown table parsing', () => {
	test( 'isMarkdownTableSeparator recognises separator rows', () => {
		const vm = factory().vm;
		expect( vm.isMarkdownTableSeparator( '|---|---|' ) ).toBe( true );
		expect( vm.isMarkdownTableSeparator( '| :--- | ---: |' ) ).toBe( true );
		expect( vm.isMarkdownTableSeparator( 'not a sep' ) ).toBe( false );
	} );

	test( 'splitMarkdownTableRow trims cells and handles quoted/edge pipes', () => {
		const vm = factory().vm;
		expect( vm.splitMarkdownTableRow( '| a | b |' ) ).toEqual( [ 'a', 'b' ] );
		expect( vm.splitMarkdownTableRow( '"| a | b |"' ) ).toEqual( [ 'a', 'b' ] );
	} );

	test( 'formatBotResponse renders a full markdown table', () => {
		const vm = factory().vm;
		const html = vm.formatBotResponse( '| A | B |\n|---|---|\n| 1 | 2 |' );
		expect( html ).toContain( '<table class="wanda-table">' );
		expect( html ).toContain( '<th>A</th>' );
		expect( html ).toContain( '<td>1</td>' );
		expect( html ).toContain( '<td>2</td>' );
	} );
} );

describe( 'ChatApp - formatBotResponse', () => {
	test( 'returns empty string for blank input', () => {
		const vm = factory().vm;
		expect( vm.formatBotResponse( '' ) ).toBe( '' );
		expect( vm.formatBotResponse( '   ' ) ).toBe( '' );
	} );

	test( 'renders a heading as bold', () => {
		const vm = factory().vm;
		expect( vm.formatBotResponse( '### Hi' ) ).toBe( '<b>Hi</b><br>' );
	} );

	test( 'renders bullet lists', () => {
		const vm = factory().vm;
		expect( vm.formatBotResponse( '- a\n- b' ) )
			.toBe( '<ul><li>a</li><li>b</li></ul>' );
	} );

	test( 'separates paragraphs with double break', () => {
		const vm = factory().vm;
		expect( vm.formatBotResponse( 'Hello\n\nWorld' ) ).toBe( 'Hello<br><br>World' );
	} );

	test( 'escapes HTML in plain content', () => {
		const vm = factory().vm;
		expect( vm.formatBotResponse( '<script>' ) ).toBe( '&lt;script&gt;' );
	} );
} );

describe( 'ChatApp - formatStepDesc', () => {
	test( 'describes a successful cargo query with pluralised rows', () => {
		const vm = factory().vm;
		expect( vm.formatStepDesc( { source: 'cargo', tables: 'Employees', rows: 2 } ) )
			.toBe( 'Queried Employees (2 rows)' );
		expect( vm.formatStepDesc( { source: 'cargo', tables: 'Employees', rows: 1 } ) )
			.toBe( 'Queried Employees (1 row)' );
	} );

	test( 'prefixes the step number when present and appends reasoning', () => {
		const vm = factory().vm;
		expect( vm.formatStepDesc( {
			source: 'cargo', step: 2, tables: 'T', rows: 3, reasoning: 'why'
		} ) ).toBe( 'Step 2: Queried T (3 rows) — why' );
	} );

	test( 'describes a wikidata query', () => {
		const vm = factory().vm;
		expect( vm.formatStepDesc( { source: 'wikidata', rows: 1 } ) )
			.toBe( 'Wikidata (1 row)' );
	} );

	test( 'describes an error step', () => {
		const vm = factory().vm;
		expect( vm.formatStepDesc( {
			source: 'cargo', type: 'error', tables: 'T', message: 'boom'
		} ) ).toBe( 'Failed querying T — boom' );
	} );
} );

describe( 'ChatApp - source selection', () => {
	test( 'defaults to the first available source', () => {
		const vm = factory().vm;
		expect( vm.selectedValues ).toEqual( [ 'wiki' ] );
	} );

	test( 'toggleSource adds and removes without duplicates', () => {
		const vm = factory().vm;
		vm.toggleSource( 'wikidata', true );
		expect( vm.selectedValues ).toEqual( [ 'wiki', 'wikidata' ] );
		// Toggling on again is a no-op.
		vm.toggleSource( 'wikidata', true );
		expect( vm.selectedValues ).toEqual( [ 'wiki', 'wikidata' ] );
		vm.toggleSource( 'wiki', false );
		expect( vm.selectedValues ).toEqual( [ 'wikidata' ] );
	} );
} );

describe( 'ChatApp - image attachment', () => {
	test( 'canAddImage rejects oversized and duplicate images', () => {
		const vm = factory().vm;
		expect( vm.canAddImage( { title: 'a.png', size: 100 } ) ).toBe( true );
		expect( vm.canAddImage( { title: 'big.png', size: vm.maxImageSize + 1 } ) ).toBe( false );
		vm.attachedImages.push( { title: 'a.png' } );
		expect( vm.canAddImage( { title: 'a.png', size: 100 } ) ).toBe( false );
	} );

	test( 'selectImage attaches a valid image and closes the picker', () => {
		const vm = factory().vm;
		vm.imagePickerOpen = true;
		vm.selectImage( { title: 'a.png', url: 'http://x/a.png', size: 100 } );
		expect( vm.attachedImages ).toHaveLength( 1 );
		expect( vm.attachedImages[ 0 ].title ).toBe( 'a.png' );
		expect( vm.imagePickerOpen ).toBe( false );
	} );

	test( 'selectImage rejects oversized images and notifies', () => {
		const vm = factory().vm;
		vm.selectImage( { title: 'big.png', url: 'u', size: vm.maxImageSize + 1 } );
		expect( vm.attachedImages ).toHaveLength( 0 );
		expect( global.mw.notify ).toHaveBeenCalled();
	} );

	test( 'removeImage removes the image at the given index', () => {
		const vm = factory().vm;
		vm.attachedImages.push( { title: 'a.png' }, { title: 'b.png' } );
		vm.removeImage( 0 );
		expect( vm.attachedImages.map( ( i ) => i.title ) ).toEqual( [ 'b.png' ] );
	} );
} );

describe( 'ChatApp - sendMessage', () => {
	test( 'does nothing when the input is empty', async () => {
		const vm = factory().vm;
		vm.inputText = '   ';
		await vm.sendMessage();
		expect( vm.messages ).toHaveLength( 0 );
		expect( global.__mwApiMock.post ).not.toHaveBeenCalled();
	} );

	test( 'posts the message and renders the bot reply with a source link', async () => {
		global.__mwApiMock.post.mockResolvedValue( {
			response: 'Hello there',
			sources: [ { title: 'Help Page' } ]
		} );
		const vm = factory().vm;
		vm.inputText = 'hi';
		await vm.sendMessage();

		expect( global.__mwApiMock.post ).toHaveBeenCalledTimes( 1 );
		expect( vm.messages ).toHaveLength( 2 );
		expect( vm.messages[ 0 ].role ).toBe( 'user' );
		expect( vm.messages[ 1 ].role ).toBe( 'bot' );
		expect( vm.messages[ 1 ].content ).toContain( 'Hello there' );
		expect( vm.messages[ 1 ].content ).toContain(
			'<a target="_blank" href="/wiki/Help_Page">Help Page</a>'
		);
		expect( vm.messages[ 1 ].content ).toContain( '<b>Source</b>' );
		expect( vm.inputText ).toBe( '' );
		expect( vm.conversationHistory ).toHaveLength( 2 );
	} );

	test( 'renders a public-knowledge response with the Public source line', async () => {
		global.__mwApiMock.post.mockResolvedValue( {
			response: '<PUBLIC_KNOWLEDGE>Sky is blue'
		} );
		const vm = factory().vm;
		vm.inputText = 'why';
		await vm.sendMessage();
		expect( vm.messages[ 1 ].content ).toContain( 'Sky is blue' );
		expect( vm.messages[ 1 ].content ).toContain( '<b>Source</b>: Public' );
	} );

	test( 'maps NO_MATCHING_CONTEXT to the localised no-context message', async () => {
		global.__mwApiMock.post.mockResolvedValue( { response: 'NO_MATCHING_CONTEXT' } );
		const vm = factory().vm;
		vm.inputText = 'q';
		await vm.sendMessage();
		expect( vm.messages[ 1 ].content ).toContain( 'wanda-llm-response-nocontext' );
	} );

	test( 'shows an error message when the API call rejects', async () => {
		global.__mwApiMock.post.mockRejectedValue( new Error( 'network' ) );
		const vm = factory().vm;
		vm.inputText = 'hi';
		await vm.sendMessage();
		expect( vm.messages[ vm.messages.length - 1 ].content )
			.toBe( 'Error connecting to Wanda chatbot API.' );
		expect( vm.loading ).toBe( false );
	} );
} );

describe( 'ChatApp - conversation persistence', () => {
	test( 'restores messages and history from sessionStorage on mount', () => {
		sessionStorage.setItem(
			'wanda-conversation-messages',
			JSON.stringify( [ { role: 'user', content: 'prev' } ] )
		);
		sessionStorage.setItem(
			'wanda-conversation-history',
			JSON.stringify( [ { role: 'user', content: 'prev' } ] )
		);
		const vm = factory().vm;
		expect( vm.messages ).toHaveLength( 1 );
		expect( vm.conversationHistory ).toHaveLength( 1 );
	} );

	test( 'clearConversation empties state and persists the cleared state', () => {
		const vm = factory().vm;
		vm.messages.push( { role: 'user', content: 'x' } );
		vm.conversationHistory.push( { role: 'user', content: 'x' } );
		vm.clearConversation();
		expect( vm.messages ).toHaveLength( 0 );
		expect( vm.conversationHistory ).toHaveLength( 0 );
		expect( sessionStorage.getItem( 'wanda-conversation-messages' ) ).toBe( '[]' );
	} );

	test( 'disabling memory clears stored history', () => {
		const vm = factory().vm;
		vm.conversationHistory.push( { role: 'user', content: 'x' } );
		vm.conversationMemoryEnabled = false;
		vm.onMemoryToggleChange();
		expect( vm.conversationHistory ).toHaveLength( 0 );
		expect( sessionStorage.getItem( 'wanda-memory-enabled' ) ).toBe( 'false' );
	} );
} );

describe( 'ChatApp - configurable sources', () => {
	test( 'omits disabled sources from the default and includes RAG sources', () => {
		global.mw.config.set( 'WandaRAGSourceNames', [ 'Handbook' ] );
		global.mw.config.set( 'WandaDisabledSources', [ 'wiki' ] );
		jest.isolateModules( () => {
			// Re-require both within the isolated registry so the component and
			// test-utils share one Vue instance.
			const { mount: isoMount } = require( '@vue/test-utils' );
			const Comp = require( '../../resources/components/ChatApp.vue' );
			const vm = isoMount( Comp ).vm;
			// 'wiki' is disabled, so the first available default is 'wikidata'.
			expect( vm.selectedValues ).toEqual( [ 'wikidata' ] );
			// The RAG source is offered as a selectable option.
			expect( vm.sourceOptions.some( ( o ) => o.value === 'RAG:Handbook' ) ).toBe( true );
		} );
	} );
} );
