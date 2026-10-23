(function (global) {
  'use strict';

  let controlId = 0;

  function positiveInteger(value, fallback) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : fallback;
  }

  /** Paginate existing directory elements without changing their search/filter state. */
  class DirectoryPagination {
    constructor({ items = [], controls, pageSize = 10, pageSizes = [10, 25, 50], label = 'items', onChange } = {}) {
      if (!controls || !controls.ownerDocument) {
        throw new TypeError('DirectoryPagination requires a controls element.');
      }

      this.items = Array.from(new Set(items));
      this.filteredItems = this.items.slice();
      this.controls = controls;
      this.pageSize = positiveInteger(pageSize, 10);
      this.currentPage = 1;
      this.label = String(label);
      this.onChange = typeof onChange === 'function' ? onChange : null;
      this.pageSizes = Array.from(new Set([
        ...Array.from(pageSizes).map(value => positiveInteger(value, 0)).filter(Boolean),
        this.pageSize
      ])).sort((a, b) => a - b);

      this.buildControls();
      this.render();
    }

    buildControls() {
      const doc = this.controls.ownerDocument;
      const title = this.label.charAt(0).toUpperCase() + this.label.slice(1);
      const create = (tag, className, text) => {
        const element = doc.createElement(tag);
        element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
      };

      this.controls.classList.add('directory-pagination');
      this.controls.setAttribute('role', 'navigation');
      this.controls.setAttribute('aria-label', title + ' pagination');

      this.status = create('p', 'directory-pagination-status');
      this.status.setAttribute('aria-live', 'polite');
      this.status.setAttribute('aria-atomic', 'true');

      const actions = create('div', 'directory-pagination-actions');
      const sizeLabel = create('label', 'directory-page-size');
      const sizeLabelText = create('span', '', title + ' per page');
      this.sizeSelect = create('select', 'directory-select');
      this.sizeSelect.id = 'directoryPageSize' + (++controlId);
      sizeLabel.htmlFor = this.sizeSelect.id;
      this.pageSizes.forEach(size => {
        const option = create('option', '', String(size));
        option.value = String(size);
        this.sizeSelect.appendChild(option);
      });
      this.sizeSelect.value = String(this.pageSize);
      sizeLabel.append(sizeLabelText, this.sizeSelect);

      const pages = create('div', 'directory-pagination-pages');
      pages.setAttribute('role', 'group');
      pages.setAttribute('aria-label', 'Page navigation');
      this.previousButton = create('button', 'directory-button directory-page-button', 'Previous');
      this.previousButton.type = 'button';
      this.previousButton.setAttribute('aria-label', 'Previous page');
      this.numberButtons = create('span', 'directory-page-numbers');
      this.nextButton = create('button', 'directory-button directory-page-button', 'Next');
      this.nextButton.type = 'button';
      this.nextButton.setAttribute('aria-label', 'Next page');
      pages.append(this.previousButton, this.numberButtons, this.nextButton);
      actions.append(sizeLabel, pages);
      this.controls.replaceChildren(this.status, actions);

      // Delegate once so changing the numeric buttons never adds more listeners.
      this.controls.addEventListener('click', event => {
        const button = event.target.closest('button[data-directory-page]');
        if (button && this.controls.contains(button) && !button.disabled) {
          this.setPage(Number(button.dataset.directoryPage));
        }
      });
      this.sizeSelect.addEventListener('change', () => {
        const firstItemIndex = (this.currentPage - 1) * this.pageSize;
        this.pageSize = positiveInteger(this.sizeSelect.value, this.pageSize);
        this.currentPage = Math.floor(firstItemIndex / this.pageSize) + 1;
        this.render();
      });
    }

    setItems(filteredItems, { reset = true } = {}) {
      const knownItems = new Set(this.items);
      this.filteredItems = Array.from(new Set(filteredItems)).filter(item => knownItems.has(item));
      if (reset) this.currentPage = 1;
      this.render();
    }

    setPage(page) {
      const requestedPage = Number(page);
      this.currentPage = Number.isFinite(requestedPage) ? Math.trunc(requestedPage) : 1;
      this.render();
    }

    getVisibleItems() {
      const start = (this.currentPage - 1) * this.pageSize;
      return this.filteredItems.slice(start, start + this.pageSize);
    }

    render() {
      const count = this.filteredItems.length;
      const totalPages = Math.max(1, Math.ceil(count / this.pageSize));
      this.currentPage = Math.max(1, Math.min(this.currentPage, totalPages));
      const visibleItems = this.getVisibleItems();
      const visibleSet = new Set(visibleItems);
      this.items.forEach(item => item.classList.toggle('directory-page-hidden', !visibleSet.has(item)));

      const first = count ? (this.currentPage - 1) * this.pageSize + 1 : 0;
      const last = count ? first + visibleItems.length - 1 : 0;
      this.status.textContent = count
        ? 'Showing ' + first + '\u2013' + last + ' of ' + count + ' ' + this.label
        : 'Showing 0 of 0 ' + this.label;
      this.sizeSelect.value = String(this.pageSize);
      this.previousButton.disabled = this.currentPage === 1;
      this.previousButton.dataset.directoryPage = String(this.currentPage - 1);
      this.nextButton.disabled = this.currentPage === totalPages;
      this.nextButton.dataset.directoryPage = String(this.currentPage + 1);

      const pageNumbers = [];
      if (totalPages <= 7) {
        for (let page = 1; page <= totalPages; page++) pageNumbers.push(page);
      } else {
        const firstMiddlePage = Math.max(2, Math.min(this.currentPage - 2, totalPages - 5));
        pageNumbers.push(1);
        for (let page = firstMiddlePage; page < firstMiddlePage + 5; page++) pageNumbers.push(page);
        pageNumbers.push(totalPages);
      }

      const doc = this.controls.ownerDocument;
      const restorePageFocus = doc.activeElement && this.numberButtons.contains(doc.activeElement);
      let currentPageButton = null;
      const fragment = doc.createDocumentFragment();
      pageNumbers.forEach((page, index) => {
        if (index > 0 && page > pageNumbers[index - 1] + 1) {
          const ellipsis = doc.createElement('span');
          ellipsis.className = 'directory-pagination-ellipsis';
          ellipsis.textContent = '\u2026';
          ellipsis.setAttribute('aria-hidden', 'true');
          fragment.appendChild(ellipsis);
        }
        const button = doc.createElement('button');
        button.type = 'button';
        button.className = 'directory-button directory-page-button';
        button.textContent = String(page);
        button.dataset.directoryPage = String(page);
        button.setAttribute('aria-label', 'Page ' + page);
        if (page === this.currentPage) {
          button.setAttribute('aria-current', 'page');
          currentPageButton = button;
        }
        fragment.appendChild(button);
      });
      this.numberButtons.replaceChildren(fragment);
      if (restorePageFocus && currentPageButton) currentPageButton.focus({ preventScroll: true });
      if (this.onChange) this.onChange(visibleItems);
    }
  }

  global.DirectoryPagination = DirectoryPagination;
})(window);
