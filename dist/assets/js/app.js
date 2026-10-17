(() => {
  const state = { screen: 'home', department: 'Customer Service', service: 'General Inquiry', ticket: 'Q-032', position: 4, billing: 'Pending' };
  const services = { 'Customer Service': [['General Inquiry', 10], ['Account Assistance', 15], ['Document Submission', 20], ['Appointment Request', 12]], Billing: [['Payment Processing', 10], ['Billing Inquiry', 15], ['Refund Request', 20], ['Payment Verification', 8]], 'Technical Support': [['Technical Assistance', 10], ['Account Support', 20], ['Device Troubleshooting', 15], ['Service Request', 12]], Appointments: [['New Appointment', 8], ['Reschedule Appointment', 10], ['Appointment Confirmation', 5], ['Appointment Inquiry', 12]], 'Other Services': [['General Inquiry', 10], ['Document Request', 15], ['Appointment Request', 20], ['Service Status Inquiry', 12]] };
  const all = (selector) => [...document.querySelectorAll(selector)];
  const setText = (selector, value) => all(selector).forEach((element) => { element.textContent = value; });
  function render() {
    setText('#ticketNumber', state.ticket); setText('#homeTicket', state.ticket); setText('#activeDepartment', state.department);
    setText('#ticketPosition', state.position); setText('#homePosition', state.position); setText('#trackingPosition', state.position); setText('#ticketWait', `${state.position * 5} min`);
    const status = document.querySelector('#billingStatus'); if (status) { status.textContent = state.billing; status.className = `status ${state.billing === 'Paid' ? 'green' : 'amber'}`; }
  }
  function show(screen) { state.screen = screen; all('[data-screen]').forEach((element) => { const active = element.dataset.screen === screen; element.classList.toggle('active', active); element.classList.toggle('hidden-view', !active); }); const navScreen = screen === 'service' ? 'department' : screen; all('.customer-sidebar [data-go]').forEach((link) => link.classList.toggle('active', link.dataset.go === navScreen)); document.querySelector('#customerSidebar')?.classList.remove('open'); render(); }
  function updateServices() { (services[state.department] || services['Other Services']).forEach((item, index) => { const button = all('[data-screen="service"] [data-service]')[index]; if (!button) return; button.dataset.service = item[0]; const name = button.querySelector('b'); const wait = button.querySelector('small'); if (name) name.textContent = item[0]; if (wait) wait.textContent = `Estimated wait: ${item[1]} minutes`; }); }
  async function createQueue() {
    const body = new URLSearchParams({ department: state.department, service: state.service });
    try { const response = await fetch('api/join_queue.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }); const result = await response.json(); if (result.ok && result.ticket) state.ticket = result.ticket; } catch (error) { console.warn('Queue persistence unavailable.', error); }
    show('ticket');
  }
  document.addEventListener('DOMContentLoaded', () => {
    const welcome = document.querySelector('[data-screen="home"] h1');
    if (welcome && window.queuezyUserName) {
      welcome.innerHTML = 'Welcome, <span class="text-queue"></span>!';
      welcome.querySelector('span').textContent = window.queuezyUserName;
    }
    document.addEventListener('click', (event) => {
      const destination = event.target.closest('[data-go]')?.dataset.go; if (destination) { event.preventDefault(); show(destination); return; }
      const department = event.target.closest('[data-department]'); if (department) { state.department = department.dataset.department; updateServices(); show('service'); return; }
      const service = event.target.closest('[data-service]'); if (service) { state.service = service.dataset.service; createQueue(); return; }
      if (event.target.closest('[data-action="announce"]')) QueuezyAnnouncer.speak(state.ticket);
      if (event.target.closest('[data-action="paid"]')) { state.billing = 'Paid'; render(); }
      if (event.target.closest('[data-action="cancel"]') && confirm('Cancel your active queue ticket?')) { state.billing = 'Cancelled'; state.position = 0; state.ticket = 'No active ticket'; render(); show('home'); }
    });
    show('home');
  });
})();
