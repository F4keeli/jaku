{
const nav = document.querySelector('.primary-nav');
const menuToggle = document.querySelector('.menu-toggle');
const toast = document.querySelector('.toast');
let toastTimer;
const currentPage = document.body.dataset.page || 'home';

nav?.querySelectorAll('[data-page]').forEach((link) => {
  const isCurrentPage = link.dataset.page === currentPage;
  link.classList.toggle('active', isCurrentPage);
  if (isCurrentPage) link.setAttribute('aria-current', 'page');
  else link.removeAttribute('aria-current');
});

const cubeCursor = document.querySelector('.cube-cursor');
if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
  document.body.classList.add('custom-cursor-enabled');
  window.addEventListener('pointermove', (event) => {
    cubeCursor.style.left = `${event.clientX}px`;
    cubeCursor.style.top = `${event.clientY}px`;
    cubeCursor.classList.add('visible');
  });
  document.addEventListener('pointerout', (event) => {
    if (!event.relatedTarget) cubeCursor.classList.remove('visible');
  });
}

menuToggle?.addEventListener('click', () => {
  const isOpen = nav.classList.toggle('open');
  menuToggle.setAttribute('aria-expanded', String(isOpen));
  menuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
});

nav?.addEventListener('click', (event) => {
  if (event.target.closest('a')) {
    nav.classList.remove('open');
    menuToggle?.setAttribute('aria-expanded', 'false');
    menuToggle?.setAttribute('aria-label', 'Open menu');
  }
});

document.querySelectorAll('[data-platform]').forEach((tab) => {
  tab.addEventListener('click', () => {
    const platform = tab.dataset.platform;
    document.querySelectorAll('[data-platform]').forEach((item) => {
      const selected = item === tab;
      item.classList.toggle('selected', selected);
      item.setAttribute('aria-selected', String(selected));
    });
    document.querySelectorAll('[data-game-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.gamePanel !== platform;
    });
  });
});

document.querySelectorAll('[data-modal]').forEach((trigger) => {
  trigger.addEventListener('click', (event) => {
    const dialog = document.getElementById(trigger.dataset.modal);
    if (!dialog) return;
    event.preventDefault();
    dialog.showModal();
  });
});

document.querySelectorAll('.site-modal').forEach((dialog) => {
  dialog.querySelector('.modal-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
});

document.querySelectorAll('[data-preview-form]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    form.closest('dialog')?.close();
    form.reset();
    if (!toast) return;
    toast.textContent = 'Preview only: this message was not sent. Contact services are not connected yet.';
    toast.classList.add('show');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.classList.remove('show'), 4200);
  });
});

document.querySelectorAll('[data-current-year]').forEach((year) => {
  year.textContent = new Date().getFullYear();
});
}
