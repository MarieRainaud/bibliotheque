const csrf = document.body.dataset.csrf;
for (const cover of document.querySelectorAll('[data-cover]'))
 cover.addEventListener('error', () => cover.remove());
const drawProgress = (root) => {
 for (const fill of root.querySelectorAll('[data-progress]'))
  fill.style.transform =
   'scaleX(' + Math.max(0, Math.min(1, Number(fill.dataset.progress) / 100)) + ')';
};
drawProgress(document);
for (const form of document.querySelectorAll('[data-confirm]'))
 form.addEventListener('submit', (e) => {
  if (!confirm(form.dataset.confirm)) e.preventDefault();
 });
document
 .querySelectorAll('[data-filters]')
 .forEach((form) =>
  form
   .querySelectorAll('select')
   .forEach((select) => select.addEventListener('change', () => form.requestSubmit()))
 );
const bookForm = document.querySelector('[data-book-form]');
if (bookForm) {
 const format = bookForm.querySelector('[data-format]'),
  unit = bookForm.querySelector('[data-unit]'),
  total = bookForm.querySelector('[data-total]'),
  label = bookForm.querySelector('[data-unit-label]');
 const positionLabel = bookForm.querySelector('[data-position-label]');
 const update = () => {
  const option = unit.selectedOptions[0];
  if (!option) return;
  label.textContent = option.dataset.totalLabel;
  if (positionLabel) positionLabel.textContent = option.dataset.positionLabel;
 };
 format.addEventListener('change', () => {
  const next = format.selectedOptions[0].dataset.defaultUnit;
  if (unit.value !== next) {
   unit.value = next;
   total.value = '';
  }
  update();
 });
 unit.addEventListener('change', () => {
  total.value = '';
  update();
 });
 update();
}
const formatBookDate = (value) => value?.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1');
const catalogueUrl = (reference) => '/ajouter?' + new URLSearchParams({ reference });
const addText = (parent, tag, value, cls) => {
 const el = document.createElement(tag);
 el.textContent = value;
 if (cls) el.className = cls;
 parent.append(el);
 return el;
};
const makeBookCard = (
 { title, authors, href, reference, excerpt },
 tag = 'article',
 searchResult = false
) => {
 const card = document.createElement(tag);
 card.className = searchResult ? 'book-card search-result' : 'book-card';
 const link = document.createElement(searchResult ? 'div' : 'a');
 link.className = searchResult ? 'card-body' : 'card-link';
 if (!searchResult) {
  link.href = href;
  link.setAttribute('aria-label', title);
 }
 if (reference) {
  const cover = document.createElement('div');
  cover.className = 'book-cover';
  addText(cover, 'span', 'Sans couverture', 'cover-empty');
  const image = document.createElement('img');
  image.src =
   'https://books.google.com/books/content?' +
   new URLSearchParams({
    id: reference,
    printsec: 'frontcover',
    img: 1,
    zoom: 1,
    source: 'gbs_api'
   });
  image.alt = '';
  image.loading = 'lazy';
  image.referrerPolicy = 'no-referrer';
  image.addEventListener('error', () => image.remove());
  cover.append(image);
  link.append(cover);
 } else link.classList.add('card-link-text');
 const content = document.createElement('div');
 content.className = 'card-content';
 addText(content, 'h3', title);
 if (authors) addText(content, 'p', authors, 'author');
 if (searchResult)
  addText(content, 'p', excerpt || 'Résumé en français indisponible.', 'result-excerpt');
 link.append(content);
 card.append(link);
 return card;
};
const postBookAction = async (url, fields = {}) => {
 const response = await fetch(url, {
  method: 'POST',
  headers: { Accept: 'application/json' },
  body: new URLSearchParams({ _token: csrf, ...fields })
 });
 const data = await response.json().catch(() => ({}));
 if (!response.ok) throw new Error(data.error || 'L’action n’a pas abouti.');
 return data;
};

