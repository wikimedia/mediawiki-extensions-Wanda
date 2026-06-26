/* eslint-env jest */
const { mount } = require( '@vue/test-utils' );
const FloatingChat = require( '../../resources/components/FloatingChat.vue' );

function factory() {
	return mount( FloatingChat, { attachTo: document.body } );
}

beforeEach( () => {
	global.resetMwMocks();
} );

describe( 'FloatingChat - window open/close', () => {
	test( 'starts closed', () => {
		const vm = factory().vm;
		expect( vm.open ).toBe( false );
	} );

	test( 'openWindow opens and closeWindow closes the window', () => {
		const vm = factory().vm;
		vm.openWindow();
		expect( vm.open ).toBe( true );
		vm.closeWindow();
		expect( vm.open ).toBe( false );
	} );

	test( 'a document click outside the widget closes an open window', () => {
		const wrapper = factory();
		const vm = wrapper.vm;
		vm.openWindow();
		expect( vm.open ).toBe( true );

		const outside = document.createElement( 'div' );
		document.body.appendChild( outside );
		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( vm.open ).toBe( false );

		outside.remove();
		wrapper.unmount();
	} );

	test( 'a document click does not close while the image picker is open', () => {
		const wrapper = factory();
		const vm = wrapper.vm;
		vm.openWindow();
		vm.imagePickerOpen = true;

		const outside = document.createElement( 'div' );
		document.body.appendChild( outside );
		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		expect( vm.open ).toBe( true );

		outside.remove();
		wrapper.unmount();
	} );
} );

describe( 'FloatingChat - shared logic', () => {
	test( 'formatBotResponse renders headings (shared with ChatApp)', () => {
		const vm = factory().vm;
		expect( vm.formatBotResponse( '### Hi' ) ).toBe( '<b>Hi</b><br>' );
	} );

	test( 'sourceLabel strips RAG prefixes', () => {
		const vm = factory().vm;
		expect( vm.sourceLabel( 'RAG:Docs' ) ).toBe( 'Docs' );
	} );

	test( 'persists state under floating-specific sessionStorage keys', async () => {
		global.__mwApiMock.post.mockResolvedValue( { response: 'Hi' } );
		const vm = factory().vm;
		vm.inputText = 'hello';
		await vm.sendMessage();
		expect( sessionStorage.getItem( 'wanda-floating-conversation-messages' ) ).not.toBeNull();
	} );
} );

describe( 'FloatingChat - sendMessage', () => {
	test( 'renders the bot reply and clears the input', async () => {
		global.__mwApiMock.post.mockResolvedValue( { response: 'Answer' } );
		const vm = factory().vm;
		vm.inputText = 'question';
		await vm.sendMessage();
		expect( vm.messages ).toHaveLength( 2 );
		expect( vm.messages[ 1 ].content ).toContain( 'Answer' );
		expect( vm.inputText ).toBe( '' );
	} );

	test( 'sends skipesquery and a custom prompt when an image is attached', async () => {
		global.__mwApiMock.post.mockResolvedValue( { response: 'Answer' } );
		const vm = factory().vm;
		vm.attachedImages.push( { title: 'File:Pic.png', url: 'u', size: 100 } );
		vm.inputText = 'describe';
		await vm.sendMessage();
		const postData = global.__mwApiMock.post.mock.calls[ 0 ][ 0 ];
		expect( postData.images ).toBe( 'File:Pic.png' );
		expect( postData.skipesquery ).toBe( true );
		expect( postData.customprompt ).toContain( 'image' );
	} );
} );
