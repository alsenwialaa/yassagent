(() => {
  'use strict';

  const STATE_HEADER = 'X-YSAI-Release1-State';
  const CHAT_URL = /(?:ysai|yassin).*(?:chat|message|turn)/i;
  let pendingToken = '';
  let currentState = null;
  const originalFetch = window.fetch.bind(window);

  const decodeState = (encoded) => {
    if (!encoded) return null;
    try {
      const base64 = encoded.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(encoded.length / 4) * 4, '=');
      const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
      return JSON.parse(new TextDecoder().decode(bytes));
    } catch (_) {
      return null;
    }
  };

  const isChatRequest = (input) => {
    const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
    return CHAT_URL.test(url);
  };

  window.fetch = async (input, init = {}) => {
    const requestInit = { ...init };
    if (pendingToken && isChatRequest(input)) {
      const headers = new Headers(requestInit.headers || (input instanceof Request ? input.headers : undefined));
      headers.set('X-YSAI-Interaction', pendingToken);
      requestInit.headers = headers;
      pendingToken = '';
    }
    const response = await originalFetch(input, requestInit);
    if (isChatRequest(input)) {
      const state = decodeState(response.headers.get(STATE_HEADER));
      if (state) {
        currentState = state;
        window.dispatchEvent(new CustomEvent('ysai:release1-state', { detail: state }));
        renderInteractions(state.interactions || []);
      }
    }
    return response;
  };

  const findRoot = () => document.querySelector('[data-ysai-chat], .ysai-chat, #ysai-chat, [class*="ysai"][class*="chat"]');
  const findComposer = (root) => root && root.querySelector('[data-ysai-composer], textarea, input[type="text"]');
  const findSend = (root) => root && root.querySelector('[data-ysai-send], button[type="submit"], button[aria-label*="send" i], button[aria-label*="إرسال"]');

  const submitChoice = (choice) => {
    const root = findRoot();
    const composer = findComposer(root);
    const send = findSend(root);
    if (!root || !composer || !send || !choice.token) return;
    pendingToken = String(choice.token);
    composer.value = String(choice.label || '');
    composer.dispatchEvent(new Event('input', { bubbles: true }));
    composer.dispatchEvent(new Event('change', { bubbles: true }));
    send.click();
  };

  const renderInteractions = (interactions) => {
    const root = findRoot();
    if (!root) return;
    let container = root.querySelector('[data-ysai-release1-controls]');
    if (!container) {
      container = document.createElement('div');
      container.dataset.ysaiRelease1Controls = '1';
      container.className = 'ysai-r1-controls';
      container.setAttribute('role', 'group');
      container.setAttribute('aria-label', document.documentElement.dir === 'rtl' ? 'خيارات المحادثة' : 'Conversation choices');
      root.appendChild(container);
    }
    container.replaceChildren();
    interactions.slice(0, 12).forEach((choice) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'ysai-r1-choice';
      button.textContent = String(choice.label || '');
      button.addEventListener('click', () => submitChoice(choice), { once: true });
      container.appendChild(button);
    });
    container.hidden = interactions.length === 0;
  };

  const style = document.createElement('style');
  style.textContent = `
    .ysai-r1-controls{display:flex;flex-wrap:wrap;gap:.5rem;padding:.5rem 1rem;direction:inherit}
    .ysai-r1-controls[hidden]{display:none}
    .ysai-r1-choice{min-height:2.5rem;padding:.5rem .85rem;border:1px solid currentColor;border-radius:999px;background:transparent;color:inherit;font:inherit;cursor:pointer}
    .ysai-r1-choice:focus-visible{outline:2px solid currentColor;outline-offset:2px}
    @media (prefers-reduced-motion:reduce){.ysai-r1-choice{transition:none}}
  `;
  document.head.appendChild(style);

  window.YSAIRelease1 = Object.freeze({
    getState: () => currentState,
    select: submitChoice,
  });
})();
