(() => {
  const fields = {
    isbn: '#isbn-busca',
    nome: '#livro-nome',
    autor: '#livro-autor',
    editora: '#livro-editora',
    categoria: '#livro-categoria',
    ano_publicacao: '#livro-ano',
    descricao: '#livro-descricao',
    imagem_url: '#livro-imagem',
  };

  function preencherCadastro(book) {
    for (const [key, selector] of Object.entries(fields)) {
      const field = document.querySelector(selector);
      const value = book[key];
      if (field && value !== undefined && value !== null && value !== '') field.value = value;
    }

    const code = document.querySelector('#livro-codigo');
    const isbn = String(book.isbn || '').replace(/[^0-9Xx]/g, '').toUpperCase();
    if (code && (!code.value.trim() || code.dataset.generated === 'true')) {
      if (isbn) {
        code.value = `LIV-${isbn}`;
      } else {
        const slug = String(book.nome || 'LIVRO').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
          .replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toUpperCase().slice(0, 28) || 'LIVRO';
        const suffix = Math.random().toString(36).slice(2, 6).toUpperCase();
        code.value = `${slug}-${suffix}`;
      }
      code.dataset.generated = 'true';
    }
    atualizarPrevia(book.imagem_url || '');
    document.querySelector('#novo-livro')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.querySelector('#preco-livro')?.focus({ preventScroll: true });
  }

  function atualizarPrevia(url) {
    const box = document.querySelector('#livro-capa-preview');
    const image = document.querySelector('#livro-capa-preview-imagem');
    if (!box || !image) return;
    if (!String(url).trim()) {
      box.hidden = true;
      image.removeAttribute('src');
      return;
    }

    try {
      const parsed = new URL(String(url), window.location.href);
      if (!['https:', 'http:'].includes(parsed.protocol)) throw new Error('URL inválida');
      image.onerror = () => { box.hidden = true; image.removeAttribute('src'); };
      image.onload = () => { box.hidden = false; };
      image.src = parsed.href;
    } catch {
      box.hidden = true;
      image.removeAttribute('src');
    }
  }

  const imageInput = document.querySelector('#livro-imagem');
  imageInput?.addEventListener('input', () => atualizarPrevia(imageInput.value));
  document.querySelector('#livro-codigo')?.addEventListener('input', (event) => {
    delete event.currentTarget.dataset.generated;
  });

  const isbnButton = document.querySelector('#buscar-isbn');
  const isbnInput = document.querySelector('#isbn-busca');
  if (isbnButton && isbnInput) {
    isbnButton.addEventListener('click', async () => {
      isbnButton.disabled = true;
      isbnButton.textContent = 'Consultando…';
      try {
        const response = await fetch(`buscar_livro.php?isbn=${encodeURIComponent(isbnInput.value)}`, {
          headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Não foi possível buscar os dados.');
        preencherCadastro(data);
        if (!document.querySelector('#livro-nome')?.value) {
          throw new Error('O resultado não contém título; preencha os campos manualmente.');
        }
        isbnInput.setCustomValidity('');
      } catch (error) {
        window.alert(error.message || 'Falha ao consultar o catálogo.');
      } finally {
        isbnButton.disabled = false;
        isbnButton.textContent = 'Buscar dados';
      }
    });
  }

  const searchForm = document.querySelector('#busca-livros-form');
  const searchInput = document.querySelector('#busca-livros-termo');
  const searchButton = document.querySelector('#buscar-livros-submit');
  const searchStatus = document.querySelector('#busca-livros-status');
  const searchResults = document.querySelector('#busca-livros-resultados');
  if (!searchForm || !searchInput || !searchButton || !searchStatus || !searchResults) return;

  function addText(parent, tag, className, value) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = String(value || '');
    parent.append(element);
    return element;
  }

  function renderBook(book) {
    const card = document.createElement('article');
    card.className = 'livro-busca-item';

    const coverBox = document.createElement('div');
    coverBox.className = 'livro-busca-item__capa';
    if (book.imagem_url) {
      const image = document.createElement('img');
      image.src = book.imagem_url;
      image.alt = `Capa de ${book.nome || 'livro'}`;
      image.loading = 'lazy';
      image.referrerPolicy = 'no-referrer';
      image.onerror = () => { coverBox.textContent = '📚'; image.remove(); };
      coverBox.append(image);
    } else {
      coverBox.textContent = '📚';
    }
    card.append(coverBox);

    const details = document.createElement('div');
    details.className = 'livro-busca-item__dados';
    addText(details, 'h3', '', book.nome || 'Título não informado');
    addText(details, 'p', 'muted', book.autor || 'Autor não informado');
    const metadata = [book.editora, book.ano_publicacao, book.isbn ? `ISBN ${book.isbn}` : ''].filter(Boolean).join(' · ');
    if (metadata) addText(details, 'p', 'muted', metadata);
    if (book.descricao) addText(details, 'p', 'livro-busca-item__descricao', `${book.descricao.slice(0, 220)}${book.descricao.length > 220 ? '…' : ''}`);
    const selectButton = addText(details, 'button', 'botao', 'Usar dados no cadastro');
    selectButton.type = 'button';
    selectButton.addEventListener('click', () => {
      preencherCadastro(book);
      searchStatus.textContent = 'Dados preenchidos. Confira o preço e o estoque e cadastre para publicar no catálogo.';
    });
    card.append(details);
    return card;
  }

  searchForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = searchInput.value.trim();
    if (query.length < 2) {
      searchStatus.textContent = 'Digite pelo menos 2 caracteres para buscar.';
      return;
    }

    searchButton.disabled = true;
    searchButton.textContent = 'Buscando…';
    searchStatus.textContent = 'Consultando o catálogo de livros…';
    searchResults.replaceChildren();
    try {
      const response = await fetch(`buscar_livro.php?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Não foi possível buscar os livros.');
      const books = Array.isArray(data.items) ? data.items : [];
      if (books.length === 0) {
        searchStatus.textContent = 'Nenhum livro encontrado. Tente outro título, autor ou ISBN.';
        return;
      }
      searchStatus.textContent = `${books.length} resultado(s). Escolha um livro para preencher o cadastro.`;
      for (const book of books) searchResults.append(renderBook(book));
    } catch (error) {
      searchStatus.textContent = error.message || 'Falha ao consultar o catálogo externo.';
    } finally {
      searchButton.disabled = false;
      searchButton.textContent = 'Buscar livros';
    }
  });
})();
