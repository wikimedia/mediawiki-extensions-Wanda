/* eslint-env jest */
const { mount } = require( '@vue/test-utils' );
const EditReview = require( '../../resources/components/EditReview.vue' );

beforeEach( () => global.resetMwMocks() );

test( 'renders model prose as text, only server diff as HTML, and filters unsafe links', () => {
	const wrapper = mount( EditReview, { props: {
		open: true,
		plan: { scope: '<b>scope</b>', truncated: true, maxpages: 10 },
		pages: [ {
			title: '<b>title</b>', selected: true, approved: true, status: 'ready', proposalid: 'p',
			summary: '<b>summary</b>', explanation: '<img src=x onerror=alert(1)>',
			diff: '<table><tbody><tr><td>Server diff</td></tr></tbody></table>',
			// eslint-disable-next-line no-script-url
			sources: [ { title: '<b>source</b>', href: 'javascript:alert(1)' } ]
		} ]
	} } );
	expect( wrapper.find( 'b' ).exists() ).toBe( false );
	expect( wrapper.find( 'img' ).exists() ).toBe( false );
	expect( wrapper.text() ).toContain( '<b>summary</b>' );
	expect( wrapper.find( 'details table' ).text() ).toBe( 'Server diff' );
	expect( wrapper.find( 'details' ).element.open ).toBe( false );
	// eslint-disable-next-line no-script-url
	expect( wrapper.find( 'a' ).attributes( 'href' ) ).not.toContain( 'javascript:' );
	[ 'data:text/html,x', 'java\nscript:alert(1)', '//evil.test', '/\\evil.test' ].forEach( ( url ) => {
		expect( wrapper.vm.safeUrl( url, 'Fallback' ) ).toBe( '/wiki/Fallback' );
	} );
	expect( wrapper.vm.safeUrl( 'https://example.org/source', 'Fallback' ) ).toBe( 'https://example.org/source' );
	wrapper.unmount();
} );

test( 'passes scope, truncation cap, and revision parameters to messages', () => {
	const original = mw.message;
	mw.message = jest.fn( original );
	const wrapper = mount( EditReview, { props: {
		open: true, plan: { scope: 'single', truncated: true, maxpages: 10 },
		pages: [ {
			title: 'A', status: 'applied', sources: [],
			result: { title: 'A', newrevid: 42, new: true }
		} ]
	} } );
	expect( mw.message ).toHaveBeenCalledWith( 'wanda-edit-scope', 'single' );
	expect( mw.message ).toHaveBeenCalledWith( 'wanda-edit-truncated', 10 );
	expect( mw.message ).toHaveBeenCalledWith( 'wanda-edit-view-result', 42, 'A' );
	expect( wrapper.text() ).toContain( 'wanda-edit-created' );
	wrapper.unmount();
	mw.message = original;
} );
