$(document).ready(function () {
  var chatContainer = $('<div>').addClass('chat-container');
  var chatBox = $('<div>').addClass('chat-box');
  chatBox.hide();

  var instructionScreen = $('<div>').addClass('chat-instructions').html(`
      <h2>${ mw.message( "wanda-chat-welcometext" ).text() }</h2>
      <p>${ mw.message( "wanda-chat-welcomedesc" ).text() }</p>
      <ul>
          <li>💬 ${ mw.message( "wanda-chat-instruction1" ).text() }</li>
          <li>🤖 ${ mw.message( "wanda-chat-instruction2" ).text() }</li>
      </ul>
  `);

  var progressBar = new OO.ui.ProgressBarWidget({
      progress: false
  }).$element.addClass('chat-progress-bar').hide();

  var chatInput = new OO.ui.MultilineTextInputWidget({
      placeholder: 'Type your message...',
      classes: [ 'chat-input-box' ],
      autosize: true
  });
  var sendButton = new OO.ui.ButtonWidget({
      flags: [ 'primary', 'progressive' ],
      label: "Submit"
  });

  var inputContainer = $('<div>').addClass('chat-input-container');
  inputContainer.append(chatInput.$element).append(sendButton.$element);

  chatContainer.append(chatBox).append(instructionScreen).append(progressBar).append(inputContainer);
  $('#chat-bot-container').append(chatContainer);

  function addMessage(role, text) {
      instructionScreen.hide();
      var msgWrapper = $('<div>').addClass('chat-message-wrapper ' + role + '-wrapper');
      var msgBubble = $('<div>').addClass('chat-message ' + role + '-message').html(text);

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
      var userText = chatInput.getValue().trim();
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
              var response = data.response || 'Error fetching response';
              response = response + "<br><b>Source</b>: " + data.source;
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

  sendButton.on('click', sendMessage);

  chatInput.$element.on('keydown', function (e) {
      if (e.which === 13 && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
      }
  });

  if (chatBox.children().length === 0) {
      instructionScreen.show();
  }
});
