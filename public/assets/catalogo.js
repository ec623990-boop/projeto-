(() => {
  const root = document.querySelector('#busca-internet');
  const form = document.querySelector('#busca-internet-form');
  const input = document.querySelector('#busca-internet-termo');
  const button = document.querySelector('#busca-internet-submit');
  const status = document.querySelector('#busca-internet-status');
  const results = document.querySelector('#busca-internet-resultados');
  if (!root || !form || !input || !button || !status || !results) return;

  const csrf = root.dataset.csrf || '';
  const canImport = root.dataset.importacaoAtiva === 'true';

  function addText(parent, tag, className, value) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = String(value || '');
    parent.append(element);
    return element;
  }

  function addHidden(formElement, name, value) {
    const field = document.createElement('input');
    field.type = 'hidden';
    field.name = name;
    field.value = String(value || '');
    formElement.append(field);
  }

  function createCover(book) {
    const box = document.createElement('div');
    box.className = 'livro-busca-item__capa';
    let coverUrl = '';
    try {
      const parsed = new URL(String(book.imagem_url || ''));
      if (parsed.protocol === 'https:' && parsed.hostname === 'covers.openlibrary.org') coverUrl = parsed.href;
    } catch { /* Capa ausente ou endereço inválido. */ }

    if (!coverUrl) {
      box.textContent = '📚';
      return box;
    }

    const image = document.createElement('img');
    image.src = coverUrl;
    image.alt = 'Capa de ' + (book.nome || 'livro');
    image.loading = 'lazy';
    image.referrerPolicy = 'no-referrer';
    image.onerror = () => { box.textContent = '📚'; };
    box.append(image);
    return box;
  }

  function renderBook(book) {
    const card = document.createElement('article');
    card.className = 'livro-busca-item';
    card.append(createCover(book));

    const details = document.createElement('div');
    details.className = 'livro-busca-item__dados';
    addText(details, 'h3', '', book.nome || 'Título não informado');
    addText(details, 'p', 'muted', book.autor || 'Autor não informado');
    const metadata = [book.editora, book.ano_publicacao, book.isbn ? 'ISBN ' + book.isbn : ''].filter(Boolean).join(' · ');
    if (metadata) addText(details, 'p', 'muted', metadata);
    addText(details, 'p', 'livro-busca-item__descricao', book.descricao || 'Sinopse não informada pela fonte para esta edição.');

    if (book.source_url) {
      try {
        const source = new URL(String(book.source_url));
        if (source.protocol === 'https:' && source.hostname === 'openlibrary.org') {
          const link = addText(details, 'a', 'muted', 'Ver ficha na Open Library');
          link.href = source.href;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        }
      } catch { /* Ignora links de origem inválidos. */ }
    }

    if (canImport && /^[a-f0-9]{32}$/.test(String(book.token || ''))) {
      const importForm = document.createElement('form');
      importForm.className = 'catalogo-importar-demo';
      importForm.method = 'post';
      importForm.action = 'adicionar_livro_demo.php';
      addHidden(importForm, 'csrf_token', csrf);
      addHidden(importForm, 'livro_token', book.token);

      const priceLabel = document.createElement('label');
      priceLabel.textContent = 'Preço fictício de teste (R$)';
      const price = document.createElement('input');
      price.type = 'number';
      price.name = 'preco';
      price.min = '0.01';
      price.max = '999999.99';
      price.step = '0.01';
      price.value = '29.90';
      price.required = true;
      priceLabel.append(price);

      const stockLabel = document.createElement('label');
      stockLabel.textContent = 'Estoque fictício';
      const stock = document.createElement('input');
      stock.type = 'number';
      stock.name = 'estoque';
      stock.min = '1';
      stock.max = '1000';
      stock.step = '1';
      stock.value = '5';
      stock.required = true;
      stockLabel.append(stock);

      const submit = addText(importForm, 'button', 'botao', 'Adicionar ao estoque de teste');
      submit.type = 'submit';
      importForm.append(priceLabel, stockLabel);
      details.append(importForm);
    } else {
      addText(details, 'p', 'muted', 'Adicionar ao estoque de teste fica disponível somente no servidor local com a simulação ativada.');
    }

    card.append(details);
    return card;
  }

  async function searchInternet() {
    const query = input.value.trim();
    if (query.length < 2) {
      status.textContent = 'Digite pelo menos 2 caracteres para buscar.';
      return;
    }

    button.disabled = true;
    button.textContent = 'Buscando…';
    status.textContent = 'Consultando livros na internet…';
    results.replaceChildren();
    try {
      const response = await fetch('buscar_livro_internet.php?q=' + encodeURIComponent(query), {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Não foi possível buscar livros na internet.');
      const books = Array.isArray(data.items) ? data.items : [];
      if (books.length === 0) {
        status.textContent = 'A fonte não encontrou esse título. Tente o nome do autor ou um ISBN.';
        return;
      }
      status.textContent = books.length + ' livro(s) encontrado(s). Confira os dados e adicione ao estoque de teste.';
      for (const book of books) results.append(renderBook(book));
    } catch (error) {
      status.textContent = error.message || 'Falha ao consultar o catálogo externo.';
    } finally {
      button.disabled = false;
      button.textContent = 'Buscar na internet';
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    searchInternet();
  });

  if (root.dataset.auto === 'true' && input.value.trim().length >= 2) searchInternet();
})();