const search = document.querySelector('form[data-google-search]');
if (search) {
 const status = document.querySelector('[data-google-status]'),
  results = document.querySelector('[data-google-results]'),
  more = document.querySelector('[data-google-more]');
 const seen = new Set();
 let nextPage = 0,
  criteria = {},
  request = null;
 const submit = search.querySelector('[type=submit]');
 const showLibraryLink = (actions, url, label) => {
  const link = addText(actions, 'a', label, 'button');
  link.href = url;
 };
 const renderCard = (book) => {
  const card = makeBookCard(
   { title: book.title, authors: book.authors, reference: book.google_id, excerpt: book.excerpt },
   'article',
   true
  );
  const footer = document.createElement('footer');
  footer.className = 'card-footer';
  card.append(footer);
  const open = addText(footer, 'a', 'Voir le livre', 'button primary card-open');
  open.href = catalogueUrl(book.google_id);
  open.setAttribute('aria-label', 'Voir le livre : ' + book.title);
  const actions = document.createElement('div');
  actions.className = 'card-actions';
  footer.append(actions);
  const message = addText(actions, 'p', '');
  message.setAttribute('role', 'status');
  if (book.library_id)
   showLibraryLink(actions, '/livre/' + book.library_id, 'Dans ma bibliothèque');
  else {
   const add = addText(actions, 'button', 'Ajouter à ma bibliothèque');
   add.type = 'button';
   add.addEventListener('click', async () => {
    add.disabled = true;
    message.textContent = '';
    try {
     const result = await postBookAction(catalogueUrl(book.google_id) + '&rapide=1');
     add.remove();
     showLibraryLink(
      actions,
      result.url,
      result.existing ? 'Dans ma bibliothèque' : 'Voir dans ma bibliothèque'
     );
     message.textContent = result.existing ? 'Déjà ajouté.' : 'Ajouté à ma bibliothèque.';
     if (result.series) {
      const follow = addText(
       actions,
       'button',
       'Suivre la série « ' + result.series.name + ' »',
       ''
      );
      follow.type = 'button';
      follow.addEventListener('click', async () => {
       follow.disabled = true;
       try {
        await postBookAction(result.series.url);
        follow.remove();
        message.textContent = 'Série suivie.';
        discover();
       } catch (error) {
        message.textContent = error.message;
        follow.disabled = false;
       }
      });
     }
    } catch (error) {
     message.textContent = error.message;
     add.disabled = false;
    }
   });
  }
  return card;
 };
 const load = async (append = false) => {
  if (request) return;
  const active = new AbortController();
  request = active;
  submit.disabled = true;
  more.hidden = true;
  status.textContent = 'Recherche de livres…';
  if (!append) {
   results.replaceChildren();
   seen.clear();
   nextPage = 0;
  }
  try {
   let hasMore = false;
   for (let tries = 0; tries < 3; tries++) {
    const response = await fetch(
     '/api/recherche?' + new URLSearchParams({ ...criteria, page: nextPage }),
     { signal: active.signal }
    );
    const data = await response.json();
    if (active.signal.aborted) return;
    if (!response.ok) throw new Error(data.error || 'La recherche n’a pas abouti.');
    nextPage++;
    hasMore = data.more;
    let added = false;
    for (const book of data.items) {
     if (seen.has(book.work)) continue;
     seen.add(book.work);
     results.append(renderCard(book));
     added = true;
    }
    if (added || !hasMore) break;
   }
   const count = results.childElementCount;
   status.textContent = count
    ? count + (count === 1 ? ' livre affiché.' : ' livres affichés.')
    : 'Aucun livre en français trouvé.';
   more.hidden = !hasMore;
  } catch (error) {
   if (!active.signal.aborted) {
    status.textContent = error.message;
    more.hidden = !append;
   }
  } finally {
   if (request === active) {
    request = null;
    submit.disabled = false;
   }
  }
 };
 const clear = () => {
  request?.abort();
  request = null;
  submit.disabled = false;
  nextPage = 0;
  criteria = {};
  results.replaceChildren();
  seen.clear();
  more.hidden = true;
  status.textContent = '';
 };
 search.addEventListener('submit', (e) => {
  e.preventDefault();
  if (request) return;
  criteria = Object.fromEntries(
   ['title', 'author', 'isbn']
    .map((name) => [name, search.elements[name].value.trim()])
    .filter(([, value]) => value)
  );
  if (!Object.keys(criteria).length) {
   clear();
   status.textContent = 'Indiquer un titre, un auteur ou un ISBN.';
   return;
  }
  load();
 });
 search.addEventListener('reset', clear);
 more.addEventListener('click', () => load(true));
}

