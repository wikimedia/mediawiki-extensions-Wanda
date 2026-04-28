<template>
  <div class="chat-container">
    <div class="chat-box" ref="chatBox" v-show="messages.length > 0">
      <div
        v-for="(m, idx) in messages"
        :key="idx"
        :class="['chat-message-wrapper', m.role + '-wrapper']"
      >
        <div class="chat-message-container">
          <div class="chat-message" :class="[ m.role + '-message' ]">
            <div v-html="m.content"></div>
            <div v-if="m.role === 'user' && m.sources && m.sources.length > 0" class="wanda-message-sources">
              <span
                v-for="src in m.sources"
                :key="src"
                class="wanda-source-chip"
              >{{ sourceLabel( src ) }}</span>
            </div>
            <cdx-accordion
              v-if="m.role === 'bot' && m.steps && m.steps.length > 0"
              class="wanda-thinking"
              :open="true"
            >
              <template #title>{{ msg( 'wanda-thinking-label' ) }}</template>
              <ol class="wanda-thinking-steps">
                <li
                  v-for="(s, sIdx) in m.steps"
                  :key="sIdx"
                  :class="'wanda-step wanda-step--' + ( s.type || 'query' )"
                >
                  {{ formatStepDesc( s ) }}
                  <pre v-if="s.source === 'wikidata' && s.sparql" class="wanda-sparql-query"><code>{{ s.sparql }}</code></pre>
                </li>
              </ol>
            </cdx-accordion>
          </div>
          <div v-if="m.role === 'user' && m.images && m.images.length > 0" class="wanda-message-images">
            <img
              v-for="(image, imgIdx) in m.images"
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
        v-if="conversationMemoryAvailable"
        v-model="conversationMemoryEnabled"
        id="wanda-toggle-conversation-memory"
        :aria-label="msg('wanda-toggle-memory-aria')"
        @change="onMemoryToggleChange"
      >
        {{ msg('wanda-toggle-memory-label') }}
      </cdx-checkbox>

      <div class="wanda-question-card">
        <div class="wanda-sources-row">
          <span class="wanda-sources-label">Sources:</span>
          <cdx-multiselect-lookup
            id="wanda-source-selector"
            v-model:input-chips="chips"
            v-model:selected="selectedValues"
            :menu-items="menuItems"
            class="wanda-source-selector"
            @input="onInput"
          ></cdx-multiselect-lookup>
        </div>

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
const { CdxButton, CdxTextArea, CdxProgressBar, CdxCheckbox, CdxDialog, CdxTextInput, CdxIcon, CdxAccordion, CdxMultiselectLookup } = require( '../../codex.js' );
const { cdxIconImage } = require( '../../icons.json' );
const { ref } = require( 'vue' );

const ALL_SOURCE_OPTIONS = [
  { value: 'wiki', label: 'Wiki' },
  { value: 'wikidata', label: 'Wikidata' },
  { value: 'publicknowledge', label: 'Public knowledge' },
  { value: 'cargo', label: 'Cargo' }
];

