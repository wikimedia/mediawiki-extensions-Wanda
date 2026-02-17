<template>
  <div class="chat-container">
    <div class="chat-box" ref="chatBox" v-show="messages.length > 0">
      <div
        v-for="(msg, idx) in messages"
        :key="idx"
        :class="['chat-message-wrapper', msg.role + '-wrapper']"
      >
        <div class="chat-message-container">
          <div class="chat-message" :class="[ msg.role + '-message' ]" v-html="msg.content"></div>
          <div v-if="msg.role === 'user' && msg.images && msg.images.length > 0" class="wanda-message-images">
            <img
              v-for="(image, imgIdx) in msg.images"
              :key="imgIdx"
              :src="image.url"
              :alt="image.title"
              :title="image.title"
              class="wanda-message-image"
            />
          </div>
        </div>
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

    <div class="wanda-clear-row" v-if="conversationMemoryEnabled && messages.length > 0">
      <cdx-button
        action="destructive"
        weight="quiet"
        @click="clearConversation"
        :aria-label="msg('wanda-clear-conversation-aria')"
        class="wanda-clear-conversation-btn"
      >
        {{ msg('wanda-clear-conversation') }}
      </cdx-button>
    </div>

    <div class="chat-input-container">
      <cdx-checkbox
        v-model="usepublicknowledge"
        id="wanda-toggle-public-knowledge"
        :aria-label="msg('wanda-toggle-public-knowledge-aria')"
      >
        {{ msg('wanda-toggle-public-knowledge-label') }}
      </cdx-checkbox>
      <cdx-checkbox
        v-if="conversationMemoryAvailable"
        v-model="conversationMemoryEnabled"
        id="wanda-toggle-conversation-memory"
        :aria-label="msg('wanda-toggle-memory-aria')"
        @change="onMemoryToggleChange"
      >
        {{ msg('wanda-toggle-memory-label') }}
      </cdx-checkbox>

      <div class="wanda-attached-images" v-if="attachedImages.length > 0">
        <div
          v-for="(image, idx) in attachedImages"
          :key="idx"
          class="wanda-image-thumbnail"
        >
          <img :src="image.url" :alt="image.title" />
          <div class="wanda-image-info">
            <span class="wanda-image-title">{{ image.title }}</span>
            <span class="wanda-image-size">{{ formatFileSize(image.size) }}</span>
          </div>
          <button
            class="wanda-remove-image"
            @click.stop="removeImage(idx)"
            :aria-label="msg('wanda-remove-image-aria')"
          >×</button>
        </div>
      </div>

      <div class="wanda-floating-input-row">
        <cdx-button v-if="enableAttachments"
          class="wanda-attach-button"
          action="default"
          weight="quiet"
          @click="openImagePicker"
          :title="msg('wanda-attach-image-title')"
        >
          <cdx-icon :icon="cdxIconImage"></cdx-icon>
        </cdx-button>
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

    <cdx-dialog
      v-model:open="imagePickerOpen"
      :title="msg('wanda-image-picker-title')"
      class="wanda-image-picker-dialog"
    >
      <div class="wanda-image-picker-content">
        <cdx-text-input
          v-model="imageSearchQuery"
          :placeholder="msg('wanda-image-search-placeholder')"
          @input="searchImages"
        ></cdx-text-input>

        <div class="wanda-image-picker-results" v-if="searchResults.length > 0">
          <div
            v-for="result in searchResults"
            :key="result.title"
            class="wanda-image-result"
            @click="selectImage(result)"
            :class="{ disabled: !canAddImage(result) }"
            :title="stripFilePrefix(result.title)"
          >
            <img :src="result.thumburl || result.url" :alt="result.title" />
            <div class="wanda-image-result-info">
              <span class="wanda-image-result-title">{{ stripFilePrefix(result.title) }}</span>
            </div>
          </div>
        </div>

        <div v-else-if="imageSearchQuery && !searchingImages" class="wanda-no-results">
          {{ msg('wanda-image-no-results') }}
        </div>

        <div v-else-if="!imageSearchQuery && !searchingImages" class="wanda-search-placeholder">
          {{ msg('wanda-image-search-placeholder-text') }}
        </div>

        <div v-if="searchingImages" class="wanda-searching">
          <cdx-progress-bar :value="null" />
        </div>
      </div>

      <template #footer>
        <cdx-button @click="imagePickerOpen = false">
          {{ msg('wanda-close') }}
        </cdx-button>
      </template>
    </cdx-dialog>
  </div>