for (const details of document.querySelectorAll('[data-editions-url]')) {
 let loaded = false,
  loading = false;
 details.addEventListener('toggle', async () => {
  if (!details.open || loaded || loading) return;
  loading = true;
  const status = details.querySelector('[data-editions-status]'),
   list = details.querySelector('[data-editions-list]');
  status.textContent = 'Recherche des éditions…';
  try {
   const response = await fetch(details.dataset.editionsUrl);
   const data = await response.json();
   if (!response.ok)
    throw new Error(data.error || 'Les éditions sont momentanément indisponibles.');
   list.replaceChildren();
   for (const edition of data.editions) {
    const row = document.createElement('li');
    if (edition.current) {
     addText(row, 'strong', edition.title);
     addText(row, 'span', 'Édition affichée', 'tag');
    } else {
     const link = addText(row, 'a', edition.title);
     link.href = catalogueUrl(edition.reference);
    }
    if (edition.subtitle) addText(row, 'p', edition.subtitle);
    const meta = [
     edition.publisher,
     formatBookDate(edition.published),
     edition.pages ? edition.pages + ' p.' : '',
     edition.isbn ? 'ISBN ' + edition.isbn : ''
    ].filter(Boolean);
    if (meta.length) addText(row, 'p', meta.join(' · '), 'muted');
    list.append(row);
   }
   status.textContent = data.editions.length ? '' : 'Aucune édition renseignée.';
   loaded = true;
  } catch (error) {
   status.textContent = error.message;
  } finally {
   loading = false;
  }
 });
}
const refreshSeriesDashboard = async (dashboard) => {
 const response = await fetch(dashboard.dataset.seriesDashboard);
 if (!response.ok)
  throw new Error(
   'L’affichage des séries n’a pas pu être actualisé. Recharge la page pour voir les dernières informations.'
  );
 dashboard.innerHTML = await response.text();
};
const updateBookScreen = (data) => {
 const wish = document.querySelector('[data-book-wishlist]');
 if (wish) wish.hidden = data.owned;
 const summary = document.querySelector('[data-reading-summary]');
 if (summary) {
  summary.innerHTML = data.summary;
  drawProgress(summary);
 }
 const form = document.querySelector('[data-reading-form]');
 if (form)
  for (const name of ['status', 'position', 'started', 'finished']) {
   if (form.elements[name]) form.elements[name].value = data[name] ?? '';
  }
 const reread = document.querySelector('[data-reread-form]');
 if (reread) reread.hidden = data.status !== 'finished';
 const history = document.querySelector('[data-current-reading]');
 if (history && summary) {
  history.querySelector('.status')?.replaceWith(summary.querySelector('.status').cloneNode(true));
  history.querySelector('p').textContent =
   (formatBookDate(data.started) || 'Début non renseigné') +
   (data.finished ? ' au ' + formatBookDate(data.finished) : '');
 }
};
let flagRequests = 0,
 pendingSeriesRefresh = null;
document.addEventListener('change', async (event) => {
 const input = event.target.closest?.('input[data-book-flag]');
 const row = input?.closest('[data-book-state]');
 if (!row) return;
 const controls = [...row.querySelectorAll('input[data-book-flag]')];
 const error = document.querySelector('[data-book-state-error]');
 const before = !input.checked;
 const restoreFocus = document.activeElement === input;
 const seriesView = row.closest('[data-series-view]');
 const flag = input.dataset.bookFlag;
 controls.forEach((control) => (control.disabled = true));
 if (error) error.hidden = true;
 flagRequests++;
 try {
  const fields = { flag, value: input.checked ? '1' : '0' };
  if (row.dataset.bookId) fields.id = row.dataset.bookId;
  else {
   fields.reference = row.dataset.reference;
   if (row.dataset.suggestion) fields.suggestion = row.dataset.suggestion;
  }
  const data = await postBookAction('/api/etat-livre', fields);
  row.dataset.bookId = data.id;
  controls.forEach((control) => (control.checked = Boolean(data[control.dataset.bookFlag])));
  if (seriesView)
   pendingSeriesRefresh = {
    view: seriesView,
    url: data.series_url,
    id: data.id,
    flag,
    restoreFocus
   };
  else updateBookScreen(data);
 } catch (failure) {
  input.checked = before;
  if (error) {
   error.textContent = failure.message;
   error.hidden = false;
  }
 } finally {
  flagRequests--;
  controls.forEach((control) => (control.disabled = false));
 }
 if (flagRequests === 0 && pendingSeriesRefresh) {
  const refresh = pendingSeriesRefresh;
  pendingSeriesRefresh = null;
  if (!refresh.view.dataset.seriesDashboard) {
   if (refresh.url) location.assign(refresh.url);
   else location.reload();
  } else {
   try {
    await refreshSeriesDashboard(refresh.view);
    if (refresh.restoreFocus)
     refresh.view
      .querySelector('[data-book-id="' + refresh.id + '"] [data-book-flag="' + refresh.flag + '"]')
      ?.focus();
   } catch {
    if (error) {
     error.textContent =
      'L’affichage des séries n’a pas pu être actualisé. Recharge la page pour voir les dernières informations.';
     error.hidden = false;
    }
   }
  }
 } else if (restoreFocus && input.isConnected) input.focus();
});

