(() => {
  const sidebar = document.getElementById('queueSidebar');
  const openButton = document.getElementById('queueSidebarOpen');
  const closeButton = document.getElementById('queueSidebarClose');
  const backdrop = document.getElementById('queueSidebarBackdrop');
  const main = document.querySelector('.queue-main');
  if (!sidebar || !openButton || !closeButton || !backdrop) return;

  const mobile = window.matchMedia('(max-width: 900px)');
  const setOpen = (open, restoreFocus = false) => {
    const expanded = mobile.matches && open;
    sidebar.classList.toggle('is-open', expanded);
    document.body.classList.toggle('queue-sidebar-open', expanded);
    openButton.setAttribute('aria-expanded', String(expanded));
    backdrop.hidden = !expanded;
    // Hidden navigation must not receive focus while it is off screen.
    sidebar.inert = mobile.matches && !expanded;
    if (main) main.inert = expanded;
    if (expanded) closeButton.focus();
    else if (restoreFocus && mobile.matches) openButton.focus();
  };

  openButton.addEventListener('click', () => setOpen(true));
  closeButton.addEventListener('click', () => setOpen(false, true));
  backdrop.addEventListener('click', () => setOpen(false, true));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar.classList.contains('is-open')) setOpen(false, true);
  });
  sidebar.querySelectorAll('.queue-nav a').forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });
  mobile.addEventListener('change', () => setOpen(false));
  setOpen(false);
})();
