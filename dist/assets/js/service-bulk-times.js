(() => {
  const form = document.getElementById('bulkServiceTimesForm');
  if (!form) return;

  const department = document.getElementById('bulkTimeDepartment');
  const minutes = document.getElementById('bulkTimeMinutes');
  const options = document.getElementById('bulkTimeServiceOptions');
  const selectAll = document.getElementById('bulkTimeSelectAll');
  const countLabel = document.getElementById('bulkTimeSelectedCount');
  const emptyLabel = document.getElementById('bulkTimeEmpty');
  const previewButton = document.getElementById('bulkTimePreviewButton');
  const applyButton = document.getElementById('bulkTimeApplyButton');
  const limit = Number(form.dataset.limit);
  const unavailable = form.dataset.unavailable === 'true';
  let previewDirty = Boolean(applyButton?.disabled);

  const visibleBoxes = () => [...options.querySelectorAll('input[name="service_ids[]"]')].filter((checkbox) => !checkbox.disabled);
  const invalidatePreview = () => {
    if (!applyButton) return;
    previewDirty = true;
    applyButton.disabled = true;
    document.getElementById('bulkTimePreviewChanged').hidden = false;
  };
  const updateSelection = () => {
    const boxes = visibleBoxes();
    const selected = boxes.filter((checkbox) => checkbox.checked).length;
    selectAll.disabled = boxes.length === 0 || unavailable;
    selectAll.checked = boxes.length > 0 && selected === boxes.length;
    selectAll.indeterminate = selected > 0 && selected < boxes.length;
    countLabel.textContent = selected > limit ? `Select up to ${limit} services per batch.` : `${selected} selected`;
    previewButton.disabled = unavailable || selected === 0 || selected > limit;
  };
  const updateDepartment = (clearSelection) => {
    options.querySelectorAll('[data-department]').forEach((option) => {
      const visible = Boolean(department.value) && option.dataset.department === department.value;
      const checkbox = option.querySelector('input');
      option.hidden = !visible;
      checkbox.disabled = !visible || unavailable;
      if (!visible || clearSelection) checkbox.checked = false;
    });
    emptyLabel.hidden = visibleBoxes().length > 0;
    if (!emptyLabel.hidden) {
      emptyLabel.textContent = department.value ? 'No services are available in this department.' : 'Choose a department to select services.';
    }
    updateSelection();
  };

  department.addEventListener('change', () => {
    updateDepartment(true);
    invalidatePreview();
  });
  minutes.addEventListener('input', invalidatePreview);
  options.addEventListener('change', () => {
    updateSelection();
    invalidatePreview();
  });
  selectAll.addEventListener('change', () => {
    visibleBoxes().forEach((checkbox) => { checkbox.checked = selectAll.checked; });
    updateSelection();
    invalidatePreview();
  });
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    previewButton.disabled = true;
    previewButton.textContent = 'Preparing preview...';
  });
  document.getElementById('bulkTimeApplyForm')?.addEventListener('submit', (event) => {
    if (!event.defaultPrevented && event.submitter?.value === 'apply_times') applyButton.textContent = 'Applying changes...';
  });
  window.addEventListener('pageshow', () => {
    previewButton.textContent = 'Preview changes';
    if (applyButton) {
      applyButton.disabled = previewDirty;
      applyButton.textContent = 'Apply changes';
    }
    updateDepartment(false);
  });
  updateDepartment(false);
})();
