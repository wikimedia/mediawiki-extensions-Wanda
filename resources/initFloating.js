const Vue = require( 'vue' );
const FloatingChat = require( './components/FloatingChat.vue' );

// Create a container near body end to host floating chat and mount immediately
const container = document.body.appendChild( document.createElement( 'div' ) );
Vue.createMwApp( FloatingChat ).mount( container );
