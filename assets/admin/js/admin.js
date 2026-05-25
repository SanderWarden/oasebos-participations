document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-oasebos-project-form]');
  if (!form) return;

  const field = (name) => form.querySelector(`[name="${name}"]`);
  const name = field('name');
  const slug = field('slug');
  const total = field('total_hectares');
  const available = field('available_hectares');
  const unit = field('unit_size');
  const price = field('price_per_unit');
  const currency = field('currency');
  const bar = form.querySelector('[data-oasebos-availability-bar]');
  const availabilityText = form.querySelector('[data-oasebos-availability-text]');
  const unitsText = form.querySelector('[data-oasebos-units]');
  const priceHectareText = form.querySelector('[data-oasebos-price-hectare]');
  let slugTouched = Boolean(slug && slug.value.trim());

  const toNumber = (input) => {
    const value = parseFloat((input && input.value ? input.value : '0').replace(',', '.'));
    return Number.isFinite(value) ? value : 0;
  };

  const slugify = (value) => value
    .toString()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  const money = (amount) => {
    const code = currency && currency.value ? currency.value.toUpperCase() : 'EUR';
    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'nl-NL', { style: 'currency', currency: code }).format(amount);
    } catch (error) {
      return `${code} ${amount.toFixed(2)}`;
    }
  };

  const updatePreview = () => {
    const totalValue = toNumber(total);
    const availableValue = toNumber(available);
    const unitValue = toNumber(unit);
    const priceValue = toNumber(price);
    const percentage = totalValue > 0 ? Math.max(0, Math.min(100, (availableValue / totalValue) * 100)) : 0;

    if (bar) bar.style.width = `${percentage}%`;
    if (availabilityText) {
      availabilityText.textContent = totalValue > 0
        ? `${percentage.toFixed(0)}% available (${availableValue.toLocaleString()} of ${totalValue.toLocaleString()} hectares).`
        : 'Enter total and available hectares to preview availability.';
    }
    if (unitsText) unitsText.textContent = unitValue > 0 ? Math.floor(availableValue / unitValue).toLocaleString() : '—';
    if (priceHectareText) priceHectareText.textContent = unitValue > 0 ? money(priceValue / unitValue) : '—';
  };

  if (slug) slug.addEventListener('input', () => { slugTouched = true; slug.value = slugify(slug.value); });
  if (name && slug) {
    name.addEventListener('input', () => {
      if (!slugTouched) slug.value = slugify(name.value);
    });
  }

  [total, available, unit, price, currency].forEach((input) => {
    if (input) input.addEventListener('input', updatePreview);
  });

  updatePreview();
});

