(function () {
  'use strict';

  var INJ_ATTR = 'data-in-whatsapp-item';
  var LABEL_RE = /(fatura por e-?mail|email invoice|invoice by email|enviar por e-?mail|envio por e-?mail)/i;

  var MODAL_ID = 'wa-send-modal';
  var openObjectUrls = [];

  function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function getToken() {
    try {
      return localStorage.getItem('X-NINJA-TOKEN');
    } catch (e) {
      return null;
    }
  }

  function apiHeaders(json) {
    var headers = {
      'X-Api-Token': getToken(),
      'X-Requested-With': 'XMLHttpRequest',
      'X-React': 'true',
      'Accept': 'application/json'
    };
    if (json) headers['Content-Type'] = 'application/json';
    return headers;
  }

  function ensureResponsiveStyles() {
    if (document.getElementById('wa-modal-styles')) return;
    var style = document.createElement('style');
    style.id = 'wa-modal-styles';
    style.textContent =
      '#wa-send-modal{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;}' +
      '#wa-send-modal *,#wa-send-modal *::before,#wa-send-modal *::after{box-sizing:border-box;}' +
      '#wa-send-modal .wa-card{position:relative;width:100%;max-width:42rem;max-height:92vh;overflow-y:auto;overscroll-behavior:contain;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);}' +
      '#wa-send-modal .wa-card.wa-card-loading{max-width:24rem;text-align:center;padding:24px;}' +
      '#wa-send-modal input[type=tel],#wa-send-modal textarea{width:100%;max-width:100%;}' +
      '#wa-send-modal .wa-preview-frame{width:100%;height:300px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;display:block;}' +
      '@media (max-width:640px){' +
        '#wa-send-modal{align-items:flex-end;padding:10px;}' +
        '#wa-send-modal .wa-card{max-height:90vh;border-radius:16px 16px 0 0;}' +
        '#wa-send-modal input[type=tel],#wa-send-modal textarea{font-size:16px;}' +
        '#wa-send-modal .wa-preview-frame{height:40vh;min-height:220px;}' +
        '#wa-send-modal .wa-attach-row{flex-wrap:wrap;gap:8px;}' +
        '#wa-send-modal .wa-preview-btn{flex:1 1 100%;justify-content:center;margin-top:2px;}' +
      '}' +
      '@media (max-width:640px) and (max-height:500px){' +
        '#wa-send-modal .wa-preview-frame{height:35vh;}' +
        '#wa-send-modal h2{font-size:16px;}' +
      '}' +
      '@media (min-width:641px) and (max-width:1024px){' +
        '#wa-send-modal .wa-card{max-width:36rem;}' +
        '#wa-send-modal .wa-preview-frame{height:260px;}' +
      '}';
    (document.head || document.documentElement).appendChild(style);
  }

  function extractHash(href) {
    if (!href) return null;
    var m = String(href).match(/\/invoices\/([A-Za-z0-9]+)/);
    return m ? m[1] : null;
  }

  function resolveInvoiceId(item) {
    var m;

    if (item && item.getAttribute) {
      var hash = extractHash(item.getAttribute('href'));
      if (hash) return hash;
    }

    var row = item && item.closest ? item.closest('tr') : null;
    if (row) {
      var link = row.querySelector('a[href*="/invoices/"]');
      var rowHash = link ? extractHash(link.getAttribute('href')) : null;
      if (rowHash) return rowHash;
    }

    m = window.location.pathname.match(/^\/invoices\/([A-Za-z0-9]+)(?:\/.*)?$/);
    if (m) return m[1];

    m = (window.location.hash || '').match(/\/invoices\/([A-Za-z0-9]+)(?:\/.*)?$/);
    return m ? m[1] : null;
  }

  function waIcon(size) {
    var wrapper = document.createElement('div');
    wrapper.innerHTML =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="' + (size || 18) + '" height="' + (size || 18) + '" fill="#25D366">' +
      '<path d="M16.3 5C10.1 5 5 10.1 5 16.3c0 2.1.6 4.2 1.7 6L5 27l4.9-1.6c1.7 1 3.7 1.6 5.7 1.6 6.2 0 11.3-5.1 11.3-11.3S22.5 5 16.3 5zm0 20.7c-1.8 0-3.5-.5-5-1.4l-.4-.2-3 1 .9-2.9-.2-.4c-1-1.6-1.6-3.5-1.6-5.5 0-5.2 4.2-9.4 9.4-9.4 5.2 0 9.4 4.2 9.4 9.4 0 5.2-4.3 9.4-9.5 9.4zm5.2-7c-.3-.1-1.7-.8-1.9-.9-.3-.1-.5-.1-.7.1-.2.3-.7.9-.9 1.1-.2.2-.3.2-.6.1-.3-.1-1.2-.4-2.3-1.4-.8-.8-1.4-1.7-1.5-2-.2-.3 0-.5.1-.6l.4-.5c.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.9-2.1c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.3 5.2 4.6.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.7-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.3z"/>' +
      '</svg>';
    return wrapper.firstChild;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function findLabelNode(root) {
    var all = root.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      var current = all[i];
      if (current.getAttribute && current.getAttribute(INJ_ATTR)) continue;
      var direct = '';
      for (var j = 0; j < current.childNodes.length; j++) {
        var c = current.childNodes[j];
        if (c.nodeType === 3) direct += c.nodeValue;
      }
      if (LABEL_RE.test(normalize(direct))) return current;
    }
    return null;
  }

  function findItemRoot(labelNode) {
    var elNode = labelNode;
    while (elNode && elNode !== document.body) {
      var cls = typeof elNode.className === 'string' ? elNode.className : '';
      if (/items-center/.test(cls) && /text-sm/.test(cls)) return elNode;
      if (elNode.tagName === 'BUTTON' || (elNode.getAttribute && elNode.getAttribute('role') === 'menuitem')) return elNode;
      elNode = elNode.parentElement;
    }
    return labelNode.parentElement;
  }

  function isInjectedSibling(item) {
    var sib = item.nextElementSibling;
    while (sib) {
      if (sib.getAttribute && sib.getAttribute(INJ_ATTR)) return true;
      sib = sib.nextElementSibling;
    }
    return false;
  }

  function buildClone(id, item) {
    var clone = item.cloneNode(false);
    clone.removeAttribute('onclick');
    clone.removeAttribute('href');
    clone.setAttribute(INJ_ATTR, '1');
    clone.appendChild(waIcon(18));

    var label = document.createElement('span');
    label.className = 'text-sm font-medium';
    label.textContent = 'Enviar WhatsApp';
    clone.appendChild(label);

    clone.onclick = function (e) {
      e.preventDefault();
      e.stopPropagation();
      openSendModal(id);
    };

    return clone;
  }

  function closeModal() {
    var modal = document.getElementById(MODAL_ID);
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    openObjectUrls.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) {} });
    openObjectUrls = [];
    document.removeEventListener('keydown', onKeydown);
  }

  function onKeydown(e) {
    if (e.key === 'Escape') closeModal();
  }

  function createAttachmentRow(labelText, sublabel, filename, checked, onPreview) {
    var row = el('label', 'wa-attach-row flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50');
    var left = el('div', 'flex items-center gap-3 min-w-0');
    var check = document.createElement('input');
    check.type = 'checkbox';
    check.checked = !!checked;
    check.className = 'h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500 shrink-0';
    var textWrap = el('div', 'min-w-0');
    var name = el('div', 'text-sm font-medium text-gray-800', labelText);
    var file = el('div', 'text-xs text-gray-500 truncate', filename || sublabel);
    textWrap.appendChild(name);
    textWrap.appendChild(file);
    left.appendChild(check);
    left.appendChild(textWrap);

    var btn = el('button', 'wa-preview-btn shrink-0 inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100');
    btn.type = 'button';
    btn.textContent = 'Visualizar';
    btn.onclick = function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (onPreview) onPreview(check, btn);
    };

    row.appendChild(left);
    row.appendChild(btn);
    return { row: row, check: check, btn: btn };
  }

  function buildModal(options, id) {
    var overlay = el('div');
    overlay.id = MODAL_ID;
    overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 p-4';

    var card = el('div', 'wa-card relative w-full max-w-2xl max-h-[92vh] overflow-y-auto rounded-xl bg-white shadow-2xl');

    // Header
    var header = el('div', 'flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4');
    var titleWrap = el('div');
    var title = el('h2', 'text-lg font-semibold text-gray-900 flex items-center gap-2');
    title.appendChild(waIcon(20));
    title.appendChild(document.createTextNode('Enviar fatura #' + options.invoice.number + ' por WhatsApp'));
    var sub = el('p', 'mt-1 text-sm text-gray-500', 'Cliente: ' + options.client_name);
    titleWrap.appendChild(title);
    titleWrap.appendChild(sub);
    var closeBtn = el('button', 'text-gray-400 hover:text-gray-600 text-2xl leading-none');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Fechar');
    closeBtn.textContent = '\u00D7';
    closeBtn.onclick = closeModal;
    header.appendChild(titleWrap);
    header.appendChild(closeBtn);
    card.appendChild(header);

    var body = el('div', 'px-5 py-4 space-y-5');

    // Recipient
    var phoneGroup = el('div');
    phoneGroup.appendChild(el('label', 'block text-sm font-medium text-gray-700', 'Destinat\u00E1rio (WhatsApp)'));
    var phoneWrap = el('div', 'mt-1');
    var phoneInput = document.createElement('input');
    phoneInput.type = 'tel';
    phoneInput.id = 'wa-phone';
    phoneInput.value = options.phone || '';
    phoneInput.placeholder = '(00) 00000-0000';
    phoneInput.className = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500';
    phoneWrap.appendChild(phoneInput);
    phoneGroup.appendChild(phoneWrap);
    if (!options.phone) {
      phoneGroup.appendChild(el('p', 'mt-1 text-xs text-amber-600', 'Nenhum telefone cadastrado no cliente. Informe um n\u00FAmero com DDD para envio.'));
    }
    body.appendChild(phoneGroup);

    // Message
    var msgGroup = el('div');
    msgGroup.appendChild(el('label', 'block text-sm font-medium text-gray-700', 'Mensagem'));
    var msgTextarea = document.createElement('textarea');
    msgTextarea.id = 'wa-message';
    msgTextarea.rows = 8;
    msgTextarea.value = options.message || '';
    msgTextarea.className = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500';
    msgGroup.appendChild(msgTextarea);
    body.appendChild(msgGroup);

    // Attachments
    var attGroup = el('div');
    attGroup.appendChild(el('label', 'block text-sm font-medium text-gray-700', 'PDFs para enviar'));
    attGroup.appendChild(el('p', 'mt-1 text-xs text-gray-500', 'Selecione quais documentos ser\u00E3o anexados, clique em "Visualizar" para conferir ou envie somente a mensagem.'));

    var rowsWrap = el('div', 'mt-2 space-y-2');

    var pdfRow = createAttachmentRow('Fatura (PDF)', 'PDF gerado pelo sistema', options.pdf ? options.pdf.filename : '', options.defaults.send_pdf, function (check, btn) {
      togglePreview('wa-pdf-preview', options.pdf, check, btn);
    });
    rowsWrap.appendChild(pdfRow.row);
    pdfRow.check.id = 'wa-send-pdf';

    if (options.boleto) {
      var boletoRow = createAttachmentRow('Boleto (Asaas)', 'Boleto banc\u00E1rio da fatura', options.boleto.filename, options.defaults.send_boleto, function (check, btn) {
        togglePreview('wa-boleto-preview', options.boleto, check, btn);
      });
      rowsWrap.appendChild(boletoRow.row);
      boletoRow.check.id = 'wa-send-boleto';
    }

    attGroup.appendChild(rowsWrap);

    var pdfPreview = el('div', 'mt-2 rounded-md overflow-hidden');
    pdfPreview.id = 'wa-pdf-preview';
    pdfPreview.style.display = 'none';
    attGroup.appendChild(pdfPreview);

    if (options.boleto) {
      var boletoPreview = el('div', 'mt-2 rounded-md overflow-hidden');
      boletoPreview.id = 'wa-boleto-preview';
      boletoPreview.style.display = 'none';
      attGroup.appendChild(boletoPreview);
    }

    body.appendChild(attGroup);

    // Status
    var status = el('div', 'hidden rounded-md bg-gray-50 px-3 py-2 text-sm');
    status.id = 'wa-status';
    body.appendChild(status);

    card.appendChild(body);

    // Footer
    var footer = el('div', 'flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-4');
    var cancelBtn = el('button', 'rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancelar';
    cancelBtn.onclick = closeModal;

    var sendBtn = el('button', 'inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50');
    sendBtn.type = 'button';
    sendBtn.id = 'wa-send-btn';
    sendBtn.appendChild(waIcon(16));
    sendBtn.appendChild(document.createTextNode('Enviar'));
    sendBtn.onclick = function () {
      sendViaWhatsApp(id, phoneInput, msgTextarea, pdfRow.check, boletoRow && boletoRow.check, status, sendBtn);
    };

    footer.appendChild(cancelBtn);
    footer.appendChild(sendBtn);
    card.appendChild(footer);

    overlay.appendChild(card);
    (document.body || document.documentElement).appendChild(overlay);

    document.addEventListener('keydown', onKeydown);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });

    if (pdfRow.check.checked) {
      var btn = pdfRow.btn;
      btn.click();
    }
    if (boletoRow && boletoRow.check.checked) {
      var bBtn = boletoRow.btn;
      bBtn.click();
    }
  }

  function togglePreview(containerId, file, check, btn) {
    var container = document.getElementById(containerId);
    if (!container || !file) return;

    if (container.getAttribute('data-loaded')) {
      if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.textContent = 'Ocultar';
      } else {
        container.style.display = 'none';
        btn.textContent = 'Visualizar';
      }
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Carregando...';

    fetch(file.url, { method: 'GET', credentials: 'same-origin', headers: apiHeaders(false) })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.blob();
      })
      .then(function (blob) {
        var objectUrl = URL.createObjectURL(blob);
        openObjectUrls.push(objectUrl);
        container.innerHTML = '';
        var iframe = document.createElement('iframe');
        iframe.src = objectUrl;
        iframe.className = 'wa-preview-frame';
        container.appendChild(iframe);
        container.setAttribute('data-loaded', '1');
        container.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Ocultar';
      })
      .catch(function () {
        container.innerHTML = '';
        var err = el('p', 'rounded-md bg-red-50 px-3 py-2 text-xs text-red-600', 'N\u00E3o foi poss\u00EDvel carregar a pr\u00E9via do PDF.');
        container.appendChild(err);
        container.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Tentar novamente';
      });
  }

  function setStatus(elNode, text, ok) {
    elNode.textContent = text;
    elNode.className = 'rounded-md px-3 py-2 text-sm ' + (ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
    elNode.style.display = 'block';
  }

  function sendViaWhatsApp(id, phoneInput, message, pdfCheck, boletoCheck, status, sendBtn) {
    var phone = normalize(phoneInput && phoneInput.value);
    if (!phone) {
      setStatus(status, 'Informe um n\u00FAmero de WhatsApp v\u00E1lido para o destinat\u00E1rio.', false);
      return;
    }

    var payload = {
      phone: phone,
      message: message ? message.value : '',
      send_pdf: !!(pdfCheck && pdfCheck.checked),
      send_boleto: !!(boletoCheck && boletoCheck.checked)
    };

    sendBtn.disabled = true;
    setStatus(status, 'Enviando...', true);

    fetch('/api/v1/invoices/' + id + '/whatsapp', {
      method: 'POST',
      credentials: 'same-origin',
      headers: apiHeaders(true),
      body: JSON.stringify(payload)
    })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
          return { ok: r.ok, status: r.status, data: data };
        });
      })
      .then(function (res) {
        if (res.ok && res.data && res.data.success !== false) {
          setStatus(status, res.data.message || 'Enviado com sucesso via WhatsApp.', true);
          sendBtn.disabled = true;
          sendBtn.textContent = 'Enviado';
          setTimeout(closeModal, 2000);
        } else {
          sendBtn.disabled = false;
          setStatus(status, (res.data && (res.data.error || res.data.message)) || ('Erro ao enviar (HTTP ' + res.status + ').'), false);
        }
      })
      .catch(function (err) {
        sendBtn.disabled = false;
        setStatus(status, 'Erro de rede: ' + err.message, false);
      });
  }

  function openSendModal(id) {
    closeModal();

    var token = getToken();
    if (!token) {
      window.alert('Sess\u00E3o n\u00E3o encontrada. Fa\u00E7a login novamente.');
      return;
    }

    var overlay = el('div');
    overlay.id = MODAL_ID;
    overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 p-4';
    var card = el('div', 'wa-card wa-card-loading w-full max-w-md rounded-xl bg-white p-6 shadow-2xl text-center');
    var spinner = el('div', 'mx-auto h-8 w-8 animate-spin rounded-full border-2 border-gray-200 border-t-green-600');
    card.appendChild(spinner);
    card.appendChild(el('p', 'mt-3 text-sm text-gray-600', 'Carregando op\u00E7\u00F5es de envio...'));
    overlay.appendChild(card);
    (document.body || document.documentElement).appendChild(overlay);

    fetch('/api/v1/invoices/' + id + '/whatsapp/options', {
      method: 'GET',
      credentials: 'same-origin',
      headers: apiHeaders(false)
    })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
          return { ok: r.ok, status: r.status, data: data };
        });
      })
      .then(function (res) {
        if (!res.ok || !res.data) {
          throw new Error((res.data && (res.data.error || res.data.message)) || ('HTTP ' + res.status));
        }
        closeModal();
        if (!res.data.is_configured) {
          window.alert(res.data.configured_message || 'WhatsApp n\u00E3o configurado.');
          return;
        }
        buildModal(res.data, id);
      })
      .catch(function (err) {
        closeModal();
        window.alert('Erro ao carregar op\u00E7\u00F5es: ' + err.message);
      });
  }

  function scan() {
    var labelNode = findLabelNode(document);
    if (!labelNode) return;

    var item = findItemRoot(labelNode);
    if (!item || !item.parentNode) return;

    if (isInjectedSibling(item)) return;

    var id = resolveInvoiceId(item);
    if (!id) return;

    item.parentNode.insertBefore(buildClone(id, item), item.nextSibling);
  }

  var scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(function () { scheduled = false; scan(); }, 250);
  }

  new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
  setInterval(scan, 2000);
  window.addEventListener('popstate', schedule);
  window.addEventListener('hashchange', schedule);

  ensureResponsiveStyles();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
})();