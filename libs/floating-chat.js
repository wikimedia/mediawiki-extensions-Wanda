$(document).ready(() => {
  // Don't load floating chat on the special page itself
  if (mw.config.get('wgCanonicalSpecialPageName') === 'Wanda' || !mw.config.get('WandaShowPopup')) {
    return;
  }
  // Create floating chat button
  const floatingButton = $('<div>')
    .addClass('wanda-floating-button')
    .html('💬')
    .attr('title', mw.message( "wanda-floating-chat-title" ).text());

  // Create floating chat window
  const floatingWindow = $('<div>')
    .addClass('wanda-floating-window')
    .hide();

  // Create chat header
  const chatHeader = $('<div>')
    .addClass('wanda-chat-header')
    .html('<span>Wanda AI Assistant</span><button class="wanda-close-btn">×</button>');

  // Create chat container (reuse existing chat functionality)
  const chatContainer = $('<div>').addClass('chat-container wanda-floating-chat');
  const chatBox = $('<div>').addClass('chat-box');

  const instructionScreen = $('<div>').addClass('chat-instructions').html(`
      <h2>${ mw.message( "wanda-chat-welcometext" ).text() }</h2>
      <p>${ mw.message( "wanda-chat-welcomedesc" ).text() }</p>
      <ul>
          <li>💬 ${ mw.message( "wanda-chat-instruction1" ).text() }</li>
          <li>🤖 ${ mw.message( "wanda-chat-instruction2" ).text() }</li>
      </ul>
  `);

  const progressBar = new OO.ui.ProgressBarWidget({
      progress: false
  }).$element.addClass('chat-progress-bar').hide();

  const chatInput = new OO.ui.MultilineTextInputWidget({
      placeholder: 'Type your message...',
      classes: [ 'chat-input-box' ],
      autosize: true,
      rows: 2
  });
  const sendButton = new OO.ui.ButtonWidget({
      flags: [ 'primary', 'progressive' ],
      label: "Send"
  });

  const inputContainer = $('<div>').addClass('chat-input-container');
  inputContainer.append(chatInput.$element).append(sendButton.$element);

  chatContainer.append(chatBox).append(instructionScreen).append(progressBar).append(inputContainer);
  floatingWindow.append(chatHeader).append(chatContainer);

  // Add to body
  $('body').append(floatingButton).append(floatingWindow);

  // Ensure input container is always visible and properly styled
  inputContainer.css({
    'display': 'flex',
    'visibility': 'visible',
    'opacity': '1'
  });

  // Chat functionality (same as original)
  function addMessage(role, text) {
      instructionScreen.hide();
      chatBox.show(); // Ensure chat box is visible when adding messages
      const msgWrapper = $('<div>').addClass('chat-message-wrapper ' + role + '-wrapper');
      const msgBubble = $('<div>').addClass('chat-message ' + role + '-message').html(text);

      msgWrapper.append(msgBubble);
      chatBox.append(msgWrapper);
      chatBox.scrollTop(chatBox[0].scrollHeight);
  }

  function toggleProgressBar(show) {
      if (show) {
          progressBar.show();
      } else {
          progressBar.hide();
      }
  }

  function sendMessage() {
      const userText = chatInput.getValue().trim();
      if (!userText) return;
      chatBox.show();

      addMessage('user', userText);
      chatInput.setValue('');
      toggleProgressBar(true);

      $.ajax({
          url: mw.util.wikiScript('api'),
          data: {
              action: 'chatbot',
              format: 'json',
              message: userText
          },
          dataType: 'json',
          success: function (data) {
              let response = data.response || 'Error fetching response';
              if (response === "NO_MATCHING_CONTEXT") {
                response = mw.message( "wanda-llm-response-nocontext" );
              } else {
                if (data.source) {
                  response += "<br><b>Source</b>: <a target='_blank' href='" +
                    mw.util.getUrl(data.source) + "'>" + data.source + "</a>";
                }
              }
              addMessage('bot', response);
          },
          error: function () {
              addMessage('bot', 'Error connecting to chatbot API.');
          },
          complete: function () {
              toggleProgressBar(false);
          }
      });
  }

  // Event handlers
  floatingButton.on('click', () => {
    floatingWindow.show();
    // Add show class after a small delay to trigger animation
    setTimeout(() => {
      floatingWindow.addClass('show');
    }, 10);
    floatingButton.hide();
    // Focus on input if window is opened
    setTimeout(() => {
      chatInput.focus();
    }, 100);
  });

  chatHeader.find('.wanda-close-btn').on('click', () => {
    floatingWindow.removeClass('show');
    setTimeout(() => {
      floatingWindow.hide();
      floatingButton.show();
    }, 300);
  });

  sendButton.on('click', sendMessage);

  chatInput.$element.on('keydown', (e) => {
      if (e.which === 13 && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
      }
  });

  // Show instructions initially
  if (chatBox.children().length === 0) {
      instructionScreen.show();
  }

  // Click outside to close
  $(document).on('click', (e) => {
    if (!$(e.target).closest('.wanda-floating-window, .wanda-floating-button').length) {
      if (floatingWindow.is(':visible')) {
        floatingWindow.removeClass('show');
        setTimeout(() => {
          floatingWindow.hide();
          floatingButton.show();
        }, 300);
      }
    }
  });
});
