// Shared plan -> research -> preview -> explicit approval workflow for both chat UIs.
// `Promise.prototype.finally` is ES2018; this project's browser support target is ES6,
// so a small helper reproduces the same run-regardless-of-outcome semantics via then/catch.
function always( promise, callback ) {
  return promise.then( ( value ) => {
    callback();
    return value;
  }, ( error ) => {
    callback();
    throw error;
  } );
}

// Runs zero-argument `steps` one at a time, in order, only starting the next step once
// the previous one's returned promise (if any) settles. Mirrors a `for` loop with
// `await` inside it (including running the first step's synchronous work immediately,
// before any microtask tick) without using `async`/`await` syntax, which this project's
// ES6-only lint target does not allow in plain .js files (Vue SFCs are exempt).
function runSteps( steps ) {
  return new Promise( ( resolve, reject ) => {
    function next( index ) {
      if ( index >= steps.length ) {
        resolve();
        return;
      }
      let result;
      try {
        result = steps[ index ]();
      } catch ( error ) {
        reject( error );
        return;
      }
      if ( result && typeof result.then === 'function' ) {
        result.then( () => next( index + 1 ), reject );
      } else {
        next( index + 1 );
      }
    }
    next( 0 );
  } );
}

module.exports = {
  data() {
    return {
      editModeEnabled: false,
      editTarget: ( mw.config.get( 'wgNamespaceNumber' ) >= 0 && mw.config.get( 'wgPageName' ) ) ?
        mw.config.get( 'wgPageName' ).replace( /_/g, ' ' ) : '',
      editTargetSelected: null,
      editTargetSuggestions: [],
      editTargetSearchTimeout: null,
      editTargetRequestId: 0,
      editDialogOpen: false,
      editPlan: null,
      editPages: [],
      editResearch: [],
      editBusy: false,
      editOperation: '',
      editRun: 0,
      editStopped: false,
      editError: ''
    };
  },
  computed: {
    canGenerateEdits() {
      return !this.editBusy && !!this.editPlan && this.editPages.some( ( page ) => page.selected &&
        [ 'pending', 'preview-failed' ].includes( page.status )
      );
    },
    canApplyEdits() {
      return !this.editBusy && this.editPages.some( this.isEditApplicable );
    }
  },
  beforeUnmount() {
    this.cancelEdit();
    this.editRun++;
    clearTimeout( this.editTargetSearchTimeout );
    this.editTargetRequestId++;
  },
  methods: {
    editMessage( key, ...args ) {
      return mw.message( key, ...args ).text();
    },
    isEditApplicable( page ) {
      return page.selected && page.approved && !!page.proposalid &&
        [ 'ready', 'save-failed', 'unknown' ].includes( page.status );
    },
    setEditSelection( index, field, value ) {
      const page = this.editPages[ index ];
      if ( !this.editBusy && page && page.status !== 'applied' &&
        [ 'selected', 'approved' ].includes( field ) ) {
        page[ field ] = value;
      }
    },
    postEdit( params ) {
      const api = new mw.Api();
      // Never send confirm (even 0) until the user explicitly clicks Apply.
      return api.postWithToken( 'csrf', Object.assign( {
        action: 'wandaedit', format: 'json', formatversion: 2
      }, params ) ).catch( ( code, result ) => {
        const error = result && result.error;
        throw new Error( ( error && ( error.info || error.code ) ) ||
          ( code && code.message ) || ( typeof code === 'string' && code ) ||
          this.editMessage( 'wanda-edit-error' ) );
      } ).then( ( data ) => {
        if ( !data || data.error ) {
          throw new Error( ( data && data.error && ( data.error.info || data.error.code ) ) ||
            this.editMessage( 'wanda-edit-error' ) );
        }
        return data;
      } );
    },
    requestEdit( userText ) {
      if ( this.editBusy || this.loading || !userText.trim() ) {
        return undefined;
      }
      const run = ++this.editRun;
      this.editPlan = null;
      this.editPages = [];
      this.editResearch = [];
      this.editError = '';
      this.editStopped = false;
      this.editBusy = true;
      this.editOperation = 'planning';
      this.editDialogOpen = true;
      this.inputText = '';
      const sources = this.selectedValues.length ? [ ...this.selectedValues ] : [ 'wiki' ];
      this.messages.push( { role: 'user', content: this.escapeHtml( userText ), sources } );
      this.scrollToBottom();
      const plan = this.postEdit( {
        stage: 'plan', message: userText, title: this.editTarget.trim(), sources: sources.join( '|' )
      } ).then( ( data ) => {
        if ( run !== this.editRun ) {
          return;
        }
        if ( !data.batchid || !Array.isArray( data.candidates ) || !Array.isArray( data.sources ) ) {
          throw new Error( this.editMessage( 'wanda-edit-error' ) );
        }
        this.editPlan = data;
        this.editPages = data.candidates.map( ( candidate ) => ( {
          title: candidate.title, selected: true, approved: false, status: 'pending',
          proposalid: null, diff: '', summary: '', explanation: '', sources: [], error: '', result: null
        } ) );
        // Only the server-approved research sources are executed, including an empty list.
        this.editResearch = data.sources.map( ( source ) => ( {
          source: source, status: 'pending', sources: [], error: ''
        } ) );
        // Single-page edits immediately start generating previews so the user does not have
        // to click an extra "Generate previews" button before seeing the diff.
        if ( this.editPages.length === 1 ) {
          return this.runPreviewSequence( run );
        }
      } ).catch( ( error ) => {
        if ( run === this.editRun ) {
          this.editError = error.message;
        }
      } );
      return always( plan, () => {
        // Keep the lock until the in-flight request settles, even after cancellation.
        this.editBusy = false;
        this.editOperation = '';
      } );
    },
    runPreviewSequence( run ) {
      this.editBusy = true;
      this.editStopped = false;
      this.editError = '';
      this.editOperation = 'generating';
      // `halted` short-circuits every remaining step (without invoking them) once
      // cancellation is detected or a research fetch fails, mirroring the early
      // `return` statements a for-loop with await would use. Research and preview
      // steps are run as a single flat sequence (not two separately-chained loops)
      // so an empty research list falls straight into the first preview step in the
      // same synchronous tick, exactly like two consecutive `for` loops would.
      let halted = false;
      const researchSteps = this.editResearch.map( ( research ) => () => {
        if ( halted || run !== this.editRun ) {
          halted = true;
          return undefined;
        }
        if ( research.status === 'done' ) {
          return undefined;
        }
        research.status = 'running';
        research.error = '';
        return this.postEdit( {
          stage: 'context', batchid: this.editPlan.batchid, source: research.source
        } ).then( ( data ) => {
          if ( run !== this.editRun ) {
            halted = true;
            return;
          }
          research.sources = data.sources || [];
          research.status = 'done';
        } ).catch( ( error ) => {
          if ( run === this.editRun ) {
            research.status = 'failed';
            research.error = error.message;
          }
          // Do not generate proposals from incomplete research. Generate retries this source.
          halted = true;
        } );
      } );
      const previewSteps = this.editPages.map( ( page ) => () => {
        if ( halted || run !== this.editRun ) {
          return undefined;
        }
        if ( !page.selected || ![ 'pending', 'preview-failed' ].includes( page.status ) ) {
          return undefined;
        }
        page.status = 'previewing';
        page.error = '';
        return this.postEdit( {
          stage: 'preview', batchid: this.editPlan.batchid, title: page.title
        } ).then( ( data ) => {
          if ( run !== this.editRun ) {
            return;
          }
          if ( typeof data.changed !== 'boolean' || ( data.changed && !data.proposalid ) ) {
            throw new Error( this.editMessage( 'wanda-edit-error' ) );
          }
          Object.assign( page, {
            title: data.title || page.title, proposalid: data.changed ? data.proposalid : null,
            diff: data.diff || '', summary: data.summary || '', explanation: data.explanation || '',
            sources: data.sources || [], status: data.changed ? 'ready' : 'nochange', approved: data.changed
          } );
        } ).catch( ( error ) => {
          if ( run === this.editRun ) {
            page.status = 'preview-failed';
            page.error = error.message;
          }
        } );
      } );
      return runSteps( researchSteps.concat( previewSteps ) );
    },
    generateEditPreviews() {
      if ( !this.canGenerateEdits ) {
        return undefined;
      }
      const run = ++this.editRun;
      return always( this.runPreviewSequence( run ), () => {
        this.editBusy = false;
        this.editOperation = '';
      } );
    },
    applyEdit() {
      if ( !this.canApplyEdits ) {
        return undefined;
      }
      const run = ++this.editRun;
      this.editBusy = true;
      this.editStopped = false;
      this.editOperation = 'applying';
      const applySteps = this.editPages.map( ( page ) => () => {
        if ( run !== this.editRun ) {
          return undefined;
        }
        if ( !this.isEditApplicable( page ) ) {
          return undefined;
        }
        page.status = 'saving';
        page.error = '';
        // The server owns the revision-bound proposal; never echo newtext or instructions.
        return this.postEdit( { confirm: 1, proposalid: page.proposalid } ).then( ( data ) => {
          if ( run !== this.editRun ) {
            return;
          }
          if ( data.result !== 'success' ) {
            throw new Error( this.editMessage( 'wanda-edit-error' ) );
          }
          page.result = data;
          page.status = 'applied';
          page.approved = false;
        } ).catch( ( error ) => {
          if ( run === this.editRun ) {
            page.status = 'save-failed';
            page.error = error.message;
          }
        } );
      } );
      return always( runSteps( applySteps ), () => {
        this.editBusy = false;
        this.editOperation = '';
      } );
    },
    cancelEdit() {
      if ( !this.editBusy || this.editStopped ) {
        return;
      }
      this.editRun++;
      this.editStopped = true;
      this.editPages.forEach( ( page ) => {
        if ( page.status === 'saving' ) {
          // The save may already have committed. Retrying the same proposal is server-idempotent.
          page.status = 'unknown';
        } else if ( page.status === 'previewing' ) {
          page.status = 'pending';
        }
      } );
      this.editResearch.forEach( ( research ) => {
        if ( research.status === 'running' ) {
          research.status = 'pending';
        }
      } );
    },
    dismissEditReview() {
      if ( this.editBusy ) {
        this.cancelEdit();
        return;
      }
      this.editDialogOpen = false;
    },
    searchEditTargets() {
      clearTimeout( this.editTargetSearchTimeout );
      // Bump the request id so any in-flight request is treated as stale. A plain
      // number is used (rather than an object token stored in reactive data) because
      // Vue wraps objects assigned to reactive data in a Proxy, which would make an
      // identity (`===`) comparison against the original reference always fail.
      this.editTargetRequestId++;
      const query = this.editTarget.trim();
      if ( !query ) {
        this.editTargetSuggestions = [];
        return;
      }
      const requestId = this.editTargetRequestId;
      this.editTargetSearchTimeout = setTimeout( () => {
        new mw.Api().get( {
          action: 'query', format: 'json', list: 'allpages', apprefix: query, apnamespace: 0, aplimit: 10
        } ).then( ( data ) => {
          if ( requestId === this.editTargetRequestId ) {
            const pages = ( data.query && data.query.allpages ) || [];
            this.editTargetSuggestions = pages.map( ( page ) => ( { value: page.title, label: page.title } ) );
          }
        } ).catch( () => {
          if ( requestId === this.editTargetRequestId ) {
            this.editTargetSuggestions = [];
          }
        } );
      }, query.length === 1 ? 0 : 150 );
    }
  }
};
