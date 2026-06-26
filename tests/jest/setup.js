/* eslint-env node, jest */
// Global `mw` mock shared by all Wanda frontend tests. The Vue SFCs read
// mw.config at module-load time and call mw.Api / mw.message / mw.util at
// runtime, so this must be installed before any component is required.

// A single Api instance is reused so tests can configure its get/post mocks via
// global.__mwApiMock without having to intercept the constructor per test.
const apiMock = { get: jest.fn(), post: jest.fn() };
global.__mwApiMock = apiMock;

const configStore = {};

global.mw = {
	config: {
		_store: configStore,
		get: ( key ) => ( Object.prototype.hasOwnProperty.call( configStore, key ) ? configStore[ key ] : null ),
		set: ( key, value ) => {
			configStore[ key ] = value;
		}
	},
	message: ( key ) => ( {
		// Return the key itself so assertions can match on the message key.
		text: () => key
	} ),
	util: {
		getUrl: ( title ) => '/wiki/' + String( title ).replace( / /g, '_' )
	},
	notify: jest.fn(),
	// `new mw.Api()` returns the shared mock instance (returning an object from a
	// constructor overrides the freshly created `this`).
	Api: function () {
		return apiMock;
	}
};

// Reset shared mock state between tests so cases stay independent.
global.resetMwMocks = () => {
	apiMock.get.mockReset();
	apiMock.post.mockReset();
	global.mw.notify.mockReset();
	Object.keys( configStore ).forEach( ( key ) => {
		delete configStore[ key ];
	} );
	if ( global.sessionStorage ) {
		global.sessionStorage.clear();
	}
};