module.exports = exports = {
  name: 'ChatApp',
  components: { CdxButton, CdxTextArea, CdxProgressBar, CdxCheckbox, CdxDialog, CdxTextInput, CdxIcon, CdxAccordion, CdxMultiselectLookup },
  setup() {
    const chips = ref( [ { value: 'wiki', label: 'Wiki' } ] );
    const selectedValues = ref( [ 'wiki' ] );
    const menuItems = ref( [] );

    function onInput( value ) {
      const q = value ? value.toLowerCase() : '';
      menuItems.value = ALL_SOURCE_OPTIONS.filter(
        ( opt ) => !selectedValues.value.includes( opt.value ) &&
          ( !q || opt.label.toLowerCase().includes( q ) )
      );
    }

    return {
      chips,
      selectedValues,
      menuItems,
      onInput
    };
  },
  data() {
    return {
      inputText: '',
      messages: [],
      loading: false,
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
    addMessage( role, content, steps ) {
      const message = { role, content };
      if ( steps && steps.length > 0 ) {
        message.steps = steps;
      }
      this.messages.push( message );
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

  applyInlineMarkdown( safeHtmlText ) {
    let s = String( safeHtmlText );
    // Bold: **text**
    s = s.replace( /\*\*([^*]+)\*\*/g, '<b>$1</b>' );
    // Italic: *text* (avoid matching **)
    s = s.replace( /(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<i>$2</i>' );
    return s;
  },

  isMarkdownTableSeparator( line ) {
    const s = String( line || '' ).trim();
    // Typical: |---|---:|:---|
    return /^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?$/.test( s );
  },

  splitMarkdownTableRow( line ) {
    let s = String( line || '' ).trim();
    // Allow quoted table blocks like "| a | b |"
    if ( s.startsWith( '"' ) && s.endsWith( '"' ) && s.length >= 2 ) {
      s = s.slice( 1, -1 ).trim();
    }
    if ( s.startsWith( '|' ) ) {
      s = s.slice( 1 );
    }
    if ( s.endsWith( '|' ) ) {
      s = s.slice( 0, -1 );
    }
    return s.split( '|' ).map( ( c ) => c.trim() );
  },

  renderMarkdownTableFromLines( lines, startIndex ) {
    const headerCells = this.splitMarkdownTableRow( lines[ startIndex ] );
    let i = startIndex + 2; // skip separator
    const bodyRows = [];

    while ( i < lines.length ) {
      const raw = String( lines[ i ] || '' );
      const trimmed = raw.trim();
      if ( trimmed === '' ) {
        break;
      }
      // Stop if line doesn't look like a table row.
      if ( trimmed.indexOf( '|' ) === -1 ) {
        break;
      }
      if ( this.isMarkdownTableSeparator( trimmed ) ) {
        break;
      }
      bodyRows.push( this.splitMarkdownTableRow( raw ) );
      i++;
    }

    const formatCell = ( cell ) => this.applyInlineMarkdown( this.escapeHtml( cell ) );

    let tableHtml = '<div class="wanda-table-wrap"><table class="wanda-table">';
    tableHtml += '<thead><tr>';
    headerCells.forEach( ( h ) => {
      tableHtml += '<th>' + formatCell( h ) + '</th>';
    } );
    tableHtml += '</tr></thead>';
    tableHtml += '<tbody>';
    bodyRows.forEach( ( row ) => {
      tableHtml += '<tr>';
      for ( let c = 0; c < headerCells.length; c++ ) {
        const v = row[ c ] !== undefined ? row[ c ] : '';
        tableHtml += '<td>' + formatCell( v ) + '</td>';
      }
      tableHtml += '</tr>';
    } );
    tableHtml += '</tbody></table></div>';

    return { html: tableHtml, endIndex: i - 1 };
  },

  formatBotResponse( rawText ) {
    let text = String( rawText || '' ).replace( /\r\n/g, '\n' );
    const newlineCount = ( text.match( /\n/g ) || [] ).length;

    // Heuristic: if model returned Markdown-ish structure but without newlines,
    // insert breaks before headings and list items.
    if ( newlineCount < 2 && text.includes( '###' ) ) {
      text = text.replace( /\s*###\s+/g, '\n\n### ' );
      const dashCount = ( text.match( /\s-\s/g ) || [] ).length;
      if ( dashCount >= 2 ) {
        text = text.replace( /\s-\s+/g, '\n- ' );
      }
    }

    text = text.trim();
    if ( !text ) {
      return '';
    }

    const lines = text.split( '\n' );
    let html = '';
    let inList = false;
    let pendingBreak = false;

    for ( let i = 0; i < lines.length; i++ ) {
      const rawLine = lines[ i ];
      const line = rawLine.trim();

      // Markdown table: header row + separator row
      if ( i + 1 < lines.length && line.indexOf( '|' ) !== -1 && this.isMarkdownTableSeparator( lines[ i + 1 ] ) ) {
        if ( inList ) {
          html += '</ul>';
          inList = false;
        }
        if ( pendingBreak && html !== '' ) {
          html += '<br><br>';
        }

        const rendered = this.renderMarkdownTableFromLines( lines, i );
        html += rendered.html;
        i = rendered.endIndex;
        pendingBreak = false;
        continue;
      }

      if ( line === '' ) {
        if ( inList ) {
          html += '</ul>';
          inList = false;
        }
        pendingBreak = true;
        continue;
      }

      if ( line.startsWith( '### ' ) ) {
        if ( inList ) {
          html += '</ul>';
          inList = false;
        }
        if ( pendingBreak && html !== '' ) {
          html += '<br><br>';
        }
        const title = this.applyInlineMarkdown( this.escapeHtml( line.slice( 4 ).trim() ) );
        html += '<b>' + title + '</b><br>';
        pendingBreak = false;
        continue;
      }

      if ( line.startsWith( '- ' ) ) {
        if ( pendingBreak && html !== '' ) {
          html += '<br><br>';
        }
        if ( !inList ) {
          html += '<ul>';
          inList = true;
        }
        const item = this.applyInlineMarkdown( this.escapeHtml( line.slice( 2 ).trim() ) );
        html += '<li>' + item + '</li>';
        pendingBreak = false;
        continue;
      }

      if ( inList ) {
        html += '</ul>';
        inList = false;
      }
      if ( pendingBreak && html !== '' ) {
        html += '<br><br>';
      } else if ( html !== '' ) {
        html += '<br>';
      }

      html += this.applyInlineMarkdown( this.escapeHtml( rawLine ) );
      pendingBreak = false;
    }

    if ( inList ) {
      html += '</ul>';
    }

    return html;
  },
    sourceLabel( value ) {
      const labels = { wiki: 'Wiki', wikidata: 'Wikidata', publicknowledge: 'Public knowledge', cargo: 'Cargo' };
      return labels[ value ] || value;
    },
    formatStepDesc( s ) {
      const stepLabel = s.step ? 'Step ' + s.step + ': ' : '';
      if ( s.source === 'wikidata' ) {
        if ( s.type === 'error' ) {
          let desc = stepLabel + 'Wikidata: ' + ( s.message || 'query failed' );
          return desc;
        }
        const rowWord = s.rows === 1 ? 'row' : 'rows';
        let desc = stepLabel + 'Wikidata (' + s.rows + ' ' + rowWord + ')';
        if ( s.reasoning ) {
          desc += ' \u2014 ' + s.reasoning;
        }
        return desc;
      }
      const tables = s.tables || s.table || 'unknown';
      if ( s.type === 'error' ) {
        let desc = stepLabel + 'Failed querying ' + tables;
        if ( s.where ) {
          desc += ' where ' + s.where;
        }
        if ( s.join_on ) {
          desc += ' [join: ' + s.join_on + ']';
        }
        if ( s.message ) {
          desc += ' \u2014 ' + s.message;
        }
        return desc;
      }
      const rowWord = s.rows === 1 ? 'row' : 'rows';
      let desc = stepLabel + 'Queried ' + tables + ' (' + s.rows + ' ' + rowWord + ')';
      if ( s.where ) {
        desc += ' where ' + s.where;
      }
      if ( s.join_on ) {
        desc += ' [join: ' + s.join_on + ']';
      }
      if ( s.reasoning ) {
        desc += ' \u2014 ' + s.reasoning;
      }
      return desc;
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
      const sourcesToSend = this.selectedValues.length > 0
        ? [ ...this.selectedValues ]
        : [ 'wiki' ];

      const message = {
        role: 'user',
        content: this.escapeHtml( userText ),
        images: imagesToSend.map( img => ({ url: img.url, title: img.title }) ),
        sources: [ ...sourcesToSend ]
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
          sources: sourcesToSend.join( '|' )
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

        let responseText = data && data.response ? data.response : 'Error fetching response';
        let rawResponse = responseText;
        if ( responseText === 'NO_MATCHING_CONTEXT' ) {
          rawResponse = this.msg( 'wanda-llm-response-nocontext' );
        } else if ( responseText.includes( '<PUBLIC_KNOWLEDGE>' ) ) {
          rawResponse = responseText.replace( '<PUBLIC_KNOWLEDGE>', '' );
        }

        let response = this.formatBotResponse( rawResponse );

        if ( responseText.includes( '<PUBLIC_KNOWLEDGE>' ) ) {
          response += '<br><b>Source</b>: Public';
        } else if ( data && data.sources && data.sources.length > 0 ) {
          const label = data.sources.length === 1 ? 'Source' : 'Sources';
          const sourceLinks = data.sources.map( ( s ) => {
            const title = s.title || s;
            const href = s.href ? s.href : mw.util.getUrl( title );
            const safeTitle = this.escapeHtml( title );
            let link;
            if ( s.type === 'cargo' && s.cargoTable && s.tableHref ) {
              const safeTable = this.escapeHtml( s.cargoTable );
              link = '<a target="_blank" href="' + href + '">' + safeTitle + '</a>' +
                ' (<a target="_blank" href="' + s.tableHref + '">' + safeTable + '</a>)';
            } else {
              link = '<a target="_blank" href="' + href + '">' + safeTitle + '</a>';
            }
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
        const allSteps = [
          ...( ( data && data.cargoSteps ) || [] ).map( ( s ) => Object.assign( {}, s, { source: 'cargo' } ) ),
          ...( ( data && data.wikidataSteps ) || [] ).map( ( s ) => Object.assign( {}, s, { source: 'wikidata' } ) )
        ];
        this.addMessage( 'bot', response, allSteps.length > 0 ? allSteps : null );

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
