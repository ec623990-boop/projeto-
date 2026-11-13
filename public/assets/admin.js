(() => {
  const button = document.querySelector('#buscar-isbn');
  const input = document.querySelector('#isbn-busca');
  if (!button || !input) return;
  const fields = { nome: '#livro-nome', autor: '#livro-autor', editora: '#livro-editora', categoria: '#livro-categoria', ano_publicacao: '#livro-ano', descricao: '#livro-descricao', imagem_url: '#livro-imagem' };
  button.addEventListener('click', async () => {
    button.disabled = true;
    button.textContent = 'Consultando…';
    try {
      const response = await fetch(`buscar_livro.php?isbn=${encodeURIComponent(input.value)}`, { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Não foi possível buscar os dados.');
      for (const [key, selector] of Object.entries(fields)) {
        const field = document.querySelector(selector);
        if (field && data[key] != null && data[key] !== '') field.value = data[key];
      }
      input.value = data.isbn;
      input.setCustomValidity('');
      if (!document.querySelector('#livro-nome').value) throw new Error('O resultado não contém título; preencha os campos manualmente.');
    } catch (error) {
      window.alert(error.message || 'Falha ao consultar o catálogo.');
    } finally {
      button.disabled = false;
      button.textContent = 'Buscar dados';
    }
  });
})();
