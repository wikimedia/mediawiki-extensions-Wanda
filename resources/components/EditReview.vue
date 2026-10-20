<template>
  <cdx-dialog
    :open="open"
    :title="msg( 'wanda-edit-preview-title' )"
    class="wanda-edit-preview-dialog"
    @update:open="onOpenChange"
  >
    <div class="wanda-edit-preview-content">
      <p v-if="operation && operation !== 'generating'" role="status">{{ msg( 'wanda-edit-' + operation ) }}</p>
      <p v-if="stopped" role="status">{{ msg( 'wanda-edit-stopped' ) }}</p>
      <p v-if="error" role="alert">{{ error }}</p>
      <template v-if="plan">
        <p>{{ msg( 'wanda-edit-scope', plan.scope ) }}</p>
        <p v-if="plan.truncated" role="alert">{{ msg( 'wanda-edit-truncated', plan.maxpages ) }}</p>
        <p v-if="!pages.length">{{ msg( 'wanda-edit-no-candidates' ) }}</p>
        <fieldset v-else-if="pages.length > 1">
          <legend>{{ msg( 'wanda-edit-candidates' ) }}</legend>
          <div v-for="(page, index) in pages" :key="index">
            <label>
              <input
                type="checkbox"
                class="wanda-edit-candidate"
                :checked="page.selected"
                :disabled="busy || page.status === 'applied'"
                @change="$emit( 'select', index, 'selected', $event.target.checked )"
              />
              {{ page.title }}
            </label>
          </div>
        </fieldset>
        <template v-if="hasStartedPreviews">
          <section v-if="research.length">
            <h3>{{ msg( 'wanda-edit-research' ) }}</h3>
            <ul>
              <li v-for="item in research" :key="item.source">
                {{ item.source }} — {{ msg( 'wanda-edit-research-' + item.status ) }}
                <p v-if="item.error" role="alert">{{ item.error }}</p>
                <ul v-if="item.sources.length">
                  <li v-for="(source, index) in item.sources" :key="index">
                    <a :href="safeUrl( source.href, source.title )" target="_blank" rel="noopener noreferrer">{{ source.title }}</a>
                  </li>
                </ul>
              </li>
            </ul>
          </section>
          <template v-for="(page, index) in pages" :key="index">
            <section v-if="page.selected || page.status !== 'pending'" class="wanda-edit-proposal">
              <h3>{{ page.title }}</h3>
              <p role="status">{{ statusLabel( page ) }}</p>
              <p v-if="page.error" role="alert">{{ page.error }}</p>
              <label v-if="page.proposalid">
                <input
                  type="checkbox"
                  class="wanda-edit-approval"
                  :checked="page.approved"
                  :disabled="busy || !page.selected || page.status === 'applied'"
                  @change="$emit( 'select', index, 'approved', $event.target.checked )"
                />
                {{ msg( 'wanda-edit-select-proposal', page.title ) }}
              </label>
              <p v-if="page.summary">{{ page.summary }}</p>
              <p v-if="page.explanation">{{ page.explanation }}</p>
              <details v-if="page.diff">
                <summary>{{ msg( 'wanda-edit-show-diff' ) }}</summary>
                <!-- Only the server-rendered MediaWiki diff is HTML; all model prose is text. -->
                <div class="wanda-edit-diff" v-html="page.diff"></div>
              </details>
              <div v-if="page.sources && page.sources.length">
                <h4>{{ msg( 'wanda-edit-provenance' ) }}</h4>
                <ul>
                  <li v-for="(source, sourceIndex) in page.sources" :key="sourceIndex">
                    <a :href="safeUrl( source.href, source.title )" target="_blank" rel="noopener noreferrer">{{ source.title }}</a>
                  </li>
                </ul>
              </div>
              <p v-if="page.result">
                <a :href="safeUrl( page.result.diffurl, page.result.title || page.title )" target="_blank" rel="noopener noreferrer">
                  {{ msg( 'wanda-edit-view-result', page.result.newrevid || '', page.result.title || page.title ) }}
                </a>
              </p>
            </section>
          </template>
        </template>
      </template>
    </div>
    <template #footer>
      <cdx-button
        v-if="pages.length > 1 || canGenerate"
        class="wanda-edit-generate"
        :disabled="!canGenerate"
        @click="$emit( 'generate' )"
      >
        {{ msg( 'wanda-edit-generate-previews' ) }}
      </cdx-button>
      <cdx-button class="wanda-edit-apply-selected" action="progressive" weight="primary" :disabled="!canApply" @click="$emit( 'apply' )">
        {{ msg( 'wanda-edit-apply-selected' ) }}
      </cdx-button>
      <cdx-button v-if="busy" :disabled="stopped" @click="$emit( 'cancel' )">
        {{ msg( 'wanda-edit-stop' ) }}
      </cdx-button>
      <cdx-button v-else @click="$emit( 'dismiss' )">{{ msg( 'wanda-close' ) }}</cdx-button>
    </template>
  </cdx-dialog>
</template>

<script>
const { CdxButton, CdxDialog } = require( '../../codex.js' );

module.exports = exports = {
  name: 'EditReview',
  components: { CdxButton, CdxDialog },
  props: {
    open: Boolean,
    plan: { type: Object, default: null },
    pages: { type: Array, default: () => [] },
    research: { type: Array, default: () => [] },
    busy: Boolean,
    stopped: Boolean,
    operation: { type: String, default: '' },
    error: { type: String, default: '' },
    canGenerate: Boolean,
    canApply: Boolean
  },
  emits: [ 'select', 'generate', 'apply', 'cancel', 'dismiss' ],
  computed: {
    hasStartedPreviews() {
      return this.pages.length === 1 ||
        this.operation === 'generating' ||
        this.pages.some( ( p ) => p.status !== 'pending' || !!p.proposalid || !!p.error || !!p.diff );
    }
  },
  methods: {
    msg( key, ...args ) {
      return mw.message( key, ...args ).text();
    },
    onOpenChange( open ) {
      if ( !open ) {
        // A controlled prop prevents Escape/backdrop dismissal from hiding an in-flight save.
        this.$emit( 'dismiss' );
      }
    },
    statusLabel( page ) {
      if ( page.status === 'applied' ) {
        return this.msg( page.result.new ? 'wanda-edit-created' : 'wanda-edit-success' );
      }
      return this.msg( page.status === 'nochange' ? 'wanda-edit-no-change' : 'wanda-edit-status-' + page.status );
    },
    safeUrl( href, title ) {
      // Reject executable/data URLs, including whitespace-obfuscated schemes.
      if ( typeof href === 'string' && /^(https?:\/\/|\/[^/\\])/i.test( href ) && !/[\s\\]/.test( href ) ) {
        return href;
      }
      return mw.util.getUrl( title || '' );
    }
  }
};
</script>