document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-oasebos-template-form]');
  if (!form) return;

  const field = (name) => form.querySelector(`[name="${name}"]`);
  const type = field('type');
  const content = field('content');
  const css = field('css');
  const previewContent = form.querySelector('[data-oasebos-template-preview-content]');
  const previewStyle = form.querySelector('[data-oasebos-template-preview-style]');
  const previewTitle = form.querySelector('[data-oasebos-template-preview-title]');
  const previewShell = form.querySelector('[data-oasebos-template-preview-shell]');
  const status = form.querySelector('[data-oasebos-template-preview-status]');
  const name = field('name');
  const resetAgreementTemplate = form.querySelector('[data-oasebos-reset-agreement-template]');
  const resetCertificateTemplate = form.querySelector('[data-oasebos-reset-certificate-template]');
  let timer = null;
  let controller = null;
  let lastType = type ? type.value : '';
  let templateTouched = Boolean(content && content.value.trim());
  let previewHtml = previewContent ? previewContent.innerHTML : '';

  const setStatus = (message, isError = false) => {
    if (!status) return;
    status.textContent = message || '';
    status.classList.toggle('is-error', Boolean(isError));
  };

  const updatePreview = () => {
    const activePreviewContent = form.querySelector('[data-oasebos-template-preview-content]');
    if (!activePreviewContent || !content || !css || !type || typeof oasebosAdmin === 'undefined') return;
    if (controller) controller.abort();
    controller = new AbortController();
    setStatus('Preview laden…');

    const body = new URLSearchParams();
    body.append('action', 'oasebos_template_preview');
    body.append('nonce', oasebosAdmin.nonce || '');
    body.append('type', type.value || 'agreement');
    body.append('content', content.value || '');
    body.append('css', css.value || '');

    fetch(oasebosAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body,
      signal: controller.signal,
    })
      .then((response) => response.json())
      .then((response) => {
        if (!response || !response.success) {
          throw new Error(response && response.data && response.data.message ? response.data.message : oasebosAdmin.previewError);
        }
        previewHtml = response.data.html || '';
        activePreviewContent.innerHTML = previewHtml;
        if (previewStyle) previewStyle.textContent = response.data.css || '';
        if (previewTitle) previewTitle.textContent = response.data.title || 'Preview';
        paginateAgreementPreview();
        setStatus('Preview bijgewerkt.');
      })
      .catch((error) => {
        if (error.name === 'AbortError') return;
        setStatus(error.message || oasebosAdmin.previewError || 'Preview kon niet worden geladen.', true);
      });
  };

  const createPreviewPage = (pageNumber, titleText) => {
    const page = document.createElement('div');
    page.className = 'oasebos-pdf-preview-page';
    page.setAttribute('data-oasebos-template-preview-page', '');

    const titleElement = document.createElement('div');
    titleElement.className = 'oasebos-pdf-preview-title';
    titleElement.textContent = titleText || 'Overeenkomst preview';
    page.appendChild(titleElement);

    const contentElement = document.createElement('div');
    contentElement.className = 'oasebos-pdf-preview-content';
    if (1 === pageNumber) contentElement.setAttribute('data-oasebos-template-preview-content', '');
    page.appendChild(contentElement);

    const agreementElement = document.createElement('div');
    agreementElement.className = 'agreement-template';
    contentElement.appendChild(agreementElement);

    const numberElement = document.createElement('div');
    numberElement.className = 'oasebos-pdf-preview-page-number';
    numberElement.textContent = `Pagina ${pageNumber}`;
    page.appendChild(numberElement);

    return { page, contentElement, agreementElement };
  };

  const elementForPageBreak = (element) => {
    if (!element || element.nodeType !== Node.ELEMENT_NODE) return false;
    return element.classList.contains('oasebos-page-break')
      || element.classList.contains('page-break')
      || element.classList.contains('agreement-page-break')
      || element.getAttribute('data-page-break') === 'before';
  };

  const paginateAgreementPreview = () => {
    const activePreviewContent = form.querySelector('[data-oasebos-template-preview-content]');
    const activePreviewTitle = form.querySelector('[data-oasebos-template-preview-title]');
    if (!previewShell || !activePreviewContent || !type) return;

    if ('agreement' !== type.value) {
      previewShell.classList.remove('is-agreement-paginated');
      if (!previewShell.contains(activePreviewContent) || previewShell.querySelectorAll('.oasebos-pdf-preview-page').length !== 1) {
        previewShell.innerHTML = '';
        const page = document.createElement('div');
        page.className = 'oasebos-pdf-preview-page';
        page.setAttribute('data-oasebos-template-preview-page', '');
        const titleElement = activePreviewTitle || document.createElement('div');
        titleElement.className = 'oasebos-pdf-preview-title';
        titleElement.setAttribute('data-oasebos-template-preview-title', '');
        titleElement.textContent = titleElement.textContent || 'Preview';
        page.appendChild(titleElement);
        activePreviewContent.innerHTML = previewHtml;
        page.appendChild(activePreviewContent);
        previewShell.appendChild(page);
      }
      return;
    }

    previewShell.classList.add('is-agreement-paginated');

    const source = document.createElement('div');
    source.innerHTML = previewHtml;
    const sourceAgreement = source.querySelector('.agreement-template');
    const paginatedNodes = sourceAgreement ? Array.from(sourceAgreement.childNodes) : Array.from(source.childNodes);
    const titleText = activePreviewTitle ? activePreviewTitle.textContent : 'Overeenkomst preview';
    previewShell.innerHTML = '';

    const createPage = () => {
      const pageNumber = previewShell.querySelectorAll('.oasebos-pdf-preview-page:not(.is-measuring)').length + 1;
      const page = createPreviewPage(pageNumber, titleText);
      previewShell.appendChild(page.page);
      return page;
    };

    let current = createPage();
    paginatedNodes.forEach((node) => {
      if (elementForPageBreak(node) && current.agreementElement.childNodes.length > 0) {
        current = createPage();
        return;
      }

      current.agreementElement.appendChild(node.cloneNode(true));
      if (current.contentElement.scrollHeight > current.contentElement.clientHeight && current.agreementElement.childNodes.length > 1) {
        current.agreementElement.removeChild(current.agreementElement.lastChild);
        current = createPage();
        current.agreementElement.appendChild(node.cloneNode(true));
      }
    });

    const firstContent = previewShell.querySelector('[data-oasebos-template-preview-content]');
    if (firstContent && firstContent !== activePreviewContent) {
      activePreviewContent.innerHTML = firstContent.innerHTML;
    }
  };

  const schedulePreview = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(updatePreview, 350);
  };

  const escapeAttribute = (value) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  const dispatchTemplateInputs = () => {
    [type, content, css].forEach((input) => {
      if (input) input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  };

  const applyStandardTemplate = (templateType, force = false) => {
    if (!type || !content || !css) return;
    if (!force && templateTouched) return;

    templateTouched = false;

    if ('agreement' === templateType) {
      type.value = 'agreement';
      content.value = form.getAttribute('data-oasebos-default-agreement-content') || '';
      css.value = form.getAttribute('data-oasebos-default-agreement-css') || '';
      if (name && (!name.value.trim() || 'Standaard certificaat' === name.value.trim())) name.value = 'Standaard participatieovereenkomst';
    } else if ('certificate' === templateType) {
      type.value = 'certificate';
      content.value = form.getAttribute('data-oasebos-default-certificate-content') || '';
      css.value = form.getAttribute('data-oasebos-default-certificate-css') || '';
      if (name && (!name.value.trim() || 'Standaard participatieovereenkomst' === name.value.trim())) name.value = 'Standaard certificaat';
    } else {
      return;
    }

    lastType = templateType;
    dispatchTemplateInputs();
  };

  const imageTargets = {
    signature: {
      pattern: /<div class="signature-placeholder(?: has-image)?">\s*(?:<img[^>]*>|[\s\S]*?)\s*<\/div>/,
      html: (url) => `<div class="signature-placeholder has-image"><img src="${escapeAttribute(url)}" alt="Handtekening" width="272" height="72"></div>`,
    },
  };

  const createFragmentElement = (html) => {
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    return template.content.firstElementChild;
  };

  const insertQualityMark = (html, key, replacement) => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(`<div data-oasebos-template-root>${html}</div>`, 'text/html');
    const root = doc.body.querySelector('[data-oasebos-template-root]');
    if (!root) return html;

    root.querySelectorAll('.quality-marks-table').forEach((table) => table.remove());
    root.querySelectorAll(`.mark-placeholder.${key}`).forEach((mark) => mark.remove());

    const certificateCard = root.querySelector('.certificate-card');
    let qualityMarks = root.querySelector('.quality-marks');
    if (!qualityMarks) {
      qualityMarks = doc.createElement('div');
      qualityMarks.className = 'quality-marks';
      (certificateCard || root).appendChild(qualityMarks);
    } else if (certificateCard && qualityMarks.parentElement !== certificateCard) {
      certificateCard.appendChild(qualityMarks);
    }

    const replacementElement = createFragmentElement(replacement);
    if (!replacementElement) return html;

    if ('anbi' === key) {
      qualityMarks.insertBefore(replacementElement, qualityMarks.firstChild);
    } else {
      qualityMarks.appendChild(replacementElement);
    }

    return root.innerHTML.trim();
  };

  const applyTemplateImage = (key, url) => {
    if (!content || !url || !imageTargets[key]) return;
    const target = imageTargets[key];
    const replacement = target.html(url.trim());
    content.value = target.pattern.test(content.value)
      ? content.value.replace(target.pattern, replacement)
      : `${content.value.trim()}\n${replacement}`;
    content.dispatchEvent(new Event('input', { bubbles: true }));
  };

  if (content) {
    content.addEventListener('input', () => {
      templateTouched = true;
    });
  }

  if (type) {
    type.addEventListener('change', () => {
      const nextType = type.value || '';
      if (nextType !== lastType && ('agreement' === nextType || 'certificate' === nextType)) {
        applyStandardTemplate(nextType, true);
      }
      lastType = nextType;
    });
  }

  if (resetAgreementTemplate) {
    resetAgreementTemplate.addEventListener('click', () => {
      if (!window.confirm('Weet je zeker dat je de inhoud en CSS wilt resetten naar het basis overeenkomsttemplate?')) return;
      applyStandardTemplate('agreement', true);
    });
  }

  if (resetCertificateTemplate) {
    resetCertificateTemplate.addEventListener('click', () => {
      if (!window.confirm('Weet je zeker dat je de inhoud en CSS wilt resetten naar het basis certificaattemplate?')) return;
      applyStandardTemplate('certificate', true);
    });
  }

  form.querySelectorAll('[data-oasebos-template-image-pick]').forEach((button) => {
    button.addEventListener('click', () => {
      const key = button.getAttribute('data-oasebos-template-image-pick');
      const input = form.querySelector(`[data-oasebos-template-image-url="${key}"]`);
      if (typeof wp === 'undefined' || !wp.media || !input) return;
      const frame = wp.media({ title: 'Afbeelding kiezen', button: { text: 'Gebruik deze afbeelding' }, multiple: false });
      frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        input.value = attachment.url || '';
        applyTemplateImage(key, input.value);
      });
      frame.open();
    });
  });

  form.querySelectorAll('[data-oasebos-template-image-apply]').forEach((button) => {
    button.addEventListener('click', () => {
      const key = button.getAttribute('data-oasebos-template-image-apply');
      const input = form.querySelector(`[data-oasebos-template-image-url="${key}"]`);
      if (input) applyTemplateImage(key, input.value);
    });
  });

  [type, content, css].forEach((input) => {
    if (input) input.addEventListener('input', schedulePreview);
    if (input) input.addEventListener('change', schedulePreview);
  });

  schedulePreview();
});
