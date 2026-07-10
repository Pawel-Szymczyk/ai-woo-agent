// assets/js/chat.js
// IIFE (Immediately Invoked Function Expression) — prevents global scope pollution.
( function() {
    'use strict';
 
    // Generate unique session ID per browser tab.
    // toString(36) = base-36 encoding (0-9 + a-z). substr(2,9) = 9 char token.
    const sessionId = Math.random().toString(36).substr( 2, 9 );
 
    // Get DOM references once — reuse instead of querying every message.
    const widget   = document.getElementById( 'ai-chat-widget' );
    const messages = document.getElementById( 'ai-chat-messages' );
    const input    = document.getElementById( 'ai-chat-input' );
    const sendBtn  = document.getElementById( 'ai-chat-send' );
 
    // Bail if widget not present on this page (pages without [ai_chat] shortcode).
    if ( ! widget ) return;
 
    // Add message bubble to the chat window.
    // role: 'user' | 'assistant' | 'error' | 'loading'
    // Returns the element so caller can remove it (loading indicator).
    function addMessage( text, role ) {
        const div     = document.createElement( 'div' );
        div.className = 'ai-message ai-message--' + role;
        div.textContent = text; // textContent is safe — no XSS risk unlike innerHTML
        messages.appendChild( div );
        messages.scrollTop = messages.scrollHeight; // auto-scroll to newest
        return div;
    }
 
    // Send message to REST API.
    // async: allows await without blocking browser UI thread.
    async function sendMessage() {
        const text = input.value.trim();
        if ( ! text ) return;
 
        input.value      = '';
        sendBtn.disabled = true; // prevent double-send
        addMessage( text, 'user' );
        const loadingDiv = addMessage( '...', 'loading' );
 
        try {
            // fetch() returns a Promise. await pauses until resolved.
            const response = await fetch( AiChatConfig.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // X-WP-Nonce: WordPress security token.
                    // Prevents CSRF — forged requests from other sites.
                    'X-WP-Nonce': AiChatConfig.nonce,
                },
                body: JSON.stringify( {
                    message:    text,
                    session_id: sessionId,            // server uses this to find history
                    post_id:    AiChatConfig.post_id, // for page context feature
                } ),
            } );
 
            const data = await response.json();
            loadingDiv.remove();
 
            if ( data.reply ) {
                addMessage( data.reply, 'assistant' );
            } else if ( data.error ) {
                addMessage( 'Błąd: ' + data.error, 'error' );
            }
 
        } catch ( networkError ) {
            // Fires on: no internet, server down, CORS error, timeout.
            loadingDiv.remove();
            addMessage( 'Brak połączenia. Sprawdź internet i spróbuj ponownie.', 'error' );
        }
 
        sendBtn.disabled = false;
        input.focus();
    }
 
    sendBtn.addEventListener( 'click', sendMessage );
 
    // Enter = send. Shift+Enter = newline (not send).
    input.addEventListener( 'keydown', function( e ) {
        if ( e.key === 'Enter' && ! e.shiftKey ) {
            e.preventDefault();
            sendMessage();
        }
    } );
 
    // Greeting message on page load.
    addMessage( 'Cześć! W czym mogę Ci pomóc?', 'assistant' );
 
} )();