let discovering = false;
async function discover(force = false) {
 if (discovering) return;
 discovering = true;
 const buttons = [...document.querySelectorAll('[data-refresh-news]')];
 const statuses = [...document.querySelectorAll('[data-discovery-status]')];
 buttons.forEach((b) => (b.disabled = true));
 let checked = 0,
  hasErrors = false;
 const message = (text) => statuses.forEach((s) => (s.textContent = text));
 try {
  let remaining = 1;
  while (remaining > 0) {
   message('Vérification des séries suivies…');
   const response = await fetch('/api/nouveautes', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ force: force ? '1' : '0' })
   });
   const data = await response.json();
   if (!response.ok) throw new Error(data.error || 'La vérification n’a pas abouti.');
   checked += data.checked;
   hasErrors ||= data.errors.length > 0;
   remaining = data.remaining;
   document.querySelectorAll('[data-news-count]').forEach((b) => {
    b.textContent = data.count;
    b.hidden = data.count === 0;
   });
  }
  const dashboard = document.querySelector('[data-series-dashboard]');
  if (dashboard && (checked || hasErrors) && flagRequests === 0)
   await refreshSeriesDashboard(dashboard);
  message(
   hasErrors
    ? 'Certaines séries n’ont pas pu être vérifiées. Réessayer dans quelques minutes.'
    : checked
      ? 'Vérification terminée.'
      : force
        ? 'Les séries ont déjà été vérifiées récemment.'
        : ''
  );
 } catch (error) {
  message(error.message);
 } finally {
  discovering = false;
  buttons.forEach((b) => (b.disabled = false));
 }
}
document
 .querySelectorAll('[data-refresh-news]')
 .forEach((button) => button.addEventListener('click', () => discover(true)));
if (document.body.dataset.discovery === 'yes') discover();

const relations = document.querySelector('[data-book-relations]');
if (relations) {
 const status = addText(relations, 'p', '', 'notice error');
 status.setAttribute('role', 'status');
 status.hidden = true;
 const retry = addText(relations, 'button', 'Réessayer');
 retry.type = 'button';
 retry.hidden = true;
 let loading = false;
 const showRelations = async () => {
  if (loading) return;
  loading = true;
  status.hidden = true;
  retry.disabled = true;
  try {
   const response = await fetch(relations.dataset.bookRelations);
   if (!response.ok) throw new Error();
   const data = await response.json();
   for (const section of relations.querySelectorAll('[data-relation]')) {
    const items = data[section.dataset.relation] || [];
    if (!Array.isArray(items)) throw new Error();
    const cards = items.map((item) => makeBookCard(item, 'li'));
    section.querySelector('ul').replaceChildren(...cards);
    section.hidden = items.length === 0;
   }
   retry.hidden = true;
  } catch {
   status.textContent = 'Les autres livres n’ont pas pu être chargés. Réessayer dans un instant.';
   status.hidden = false;
   retry.hidden = false;
  } finally {
   loading = false;
   retry.disabled = false;
  }
 };
 retry.addEventListener('click', showRelations);
 if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver(
   (entries) => {
    if (entries.some((entry) => entry.isIntersecting)) {
     observer.disconnect();
     showRelations();
    }
   },
   { rootMargin: '160px' }
  );
  observer.observe(relations);
 } else showRelations();
}
