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

    <div class="chat-input-container">
      <cdx-checkbox
        v-model="usepublicknowledge"
        id="wanda-toggle-public-knowledge"
        :aria-label="msg('wanda-toggle-public-knowledge-aria')"
      >
        {{ msg('wanda-toggle-public-knowledge-label') }}
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
        <cdx-button
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
      attachedImages: [],
      imagePickerOpen: false,
      imageSearchQuery: '',
      searchResults: [],
      searchingImages: false,
      searchTimeout: null,
      searchAbortController: null,
      maxImageSize: mw.config.get( 'WandaMaxImageSize' ) || 5242880,
      maxImageCount: mw.config.get( 'WandaMaxImageCount' ) || 10,
      cdxIconImage: cdxIconImage
    };
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
        }

        const data = await api.post( postData );

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
