/**
 * RJM Archibuild Chatbot
 * AI-powered customer support chat interface
 */

(function() {
  'use strict';

  // Wait for DOM to be fully loaded
  document.addEventListener('DOMContentLoaded', function() {
    initializeChatbot();
  });

  function initializeChatbot() {
    const chatToggle = document.getElementById('chatToggle');
    const chatbot = document.getElementById('chatbot');
    const closeChat = document.getElementById('closeChat');
    const chatBody = document.getElementById('chatBody');
    const sendBtn = document.getElementById('sendBtn');
    const userInput = document.getElementById('userInput');
    const suggestions = document.getElementById('suggestions');

    // Toggle chatbot visibility
    if (chatToggle) {
      chatToggle.addEventListener('click', () => {
        chatbot.classList.toggle('active');
      });
    }

    // Close chatbot
    if (closeChat) {
      closeChat.addEventListener('click', () => {
        chatbot.classList.remove('active');
      });
    }

    // Add user message to chat
    function addUserMessage(text) {
      const row = document.createElement('div');
      row.className = 'message-row user';
      row.innerHTML = `<div class="message">${text}</div>`;
      chatBody.appendChild(row);
      scrollToBottom();
    }

    // Add bot message to chat
    function addBotMessage(text) {
      const row = document.createElement('div');
      row.className = 'message-row bot';
      row.innerHTML = `
        <div class="bot-icon"></div>
        <div class="message">${text}</div>
      `;
      chatBody.appendChild(row);
      scrollToBottom();
    }

    // Generate bot reply based on user input
    function botReply(userText) {
      const text = userText.toLowerCase();

      if (text.includes('services')) {
        return 'We offer architectural design, construction planning, consultations, renovation & remodeling, and project management depending on your needs.';
      } else if (text.includes('cost') || text.includes('price')) {
        return 'Project cost depends on the design, specifications, and overall scope of the project. We can prepare a detailed estimate once we have the details. Visit our quote page to get started!';
      } else if (text.includes('consultation') || text.includes('book')) {
        return "Yes! You can book a consultation with us. Click 'Schedule Call' at the top or visit our contact page to request an appointment. We're here to help!";
      } else if (text.includes('long') || text.includes('take') || text.includes('duration') || text.includes('timeline')) {
        return 'Project timeline depends on the size and scope of the work. Once we review your requirements, we can provide you with a realistic schedule.';
      } else if (text.includes('project') || text.includes('portfolio')) {
        return "Check out our projects page to see examples of our work. We've completed residential, commercial, and resort projects!";
      } else {
        return 'Thank you for reaching out! Could you share more details about your project so I can assist you better?';
      }
    }

    // Send message
    function sendMessage(text = null) {
      const message = text || userInput.value.trim();
      if (message === '') return;

      addUserMessage(message);

      if (suggestions) {
        suggestions.style.display = 'none';
      }

      if (!text) {
        userInput.value = '';
      }

      setTimeout(() => {
        addBotMessage(botReply(message));
      }, 500);
    }

    // Send button click event
    if (sendBtn) {
      sendBtn.addEventListener('click', () => {
        sendMessage();
      });
    }

    // Enter key to send message
    if (userInput) {
      userInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
          sendMessage();
        }
      });
    }

    // Suggestion button clicks
    document.querySelectorAll('.suggestion-btn').forEach(button => {
      button.addEventListener('click', () => {
        sendMessage(button.textContent);
      });
    });

    // Scroll to bottom of chat
    function scrollToBottom() {
      chatBody.scrollTop = chatBody.scrollHeight;
    }
  }
})();