</template>

<script>
const { CdxButton, CdxTextArea, CdxProgressBar, CdxCheckbox, CdxDialog, CdxTextInput, CdxIcon } = require( '../../codex.js' );
const { cdxIconImage } = require( '../../icons.json' );

module.exports = exports = {
  name: 'ChatApp',
  components: { CdxButton, CdxTextArea, CdxProgressBar, CdxCheckbox, CdxDialog, CdxTextInput, CdxIcon },
  data() {
    return {
      inputText: '',
      messages: [],
      loading: false,
      usepublicknowledge: false,
      enableAttachments: !!mw.config.get( 'WandaEnableAttachments' ),
      attachedImages: [],
      imagePickerOpen: false,
      imageSearchQuery: '',
      searchResults: [],
      searchingImages: false,
      searchTimeout: null,
      searchAbortController: null,
      maxImageSize: mw.config.get( 'WandaMaxImageSize' ) || 5242880,
      maxImageCount: mw.config.get( 'WandaMaxImageCount' ) || 10,
      showConfidenceScore: !!mw.config.get( 'WandaShowConfidenceScore' ),
      cdxIconImage: cdxIconImage,
      conversationMemoryAvailable: !!mw.config.get( 'WandaEnableConversationMemory' ),
      conversationMemoryEnabled: !!mw.config.get( 'WandaEnableConversationMemory' ),
      conversationHistory: [],
      conversationImages: []
    };
  },
  mounted() {
    // Restore conversation state from sessionStorage
    if ( this.conversationMemoryAvailable ) {
      try {
        const savedToggle = sessionStorage.getItem( 'wanda-memory-enabled' );
        if ( savedToggle !== null ) {
          this.conversationMemoryEnabled = savedToggle === 'true';
        }
        const saved = sessionStorage.getItem( 'wanda-conversation-history' );
        const savedMessages = sessionStorage.getItem( 'wanda-conversation-messages' );
        if ( saved ) {
          this.conversationHistory = JSON.parse( saved );
        }
        if ( savedMessages ) {
          this.messages = JSON.parse( savedMessages );
          this.scrollToBottom();
        }
        const savedImages = sessionStorage.getItem( 'wanda-conversation-images' );
        if ( savedImages ) {
          this.conversationImages = JSON.parse( savedImages );
        }
      } catch ( e ) {
        // Ignore parse errors
      }
    }
  },
  computed: {
    sendLabel() {
      return 'Send';
    }
  },
  methods: {
    msg( key ) {
      return mw.message( key ).text();
    },
    stripFilePrefix( title ) {
      return title.replace( /^File:/i, '' );
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
    openImagePicker() {
      this.imagePickerOpen = true;
      this.imageSearchQuery = '';
      this.searchResults = [];
    },
    formatFileSize( bytes ) {
      if ( !bytes ) return '0 B';
      const k = 1024;
      const sizes = [ 'B', 'KB', 'MB', 'GB' ];
      const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
      return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[ i ];
    },
    getMaxSizeMessage() {
      return this.formatFileSize( this.maxImageSize );
    },
    canAddImage( image ) {
      if ( image.size > this.maxImageSize ) {
        return false;
      }
      return !this.attachedImages.some( img => img.title === image.title );
    },
    selectImage( image ) {
      if ( !this.canAddImage( image ) ) {
        if ( image.size > this.maxImageSize ) {
          const message = mw.message( 'wanda-image-too-large', this.getMaxSizeMessage() ).text();
          mw.notify( message, { type: 'error' } );
        } else {
          const message = mw.message( 'wanda-image-limit-reached', this.maxImageCount ).text();
          mw.notify( message, { type: 'error' } );
        }
        return;
      }

      this.attachedImages.push( {
        title: image.title,
        url: image.thumburl || image.url,
        fullUrl: image.url,
        size: image.size
      } );

      this.imagePickerOpen = false;
    },
    removeImage( index ) {
      this.attachedImages.splice( index, 1 );
    },
    searchImages() {
      clearTimeout( this.searchTimeout );

      if ( this.searchAbortController ) {
        this.searchAbortController.abort();
        this.searchAbortController = null;
      }

      const query = this.imageSearchQuery.trim();

      if ( !query ) {
        this.searchResults = [];
        this.searchingImages = false;
        return;
      }

      const delay = query.length === 1 ? 0 : 150;

      this.searchTimeout = setTimeout( async () => {
        this.searchingImages = true;

        this.searchAbortController = new AbortController();
        const currentController = this.searchAbortController;

        try {
          const api = new mw.Api();

          const searchResult = await api.get( {
            action: 'query',
            format: 'json',
            list: 'allpages',
            apprefix: query,
            apnamespace: 6,
            aplimit: 30
          } );

          if ( currentController !== this.searchAbortController ) {
            return;
          }

          if ( !searchResult.query || !searchResult.query.allpages || searchResult.query.allpages.length === 0 ) {
            this.searchResults = [];
            this.searchingImages = false;
            return;
          }

          const titles = searchResult.query.allpages.map( item => item.title ).join( '|' );

          const imageResult = await api.get( {
            action: 'query',
            format: 'json',
            titles: titles,
            prop: 'imageinfo',
            iiprop: 'url|size|mime',
            iiurlwidth: 200
          } );

          if ( currentController !== this.searchAbortController ) {
            return;
          }

          if ( imageResult.query && imageResult.query.pages ) {
            this.searchResults = Object.values( imageResult.query.pages )
              .filter( page => page.imageinfo && page.imageinfo.length > 0 )
              .map( page => {
                const imageInfo = page.imageinfo[ 0 ];
                return {
                  title: page.title,
                  url: imageInfo.url,
                  thumburl: imageInfo.thumburl || imageInfo.url,
                  size: imageInfo.size,
                  mime: imageInfo.mime
                };
              } )
              .filter( img => img.mime && img.mime.startsWith( 'image/' ) );
          } else {
            this.searchResults = [];
          }
        } catch ( e ) {
          if ( e.name === 'AbortError' || currentController !== this.searchAbortController ) {
            return;
          }
          console.error( 'Image search error:', e );
          mw.notify( this.msg( 'wanda-image-search-error' ), { type: 'error' } );
          this.searchResults = [];
        } finally {
          if ( currentController === this.searchAbortController ) {
            this.searchingImages = false;
            this.searchAbortController = null;
          }
        }
      }, delay );
    },
    async sendMessage() {
      const userText = this.inputText.trim();
      if ( !userText || this.loading ) {
        return;
      }

      const imagesToSend = [ ...this.attachedImages ];

      const message = {
        role: 'user',
        content: this.escapeHtml( userText ),
        images: imagesToSend.map( img => ({ url: img.url, title: img.title }) )
      };
      this.messages.push( message );
      this.scrollToBottom();

      this.inputText = '';
      this.attachedImages = [];
      this.loading = true;

      try {
        const api = new mw.Api();
        const postData = {
          action: 'wandachat',
          format: 'json',
          message: userText,
          usepublicknowledge: this.usepublicknowledge ? true : false
        };

        if ( imagesToSend.length > 0 ) {
          postData.images = imagesToSend.map( img => img.title ).join( '|' );
        } else if ( this.conversationMemoryEnabled && this.conversationImages.length > 0 ) {
          // Re-include images from earlier in the conversation
          postData.images = this.conversationImages.join( '|' );
        }

        // Send conversation history if memory is enabled
        if ( this.conversationMemoryEnabled && this.conversationHistory.length > 0 ) {
          postData.conversationhistory = JSON.stringify( this.conversationHistory );
        }

        // Tell backend memory is disabled so LLM can respond appropriately
        if ( this.conversationMemoryAvailable && !this.conversationMemoryEnabled ) {
          postData.memorydisabled = true;
        }

        const data = await api.post( postData );

        let response = data && data.response ? data.response : 'Error fetching response';
        let rawResponse = response;
        if ( response === 'NO_MATCHING_CONTEXT' ) {
          response = this.msg( 'wanda-llm-response-nocontext' );
          rawResponse = response;
        } else if ( response.includes( '<PUBLIC_KNOWLEDGE>' ) ) {
          rawResponse = response.replace( '<PUBLIC_KNOWLEDGE>', '' );
          response = rawResponse;
          response += '<br><b>Source</b>: Public';
        } else if ( data && data.sources && data.sources.length > 0 ) {
          const label = data.sources.length === 1 ? 'Source' : 'Sources';
          const sourceLinks = data.sources.map( ( s ) => {
            const title = s.title || s;
            const href = mw.util.getUrl( title );
            const safeTitle = this.escapeHtml( title );
            let link = '<a target="_blank" href="' + href + '">' + safeTitle + '</a>';
            if ( this.showConfidenceScore && s.score !== undefined ) {
              link += ' <small>(' + s.score + ')</small>';
            }
            return link;
          } );
          response += '<br><br><b>' + label + '</b>:<ul class="wanda-sources-list">';
          sourceLinks.forEach( ( link ) => {
            response += '<li>' + link + '</li>';
          } );
          response += '</ul>';
        } else if ( data && data.source ) {
          const sources = data.source.split( ', ' ).filter( ( s ) => s.trim() );
          const label = sources.length === 1 ? 'Source' : 'Sources';
          const sourceLinks = sources.map( ( title ) => {
            const href = mw.util.getUrl( title.trim() );
            const safeTitle = this.escapeHtml( title.trim() );
            return '<a target="_blank" href="' + href + '">' + safeTitle + '</a>';
          } );
          response += '<br><br><b>' + label + '</b>:<ul class="wanda-sources-list">';
          sourceLinks.forEach( ( link ) => {
            response += '<li>' + link + '</li>';
          } );
          response += '</ul>';
        }
        this.addMessage( 'bot', response );

        // Update conversation history
        if ( this.conversationMemoryEnabled ) {
          let historyContent = userText;
          if ( imagesToSend.length > 0 ) {
            historyContent += '\n[Attached images: ' + imagesToSend.map( img => img.title ).join( ', ' ) + ']';
          }
          this.conversationHistory.push( { role: 'user', content: historyContent } );
          this.conversationHistory.push( { role: 'assistant', content: rawResponse } );
          if ( imagesToSend.length > 0 ) {
            this.conversationImages = imagesToSend.map( img => img.title );
          }
          this.saveConversationState();
        }
      } catch ( e ) {
        this.addMessage( 'bot', 'Error connecting to Wanda chatbot API.' );
      } finally {
        this.loading = false;
      }
    },
    clearConversation() {
      this.messages = [];
      this.conversationHistory = [];
      this.conversationImages = [];
      this.saveConversationState();
    },
    saveConversationState() {
      try {
        sessionStorage.setItem( 'wanda-conversation-history', JSON.stringify( this.conversationHistory ) );
        sessionStorage.setItem( 'wanda-conversation-messages', JSON.stringify( this.messages ) );
        sessionStorage.setItem( 'wanda-conversation-images', JSON.stringify( this.conversationImages ) );
      } catch ( e ) {
        // Ignore storage errors
      }
    },
    onMemoryToggleChange() {
      try {
        sessionStorage.setItem( 'wanda-memory-enabled', String( this.conversationMemoryEnabled ) );
      } catch ( e ) {
        // Ignore storage errors
      }
      if ( !this.conversationMemoryEnabled ) {
        this.conversationHistory = [];
        this.saveConversationState();
      }
    }
  }
};
</script>
