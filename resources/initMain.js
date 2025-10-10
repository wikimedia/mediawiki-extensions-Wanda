const Vue = require( 'vue' );
const ChatApp = require( './components/ChatApp.vue' );

// Ensure mount point exists and mount immediately when module loads
let mount = document.getElementById( 'chat-bot-container' );
if ( !mount ) {
  mount = document.body.appendChild( document.createElement( 'div' ) );
  mount.id = 'chat-bot-container';
}
Vue.createMwApp( ChatApp ).mount( mount );
