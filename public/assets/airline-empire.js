(() => {
  const norm = (value) => String(value ?? '').toLocaleLowerCase('de-DE').trim();

  document.querySelectorAll('select[data-searchable]').forEach((select) => {
    if (select.dataset.enhanced === '1') return;
    select.dataset.enhanced = '1';

    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'select-search';
    input.placeholder = select.dataset.searchPlaceholder || 'Suchen…';
    input.autocomplete = 'off';
    input.setAttribute('aria-label', input.placeholder);
    select.parentNode.insertBefore(input, select);

    const filter = () => {
      const q = norm(input.value);
      [...select.options].forEach((option) => {
        if (!option.value) {
          option.hidden = false;
          return;
        }
        option.hidden = q !== '' && !norm(option.textContent).includes(q);
      });

      [...select.querySelectorAll('optgroup')].forEach((group) => {
        group.hidden = [...group.querySelectorAll('option')].every((option) => option.hidden);
      });
    };

    input.addEventListener('input', filter);
  });

  document.querySelectorAll('[data-aircraft-filter]').forEach((root) => {
    const cards = [...root.querySelectorAll('[data-aircraft-card]')];
    const search = root.querySelector('[data-filter-search]');
    const manufacturer = root.querySelector('[data-filter-manufacturer]');
    const seats = root.querySelector('[data-filter-seats]');
    const range = root.querySelector('[data-filter-range]');
    const status = root.querySelector('[data-filter-status]');
    const count = root.querySelector('[data-filter-count]');

    const apply = () => {
      const q = norm(search?.value);
      const maker = norm(manufacturer?.value);
      const minSeats = Number(seats?.value || 0);
      const minRange = Number(range?.value || 0);
      const wantedStatus = norm(status?.value);
      let visible = 0;

      cards.forEach((card) => {
        const haystack = norm(card.dataset.search);
        const cardMaker = norm(card.dataset.manufacturer);
        const cardStatus = norm(card.dataset.status);
        const cardSeats = Number(card.dataset.seats || 0);
        const cardRange = Number(card.dataset.range || 0);

        const show =
          (!q || haystack.includes(q)) &&
          (!maker || cardMaker === maker) &&
          (!wantedStatus || cardStatus === wantedStatus) &&
          cardSeats >= minSeats &&
          cardRange >= minRange;

        card.hidden = !show;
        if (show) visible++;
      });

      if (count) count.textContent = String(visible);
    };

    [search, manufacturer, seats, range, status].filter(Boolean).forEach((el) => {
      el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', apply);
    });
    apply();
  });

  document.querySelectorAll('[data-table-filter]').forEach((root) => {
    const input = root.querySelector('[data-table-search]');
    const rows = [...root.querySelectorAll('tbody tr[data-filter-row]')];
    const count = root.querySelector('[data-table-count]');

    const apply = () => {
      const q = norm(input?.value);
      let visible = 0;
      rows.forEach((row) => {
        const show = !q || norm(row.dataset.search || row.textContent).includes(q);
        row.hidden = !show;
        if (show) visible++;
      });
      if (count) count.textContent = String(visible);
    };

    input?.addEventListener('input', apply);
    apply();
  });
})();
