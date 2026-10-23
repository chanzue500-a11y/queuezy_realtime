(() => {
  const table = document.getElementById('monitorTable') || document.getElementById('ratingsTable');
  if (!table) return;
  const isRatings = table.id === 'ratingsTable';
  const prefix = isRatings ? 'ratings' : 'queue';
  const label = isRatings ? 'ratings' : 'tickets';
  const pageSize = 10;
  const rows = Array.from(table.querySelectorAll(isRatings ? '.rating-row' : '.queue-row'));
  const searchableRows = rows.map((row) => ({ row, text: row.textContent.toLowerCase() }));
  const search = document.getElementById(isRatings ? 'ratingSearch' : 'queueSearchInput');
  const status = document.getElementById(`${prefix}PageStatus`);
  const previous = document.getElementById(`${prefix}PreviousPage`);
  const next = document.getElementById(`${prefix}NextPage`);
  const numbers = document.getElementById(`${prefix}PageNumbers`);
  const empty = document.getElementById(`${prefix}SearchEmpty`);
  let page = 1;

  const render = () => {
    const query = search.value.trim().toLowerCase();
    const matches = searchableRows.filter((item) => item.text.includes(query));
    const totalPages = Math.max(1, Math.ceil(matches.length / pageSize));
    page = Math.min(Math.max(1, page), totalPages);
    const start = (page - 1) * pageSize;
    rows.forEach((row) => { row.hidden = true; });
    matches.slice(start, start + pageSize).forEach(({ row }) => { row.hidden = false; });
    empty.hidden = matches.length > 0;
    status.textContent = matches.length
      ? `Showing ${start + 1}–${Math.min(start + pageSize, matches.length)} of ${matches.length} ${label} · Page ${page} of ${totalPages}`
      : `Showing 0 ${label}`;
    previous.disabled = page === 1;
    next.disabled = page === totalPages || matches.length === 0;
    numbers.replaceChildren();
    if (matches.length === 0) return;
    const pages = totalPages <= 5
      ? Array.from({ length: totalPages }, (_, index) => index + 1)
      : [...new Set([1, page - 1, page, page + 1, totalPages])].filter((value) => value >= 1 && value <= totalPages).sort((a, b) => a - b);
    let last = 0;
    pages.forEach((value) => {
      if (last && value - last > 1) {
        const gap = document.createElement('span');
        gap.textContent = '…';
        gap.setAttribute('aria-hidden', 'true');
        numbers.appendChild(gap);
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = String(value);
      button.dataset.page = String(value);
      button.setAttribute('aria-label', `Page ${value}`);
      button.setAttribute('aria-controls', table.id);
      if (value === page) button.setAttribute('aria-current', 'page');
      numbers.appendChild(button);
      last = value;
    });
  };

  previous.addEventListener('click', () => { page--; render(); });
  next.addEventListener('click', () => { page++; render(); });
  numbers.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-page]');
    if (!button) return;
    page = Number(button.dataset.page);
    render();
    numbers.querySelector(`[data-page="${page}"]`)?.focus();
  });
  search.addEventListener('input', () => { page = 1; render(); });
  render();
})();
