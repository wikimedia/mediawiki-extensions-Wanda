<template>
  <h2 :aria-label="title">
    <span aria-hidden="true">{{ titleShown }}</span><span
      v-if="activeLine === 'title'"
      class="wanda-typewriter-caret"
      aria-hidden="true"
    ></span>
  </h2>
  <p :aria-label="description">
    <span aria-hidden="true">{{ descriptionShown }}</span><span
      v-if="activeLine === 'description'"
      class="wanda-typewriter-caret"
      aria-hidden="true"
    ></span>
  </p>
</template>

<script>
const { ref, onMounted, onBeforeUnmount } = require( 'vue' );

module.exports = exports = {
  name: 'WelcomeTypewriter',
  props: {
    title: { type: String, required: true },
    description: { type: String, required: true },
    typeDelay: { type: Number, default: 60 },
    lineDelay: { type: Number, default: 400 }
  },
  setup( props ) {
    const titleShown = ref( '' );
    const descriptionShown = ref( '' );
    const activeLine = ref( 'title' );
    let timer = null;

    function typeLine( target, text, line, onDone ) {
      activeLine.value = line;
      let index = 0;
      const tick = () => {
        index++;
        target.value = text.slice( 0, index );
        timer = setTimeout( index < text.length ? tick : onDone, index < text.length ? props.typeDelay : props.lineDelay );
      };
      tick();
    }

    function run() {
      titleShown.value = '';
      descriptionShown.value = '';
      typeLine( titleShown, props.title, 'title', () => {
        typeLine( descriptionShown, props.description, 'description', () => {
          activeLine.value = '';
        } );
      } );
    }

    onMounted( () => {
      if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
        titleShown.value = props.title;
        descriptionShown.value = props.description;
        activeLine.value = '';
        return;
      }
      run();
    } );
    onBeforeUnmount( () => clearTimeout( timer ) );

    return { titleShown, descriptionShown, activeLine };
  }
};
</script>
