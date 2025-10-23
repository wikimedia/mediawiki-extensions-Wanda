<template>
  <div>
    <div
      class="wanda-floating-button"
      :title="msg( 'wanda-floating-chat-title' )"
      v-show="!open && showPopup"
      @click="openWindow"
    >
      💬
    </div>

    <div class="wanda-floating-window" :class="{ show: open }" v-show="open && showPopup">
      <div class="wanda-chat-header">
        <span v-text="msg( 'wanda-floating-chat-header' )"></span>
        <button class="wanda-close-btn" @click="closeWindow">×</button>
      </div>
      <div class="chat-container wanda-floating-chat">
        <div class="chat-box" ref="chatBox" v-show="messages.length > 0">
          <div v-for="(msg, idx) in messages" :key="idx" :class="['chat-message-wrapper', msg.role + '-wrapper']">
            <div class="chat-message" :class="[ msg.role + '-message' ]" v-html="msg.content"></div>
          </div>
        </div>

        <div class="chat-instructions" v-show="messages.length === 0">
          <h2>{{ msg( 'wanda-chat-welcometext' ) }}</h2>
          <p>{{ msg( 'wanda-chat-welcomedesc' ) }}</p>
          <ul>
            <li>💬 {{ msg( 'wanda-chat-instruction1' ) }}</li>
            <li>🤖 {{ msg( 'wanda-chat-instruction2' ) }}</li>
          </ul>
        </div>

        <div class="chat-progress-bar" v-if="loading">
          <cdx-progress-bar :value="null" />
        </div>

        <div class="chat-input-container">
          <cdx-checkbox
            v-model="usepublicknowledge"
            id="wanda-toggle-public-knowledge"
            :aria-label="msg('wanda-toggle-public-knowledge-aria')"
          >
            {{ msg('wanda-toggle-public-knowledge-label') }}
          </cdx-checkbox>
          <div class="wanda-floating-input-row">
            <cdx-text-area
              id="wanda-floating-chat-input"
              v-model="inputText"
              class="chat-input-box"
              :rows="2"
              placeholder="Type your message..."
              @keydown.enter.exact.prevent="sendMessage"
            ></cdx-text-area>
            <cdx-button action="progressive" weight="primary" @click="sendMessage">Send</cdx-button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
const { CdxButton, CdxTextArea, CdxProgressBar, CdxCheckbox } = require( '../../codex.js' );

module.exports = exports = {
  name: 'FloatingChat',
  components: { CdxButton, CdxTextArea, CdxProgressBar, CdxCheckbox },
  data() {
    return {
      open: false,
      inputText: '',
      messages: [],
      loading: false,
      showPopup: !!mw.config.get( 'WandaShowPopup' ),
      usepublicknowledge: false
    };
  },
  computed: {
    sendLabel() {
      return 'Send';
    }
  },
  mounted() {
    document.addEventListener( 'click', this.onDocumentClick );
  },
  beforeUnmount() {
    document.removeEventListener( 'click', this.onDocumentClick );
  },
  methods: {
    msg( key ) {
      return mw.message( key ).text();
    },
    openWindow() {
      this.open = true;
      this.$nextTick( () => {
        const textarea = this.$el.querySelector( 'textarea' );
        if ( textarea ) {
          textarea.focus();
        }
      } );
    },
    closeWindow() {
      this.open = false;
    },
    onDocumentClick( e ) {
      const within = e.target.closest( '.wanda-floating-window, .wanda-floating-button' );
      if ( !within && this.open ) {
        this.closeWindow();
      }
    },
    scrollToBottom() {
      this.$nextTick( () => {
        const el = this.$refs.chatBox;
        if ( el ) {
          el.scrollTop = el.scrollHeight;
        }
      } );
    },
    addMessage( role, content ) {
      this.messages.push( { role, content } );
      this.scrollToBottom();
    },
    escapeHtml( str ) {
      return String( str )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' )
        .replace( /'/g, '&#39;' );
    },
    async sendMessage() {
      const userText = this.inputText.trim();
      if ( !userText || this.loading ) {
        return;
      }
      this.addMessage( 'user', this.escapeHtml( userText ) );
      this.inputText = '';
      this.loading = true;

      try {
        const api = new mw.Api();
        const data = await api.post( {
          action: 'wandachat',
          format: 'json',
          message: userText,
          usepublicknowledge: this.usepublicknowledge ? true : false
        } );

        let response = data && data.response ? data.response : 'Error fetching response';
        if ( response === 'NO_MATCHING_CONTEXT' ) {
          response = this.msg( 'wanda-llm-response-nocontext' );
        } else if ( response.includes( '<PUBLIC_KNOWLEDGE>' ) ) {
          response = response.replace( '<PUBLIC_KNOWLEDGE>', '' );
          response += '<br><b>Source</b>: Public';
        } else if ( data && data.source ) {
          const href = mw.util.getUrl( data.source );
          const safeTitle = this.escapeHtml( data.source );
          response += '<br><b>Source</b>: <a target="_blank" href="' + href + '">' + safeTitle + '</a>';
        }
        this.addMessage( 'bot', response );
      } catch ( e ) {
        this.addMessage( 'bot', 'Error connecting to Wanda chatbot API.' );
      } finally {
        this.loading = false;
      }
    }
  }
};
</script>
