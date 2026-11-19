document.addEventListener('DOMContentLoaded', () => {
  if (window.location.protocol === 'file:' || window.location.hostname === '') {
    window.location.replace('http://localhost/APPLICATION/');
    return;
  }

  const loginView = document.getElementById('login-view');
  const mainLayout = document.getElementById('main-layout');
  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const registerModal = document.getElementById('register-modal');
  const logoutModal = document.getElementById('logout-modal');
  const notifPanel = document.getElementById('notification-panel');
  const profileMenu = document.getElementById('profile-menu');
  const appMessage = document.getElementById('app-message');
  const authMessage = document.getElementById('auth-message');
  const registerMessage = document.getElementById('register-message');
  const navItems = document.querySelectorAll('.nav-item:not(.logout-item)');
  const subViews = document.querySelectorAll('.sub-view');
  const bookGrid = document.getElementById('book-cards-grid');
  const loansTable = document.getElementById('loans-table-body');
  const notificationList = document.getElementById('notifications-list');
  const activityList = document.getElementById('activity-list');
  const adminBooksTable = document.getElementById('admin-books-table-body');
  const adminBookForm = document.getElementById('admin-book-form');
  const globalSearch = document.getElementById('global-search');
  const filterAuthor = document.getElementById('filter-author');
  const filterCategory = document.getElementById('filter-category');
  const filterAvailability = document.getElementById('filter-availability');
  const noBookResult = document.getElementById('no-book-result');
  const sidebar = document.getElementById('app-sidebar');
  const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
  const bookPreviewModal = document.getElementById('book-preview-modal');
  const bookPreviewFrame = document.getElementById('book-preview-frame');
  const bookPreviewLink = document.getElementById('book-preview-link');
  const state = { csrfToken: '', dashboard: null, charts: {} };
  let previewReturnFocus = null;

  const setMobileMenuOpen = open => {
    mainLayout.classList.toggle('sidebar-open', open);
    mobileMenuToggle.setAttribute('aria-expanded', String(open));
    mobileMenuToggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
    mobileMenuToggle.innerHTML = `<i class="fa-solid ${open ? 'fa-xmark' : 'fa-bars'}" aria-hidden="true"></i>`;
  };

  const closeBookPreview = () => {
    bookPreviewModal.classList.add('hidden');
    bookPreviewFrame.src = 'about:blank';
    if (previewReturnFocus instanceof HTMLElement) previewReturnFocus.focus();
  };

  const openBookPreview = book => {
    const isbn = (book.isbn || '').replace(/[^0-9Xx]/g, '');
    const search = new URLSearchParams({ q: `${book.title} ${book.author}` });
    const previewUrl = isbn
      ? `https://books.google.com/books?vid=ISBN:${encodeURIComponent(isbn)}&printsec=frontcover&output=embed`
      : `https://books.google.com/books?${search.toString()}&output=embed`;
    const externalUrl = isbn
      ? `https://books.google.com/books?vid=ISBN:${encodeURIComponent(isbn)}`
      : `https://books.google.com/books?${search.toString()}`;

    document.getElementById('book-preview-title').textContent = book.title;
    document.getElementById('book-preview-author').textContent = `by ${book.author}`;
    bookPreviewFrame.src = previewUrl;
    bookPreviewLink.href = externalUrl;
    document.getElementById('book-preview-notice').textContent = isbn
      ? 'Google Books preview pages are shown when the publisher makes them available.'
      : 'No ISBN is listed for this book. Search Google Books for a reading preview.';
    previewReturnFocus = document.activeElement;
    bookPreviewModal.classList.remove('hidden');
    document.getElementById('close-book-preview').focus();
  };

  const api = async (action, options = {}) => {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json' };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && !['login', 'register'].includes(action)) {
      headers['X-CSRF-Token'] = state.csrfToken;
    }

    let response;
    try {
      response = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: options.body === undefined ? undefined : JSON.stringify(options.body)
      });
    } catch {
      throw new Error('Cannot reach the PHP server. Open this app through XAMPP at http://localhost/APPLICATION/ and make sure Apache and MySQL are running.');
    }
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error('The server returned an invalid response. Check that PHP is running.');
    }
    if (!response.ok) throw new Error(result.error || 'The request could not be completed.');
    return result;
  };

  const showMessage = (element, message, kind = 'error') => {
    element.textContent = message;
    element.classList.remove('hidden', 'success-message', 'error-message');
    element.classList.add(kind === 'success' ? 'success-message' : 'error-message');
  };

  const clearMessage = element => {
    element.textContent = '';
    element.classList.add('hidden');
    element.classList.remove('success-message', 'error-message');
  };

  const announce = (message, kind = 'success') => {
    showMessage(appMessage, message, kind);
    window.clearTimeout(announce.timer);
    announce.timer = window.setTimeout(() => clearMessage(appMessage), 5000);
  };

  const activateView = targetId => {
    if (targetId === 'admin-books-view' && state.dashboard?.user.role !== 'admin') {
      targetId = 'dashboard-view';
    }
    navItems.forEach(item => item.classList.toggle('active', item.dataset.target === targetId));
    subViews.forEach(view => {
      const active = view.id === targetId;
      view.classList.toggle('hidden', !active);
      view.classList.toggle('active', active);
    });
  };

  const closeDropdowns = () => {
    notifPanel.classList.add('hidden');
    profileMenu.classList.add('hidden');
  };

  const displayDate = value => {
    if (!value) return '—';
    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(undefined, {
      year: 'numeric', month: 'short', day: 'numeric'
    }).format(date);
  };

  const displayDateTime = value => {
    if (!value) return '';
    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, {
      year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
    }).format(date);
  };

  const textElement = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = text;
    return element;
  };

  const setCount = (id, value) => {
    document.getElementById(id).textContent = Number(value || 0).toLocaleString();
  };

  const setOptions = (select, values, label) => {
    const selected = select.value;
    select.replaceChildren(new Option(label, ''));
    values.forEach(value => select.add(new Option(value, value)));
    if (values.includes(selected)) select.value = selected;
  };

  const populateFilters = books => {
    setOptions(filterAuthor, [...new Set(books.map(book => book.author))].sort(), 'All Authors');
    setOptions(filterCategory, [...new Set(books.map(book => book.category))].sort(), 'All Categories');
  };

  const renderBooks = () => {
    const { books } = state.dashboard;
    const keyword = (globalSearch.value || '').trim().toLowerCase();
    const filtered = books.filter(book => {
      const available = Number(book.available_copies) > 0;
      const matchesKeyword = !keyword || [book.title, book.author, book.category, book.isbn]
        .some(value => value.toLowerCase().includes(keyword));
      const matchesAuthor = !filterAuthor.value || book.author === filterAuthor.value;
      const matchesCategory = !filterCategory.value || book.category === filterCategory.value;
      const matchesAvailability = !filterAvailability.value ||
        (filterAvailability.value === 'available' ? available : !available);
      return matchesKeyword && matchesAuthor && matchesCategory && matchesAvailability;
    });

    bookGrid.replaceChildren();
    filtered.forEach(book => {
      const available = Number(book.available_copies) > 0;
      const card = document.createElement('article');
      card.className = 'book-item';
      card.dataset.title = book.title;
      card.dataset.author = book.author;
      card.dataset.category = book.category;

      const image = document.createElement('img');
      image.src = book.cover;
      image.alt = book.title;
      image.loading = 'lazy';
      image.addEventListener('error', () => {
        const placeholder = textElement('div', 'book-cover-placeholder', '');
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-book-open';
        icon.setAttribute('aria-hidden', 'true');
        placeholder.append(icon, textElement('span', '', book.title));
        image.replaceWith(placeholder);
      }, { once: true });

      const details = textElement('div', 'book-details', '');
      const publicationDetails = [
        book.published_year ? `Published ${book.published_year}` : '',
        book.isbn ? `ISBN ${book.isbn}` : ''
      ].filter(Boolean);
      details.append(
        textElement('h4', '', book.title),
        textElement('p', 'book-author', book.author),
        textElement('p', 'book-category', book.category),
        ...publicationDetails.map(detail => textElement('p', 'book-publication', detail)),
        textElement('span', `badge-status ${available ? 'available' : 'borrowed'}`,
          available ? `${book.available_copies} available` : 'Currently borrowed')
      );

      const borrowButton = textElement('button', 'btn-primary btn-sm book-borrow-btn', 'Borrow');
      borrowButton.type = 'button';
      borrowButton.dataset.bookId = book.id;
      borrowButton.disabled = !available;
      const actions = textElement('div', 'book-card-actions', '');
      const previewButton = textElement('button', 'btn-secondary btn-sm book-preview-button', 'Preview');
      previewButton.type = 'button';
      previewButton.dataset.previewBookId = book.id;
      actions.append(previewButton, borrowButton);
      if (state.dashboard.user.role === 'admin') {
        const editButton = textElement('button', 'btn-secondary btn-sm admin-edit-book', 'Edit');
        editButton.type = 'button';
        editButton.dataset.bookId = book.id;
        actions.append(editButton);
      }
      details.append(actions);
      card.append(image, details);
      bookGrid.append(card);
    });

    const hasFilters = Boolean(keyword || filterAuthor.value || filterCategory.value || filterAvailability.value);
    noBookResult.classList.toggle('hidden', filtered.length > 0 || !hasFilters);
    noBookResult.textContent = 'No books match your search and filters.';
  };

  const renderAdminBooks = books => {
    adminBooksTable.replaceChildren();
    books.forEach(book => {
      const row = document.createElement('tr');
      [
        book.title,
        book.author,
        book.category,
        book.isbn || '—',
        book.published_year ? String(book.published_year) : '—',
        String(book.total_copies),
        String(book.available_copies)
      ].forEach(value => row.append(textElement('td', '', value)));
      const actions = document.createElement('td');
      if (state.dashboard.user.role === 'admin') {
        const edit = textElement('button', 'btn-secondary btn-sm', 'Edit');
        edit.type = 'button';
        edit.dataset.editBookId = book.id;
        const remove = textElement('button', 'btn-danger btn-sm', 'Delete');
        remove.type = 'button';
        remove.dataset.deleteBookId = book.id;
        actions.append(edit, remove);
      } else {
        actions.textContent = '—';
      }
      row.append(actions);
      adminBooksTable.append(row);
    });
    if (!books.length) {
      const row = document.createElement('tr');
      const cell = textElement('td', 'empty-table-message', 'There are no books in the catalog.');
      cell.colSpan = 8;
      row.append(cell);
      adminBooksTable.append(row);
    }
  };

  const resetBookForm = () => {
    adminBookForm.reset();
    document.getElementById('admin-book-id').value = '';
    document.getElementById('admin-book-copies').value = '1';
    document.getElementById('book-form-heading').textContent = 'Add a book';
    document.getElementById('admin-book-submit').textContent = 'Add Book';
    document.getElementById('admin-book-cancel').classList.add('hidden');
  };

  const editBook = bookId => {
    const book = state.dashboard.books.find(item => String(item.id) === String(bookId));
    if (!book) return;
    document.getElementById('admin-book-id').value = book.id;
    document.getElementById('admin-book-title').value = book.title;
    document.getElementById('admin-book-author').value = book.author;
    document.getElementById('admin-book-category').value = book.category;
    document.getElementById('admin-book-isbn').value = book.isbn || '';
    document.getElementById('admin-book-year').value = book.published_year || '';
    document.getElementById('admin-book-copies').value = book.total_copies;
    document.getElementById('admin-book-cover').value = book.cover || '';
    document.getElementById('book-form-heading').textContent = `Edit: ${book.title}`;
    document.getElementById('admin-book-submit').textContent = 'Save Changes';
    document.getElementById('admin-book-cancel').classList.remove('hidden');
    activateView('admin-books-view');
    document.getElementById('admin-book-title').focus();
  };

  const renderLoans = loans => {
    const isAdmin = state.dashboard.user.role === 'admin';
    loansTable.replaceChildren();
    if (!loans.length) {
      const row = document.createElement('tr');
      const cell = textElement('td', 'empty-table-message',
        isAdmin ? 'There are no borrowing records yet.' : 'You have no borrowing history yet.');
      cell.colSpan = isAdmin ? 6 : 4;
      row.append(cell);
      loansTable.append(row);
      return;
    }

    loans.forEach(loan => {
      const row = document.createElement('tr');
      const isActive = !loan.returned_at;
      const isOverdue = isActive && new Date(`${loan.due_at.replace(' ', 'T')}Z`) < new Date();
      const status = textElement('span', `badge-status ${isActive ? 'borrowed' : 'available'}`,
        isActive ? (isOverdue ? 'Overdue' : 'Borrowed') : 'Returned');
      row.append(textElement('td', '', loan.title));
      if (isAdmin) {
        row.append(textElement('td', '', `${loan.borrower_name} (@${loan.borrower_username})`));
      }
      row.append(
        textElement('td', '', displayDate(loan.borrowed_at)),
        textElement('td', '', displayDate(loan.due_at))
      );
      const statusCell = document.createElement('td');
      statusCell.append(status);
      row.append(statusCell);

      if (isAdmin) {
        const actionCell = document.createElement('td');
        if (isActive) {
          const returnButton = textElement('button', 'btn-secondary btn-sm', 'Mark Returned');
          returnButton.type = 'button';
          returnButton.dataset.loanId = loan.id;
          actionCell.append(returnButton);
        } else {
          actionCell.textContent = '—';
        }
        row.append(actionCell);
      }
      loansTable.append(row);
    });
  };

  const renderNotifications = notifications => {
    const count = notifications.length;
    document.querySelectorAll('.notification-count').forEach(element => {
      element.textContent = count;
      element.classList.toggle('hidden', count === 0);
    });
    document.querySelectorAll('.notification-dot').forEach(element => element.classList.toggle('hidden', count === 0));
    const panelCount = document.getElementById('notification-panel-count');
    panelCount.textContent = count;
    panelCount.classList.toggle('hidden', count === 0);
    notificationList.replaceChildren();
    const dropdownItems = document.getElementById('notification-dropdown-items');
    dropdownItems.replaceChildren();

    if (!count) {
      notificationList.append(textElement('p', 'empty-table-message', 'You are all caught up.'));
      dropdownItems.append(textElement('p', 'dropdown-empty', 'You are all caught up.'));
      return;
    }
    notifications.forEach((notification, index) => {
      const box = document.createElement('div');
      box.className = `notif-box ${notification.type === 'danger' ? 'danger' : notification.type === 'success' ? 'success' : 'warning'}`;
      const icon = document.createElement('i');
      icon.className = `fa-solid ${notification.type === 'danger' ? 'fa-triangle-exclamation' : notification.type === 'success' ? 'fa-circle-check' : 'fa-clock'}`;
      const body = document.createElement('div');
      body.append(
        textElement('p', '', notification.title + ': ' + notification.message),
        textElement('span', '', displayDateTime(notification.created_at))
      );
      box.append(icon, body);
      notificationList.append(box);

      if (index < 5) {
        const item = document.createElement('a');
        item.href = '#';
        item.className = 'dropdown-item notification-link';
        item.dataset.target = 'notifications-view';
        item.append(
          textElement('i', `fa-solid ${notification.type === 'danger' ? 'fa-circle-exclamation danger' : notification.type === 'success' ? 'fa-circle-check success' : 'fa-clock info'}`, ''),
          textElement('span', '', notification.title + ': ' + notification.message)
        );
        dropdownItems.append(item);
      }
    });
  };

  const renderActivities = loans => {
    activityList.replaceChildren();
    const recent = loans.slice(0, 5);
    if (!recent.length) {
      activityList.append(textElement('p', 'empty-table-message', 'Borrow or return a book to see your recent activity.'));
      return;
    }
    recent.forEach(loan => {
      const returned = Boolean(loan.returned_at);
      const item = document.createElement('div');
      item.className = `activity-item ${returned ? 'green' : 'blue'}`;
      const icon = textElement('div', `act-icon ${returned ? 'green' : 'blue'}`, '');
      const iconElement = document.createElement('i');
      iconElement.className = `fa-solid ${returned ? 'fa-circle-check' : 'fa-book-open'}`;
      icon.append(iconElement);
      const info = document.createElement('div');
      info.className = 'act-info';
      const description = document.createElement('p');
      const isAdmin = state.dashboard.user.role === 'admin';
      description.append(document.createTextNode(returned
        ? (isAdmin ? `Admin recorded a return for ${loan.borrower_name}: ` : 'You returned ')
        : (isAdmin ? `${loan.borrower_name} borrowed ` : 'You borrowed ')));
      description.append(textElement('strong', '', loan.title));
      info.append(description, textElement('span', '', displayDateTime(returned ? loan.returned_at : loan.borrowed_at)));
      item.append(icon, info);
      activityList.append(item);
    });
  };

  const renderCharts = reports => {
    if (typeof Chart === 'undefined') return;
    if (state.charts.activity) {
      state.charts.activity.data.labels = reports.activity_labels;
      state.charts.activity.data.datasets[0].data = reports.activity_values;
      state.charts.activity.update();
    } else {
      state.charts.activity = new Chart(document.getElementById('reportsChart'), {
        type: 'line',
        data: {
          labels: reports.activity_labels,
          datasets: [{
            label: 'Borrow transactions',
            data: reports.activity_values,
            borderColor: '#5b4abf',
            backgroundColor: 'rgba(91, 74, 191, 0.1)',
            fill: true,
            tension: 0.3
          }]
        },
        options: { responsive: true, maintainAspectRatio: false }
      });
    }

    if (state.charts.categories) {
      state.charts.categories.data.labels = reports.category_labels;
      state.charts.categories.data.datasets[0].data = reports.category_values;
      state.charts.categories.update();
    } else {
      state.charts.categories = new Chart(document.getElementById('categoryChart'), {
        type: 'doughnut',
        data: {
          labels: reports.category_labels,
          datasets: [{
            data: reports.category_values,
            backgroundColor: ['#4880ff', '#00c9a7', '#ff9f43', '#7d5ce6', '#d75d6a']
          }]
        },
        options: { responsive: true, maintainAspectRatio: false }
      });
    }
  };

  const applyTheme = theme => {
    document.body.classList.toggle('dark-theme', theme === 'dark');
    document.getElementById('theme-select').value = theme;
  };

  const applyDashboard = dashboard => {
    state.dashboard = dashboard;
    state.csrfToken = dashboard.csrf_token;
    const { user, books, loans, stats, notifications, reports } = dashboard;
    document.querySelector('#header-profile-btn .name').textContent = user.name;
    const isAdmin = user.role === 'admin';
    document.querySelector('#header-profile-btn .role').textContent = isAdmin ? 'Administrator' : 'Member';
    document.querySelectorAll('.admin-only').forEach(element => element.classList.toggle('hidden', !isAdmin));
    document.getElementById('loans-view-description').textContent = isAdmin
      ? 'Review all borrowing records and record book returns.'
      : 'View your borrowing history. Return books to the administrator.';
    if (!isAdmin && document.getElementById('admin-books-view').classList.contains('active')) {
      activateView('dashboard-view');
    }
    document.querySelector('#dashboard-view .view-header p').textContent = `Welcome back, ${user.name}!`;

    document.getElementById('setting-name').value = user.name;
    document.getElementById('setting-username').value = user.username;
    document.getElementById('setting-course').value = user.course;
    document.getElementById('setting-year').value = user.year_level;
    document.getElementById('setting-contact').value = user.contact_no;
    document.getElementById('profile-username').textContent = user.username;
    document.getElementById('profile-course').textContent = user.course || 'Not provided';
    document.getElementById('profile-year').textContent = user.year_level || 'Not provided';
    document.getElementById('profile-contact').textContent = user.contact_no || 'Not provided';
    document.getElementById('member-since').textContent = displayDate(user.created_at);
    applyTheme(user.theme);

    setCount('stat-total-books', stats.total_books);
    setCount('stat-total-borrowers', stats.total_borrowers);
    setCount('stat-active-loans', stats.active_loans);
    setCount('stat-overdue', stats.overdue);
    setCount('report-total-books', stats.total_books);
    setCount('report-total-borrowers', stats.total_borrowers);
    setCount('report-total-transactions', stats.total_transactions);

    populateFilters(books);
    renderBooks();
    renderAdminBooks(books);
    renderLoans(loans);
    renderNotifications(notifications);
    renderActivities(loans);
    renderCharts(reports);
    loginView.classList.add('hidden');
    mainLayout.classList.remove('hidden');
    clearMessage(authMessage);
  };

  const refreshDashboard = async () => {
    const dashboard = await api('dashboard');
    applyDashboard(dashboard);
    return dashboard;
  };

  loginForm.addEventListener('submit', async event => {
    event.preventDefault();
    clearMessage(authMessage);
    const submit = loginForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      const result = await api('login', {
        method: 'POST',
        body: {
          username: document.getElementById('username').value,
          password: document.getElementById('password').value
        }
      });
      applyDashboard(result);
      loginForm.reset();
    } catch (error) {
      showMessage(authMessage, error.message);
    } finally {
      submit.disabled = false;
    }
  });

  document.getElementById('register-link').addEventListener('click', () => {
    clearMessage(registerMessage);
    registerModal.classList.remove('hidden');
  });

  document.getElementById('close-register-modal').addEventListener('click', () => {
    registerModal.classList.add('hidden');
    registerForm.reset();
    clearMessage(registerMessage);
  });

  registerForm.addEventListener('submit', async event => {
    event.preventDefault();
    clearMessage(registerMessage);
    const submit = registerForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      const result = await api('register', {
        method: 'POST',
        body: {
          name: document.getElementById('reg-name').value,
          username: document.getElementById('reg-username').value,
          password: document.getElementById('reg-password').value
        }
      });
      registerModal.classList.add('hidden');
      registerForm.reset();
      applyDashboard(result);
      announce('Your account has been created.');
    } catch (error) {
      showMessage(registerMessage, error.message);
    } finally {
      submit.disabled = false;
    }
  });

  document.getElementById('cancel-logout-btn').addEventListener('click', () => logoutModal.classList.add('hidden'));
  document.getElementById('open-logout-modal').addEventListener('click', event => {
    event.preventDefault();
    setMobileMenuOpen(false);
    logoutModal.classList.remove('hidden');
  });
  document.getElementById('profile-logout-link').addEventListener('click', event => {
    event.preventDefault();
    closeDropdowns();
    logoutModal.classList.remove('hidden');
  });
  document.getElementById('confirm-logout-btn').addEventListener('click', async () => {
    const button = document.getElementById('confirm-logout-btn');
    button.disabled = true;
    try {
      await api('logout', { method: 'POST', body: {} });
      logoutModal.classList.add('hidden');
      mainLayout.classList.add('hidden');
      loginView.classList.remove('hidden');
      closeDropdowns();
      setMobileMenuOpen(false);
      state.dashboard = null;
      activateView('dashboard-view');
      loginForm.reset();
      clearMessage(authMessage);
    } catch (error) {
      announce(error.message, 'error');
    } finally {
      button.disabled = false;
    }
  });

  navItems.forEach(item => item.addEventListener('click', event => {
    event.preventDefault();
    activateView(item.dataset.target);
    closeDropdowns();
    setMobileMenuOpen(false);
  }));

  document.addEventListener('click', event => {
    const route = event.target.closest('.route-link, .route-button, .notification-link');
    if (route && route.dataset.target) {
      event.preventDefault();
      activateView(route.dataset.target);
      closeDropdowns();
      setMobileMenuOpen(false);
    }
    if (mainLayout.classList.contains('sidebar-open') &&
        !sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
      setMobileMenuOpen(false);
    }
    if (!notifPanel.contains(event.target) && !document.getElementById('header-notif-btn').contains(event.target)) {
      notifPanel.classList.add('hidden');
    }
    if (!profileMenu.contains(event.target) && !document.getElementById('header-profile-btn').contains(event.target)) {
      profileMenu.classList.add('hidden');
    }
  });

  const toggleMobileMenu = () => {
    setMobileMenuOpen(!mainLayout.classList.contains('sidebar-open'));
  };

  mobileMenuToggle.addEventListener('pointerup', event => {
    if (event.button !== 0) return;
    event.preventDefault();
    toggleMobileMenu();
  });

  mobileMenuToggle.addEventListener('click', event => {
    event.preventDefault();
    if (event.detail === 0) toggleMobileMenu();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !bookPreviewModal.classList.contains('hidden')) {
      closeBookPreview();
      return;
    }
    if (event.key === 'Escape' && mainLayout.classList.contains('sidebar-open')) {
      setMobileMenuOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 800) setMobileMenuOpen(false);
  });

  document.getElementById('header-notif-btn').addEventListener('click', event => {
    event.stopPropagation();
    const wasHidden = notifPanel.classList.contains('hidden');
    closeDropdowns();
    notifPanel.classList.toggle('hidden', !wasHidden);
  });

  document.getElementById('header-profile-btn').addEventListener('click', event => {
    event.stopPropagation();
    const wasHidden = profileMenu.classList.contains('hidden');
    closeDropdowns();
    profileMenu.classList.toggle('hidden', !wasHidden);
  });

  [globalSearch, filterAuthor, filterCategory, filterAvailability].forEach(input => {
    input.addEventListener(input === globalSearch ? 'input' : 'change', () => {
      if (!state.dashboard) return;
      renderBooks();
      if (input === globalSearch && input.value.trim()) activateView('catalog-view');
    });
  });

  bookGrid.addEventListener('click', async event => {
    const previewButton = event.target.closest('.book-preview-button');
    if (previewButton) {
      const book = state.dashboard.books.find(item => String(item.id) === previewButton.dataset.previewBookId);
      if (book) openBookPreview(book);
      return;
    }
    const editButton = event.target.closest('.admin-edit-book');
    if (editButton && state.dashboard.user.role === 'admin') {
      editBook(editButton.dataset.bookId);
      return;
    }
    const button = event.target.closest('.book-borrow-btn');
    if (!button || button.disabled) return;
    button.disabled = true;
    try {
      applyDashboard(await api('borrow', { method: 'POST', body: { book_id: button.dataset.bookId } }));
      announce('Book borrowed. It is due in 14 days.');
    } catch (error) {
      announce(error.message, 'error');
      button.disabled = false;
    }
  });

  document.getElementById('close-book-preview').addEventListener('click', closeBookPreview);
  bookPreviewModal.addEventListener('click', event => {
    if (event.target === bookPreviewModal) closeBookPreview();
  });

  document.getElementById('admin-book-cancel').addEventListener('click', resetBookForm);

  adminBookForm.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = document.getElementById('admin-book-submit');
    submit.disabled = true;
    try {
      const saved = await api('save-book', {
        method: 'POST',
        body: Object.fromEntries(new FormData(adminBookForm).entries())
      });
      applyDashboard(saved);
      resetBookForm();
      announce('Book saved to the catalog.');
    } catch (error) {
      announce(error.message, 'error');
    } finally {
      submit.disabled = false;
    }
  });

  adminBooksTable.addEventListener('click', async event => {
    const editButton = event.target.closest('[data-edit-book-id]');
    if (editButton) {
      editBook(editButton.dataset.editBookId);
      return;
    }
    const deleteButton = event.target.closest('[data-delete-book-id]');
    if (!deleteButton || !window.confirm('Delete this book from the catalog? Books with borrowing history cannot be deleted.')) return;
    deleteButton.disabled = true;
    try {
      applyDashboard(await api('delete-book', {
        method: 'POST',
        body: { id: deleteButton.dataset.deleteBookId }
      }));
      resetBookForm();
      announce('Book removed from the catalog.');
    } catch (error) {
      announce(error.message, 'error');
      deleteButton.disabled = false;
    }
  });

  loansTable.addEventListener('click', async event => {
    const button = event.target.closest('[data-loan-id]');
    if (!button || button.disabled || state.dashboard.user.role !== 'admin') return;
    button.disabled = true;
    try {
      applyDashboard(await api('return', { method: 'POST', body: { loan_id: button.dataset.loanId } }));
      announce('Book return recorded.');
    } catch (error) {
      announce(error.message, 'error');
      button.disabled = false;
    }
  });

  document.getElementById('profile-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      applyDashboard(await api('save-profile', {
        method: 'POST',
        body: Object.fromEntries(new FormData(event.currentTarget).entries())
      }));
      announce('Profile changes saved.');
    } catch (error) {
      announce(error.message, 'error');
    }
  });

  document.getElementById('password-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      const result = await api('change-password', {
        method: 'POST',
        body: Object.fromEntries(new FormData(form).entries())
      });
      form.reset();
      announce(result.message);
    } catch (error) {
      announce(error.message, 'error');
    }
  });

  document.getElementById('appearance-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      applyDashboard(await api('save-theme', {
        method: 'POST',
        body: { theme: document.getElementById('theme-select').value }
      }));
      announce('Appearance saved.');
    } catch (error) {
      announce(error.message, 'error');
    }
  });

  document.querySelectorAll('[data-settings-tab]').forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.settingsTab;
      document.querySelectorAll('[data-settings-tab]').forEach(item => {
        const active = item === tab;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', String(active));
      });
      document.querySelectorAll('[data-settings-panel]').forEach(panel => {
        panel.classList.toggle('hidden', panel.dataset.settingsPanel !== target);
      });
    });
  });

  (async () => {
    try {
      const session = await api('session');
      state.csrfToken = session.csrf_token;
      if (session.authenticated) await refreshDashboard();
    } catch (error) {
      showMessage(authMessage, error.message);
    }
  })();

  window.setInterval(async () => {
    if (state.dashboard?.user.role !== 'admin') return;
    try {
      const dashboard = await api('dashboard');
      if (!state.dashboard) return;
      if (dashboard.user.role !== 'admin') {
        applyDashboard(dashboard);
        return;
      }
      if (state.dashboard.user.role === 'admin') {
        renderLoans(dashboard.loans);
        renderNotifications(dashboard.notifications);
        renderActivities(dashboard.loans);
      }
    } catch (error) {
      announce(error.message, 'error');
    }
  }, 30000);
});
