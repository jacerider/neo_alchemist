(function (Drupal, once) {

  function debounce<T extends (...args: any[]) => void>(func: T, delay: number): T {
    let timeoutId: ReturnType<typeof setTimeout>|null;
    return function (this: any, ...args: any[]) {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
      timeoutId = setTimeout(() => {
        func.apply(this, args);
      }, delay);
    } as T;
  }

  function handleRefresh() {
    const form = jQuery('#neo-alchemist--instance-component-form') as any;
    if (Drupal.Ajax) {
      // Clear the form id so that the form is not submitted again.
      let url = form.attr('action');
      if (!url) {
        return;
      }
      if (url.includes('?')) {
        url += '&';
      }
      else {
        url += '?';
      }
      url += 'ajax_form=1';
      formBuildId = null;
      const options = {
        callback: '::ajaxRefresh',
        dialogType: 'ajax',
        event: 'none',
        httpMethod: 'POST',
        keypress: true,
        selector: '#neo-alchemist--refresh',
        submit: {
          js: true,
          _triggering_element_name: 'op',
          _triggering_element_value: 'Refresh',
        },
        url: url,
      };
      const ajax = Drupal.ajax(options) as any;
      ajax.element = jQuery('<div>')[0];
      ajax.$form = form;
      form.ajaxSubmit(ajax.options);
    }
  }

  const throttledInput = debounce(handleRefresh, 250);
  const formId = 'neo-alchemist--instance-component-form';
  // The form instance the build id below belongs to. Both are per-form state,
  // but this module outlives any one form: every component opens its own edit
  // form into the same modal, so without tracking which form the id came from,
  // the previous component's id is still sitting here when the next one mounts.
  let formElement = null as HTMLElement|null;
  let formBuildId = null as string|null;

  Drupal.behaviors.neoAlchemistInstanceComponentAjaxForm = {
    attach: function () {
      // Watch autocomplete.
      once('neo.alchemist', '#' + formId + ' [data-autocomplete-path]').forEach(el => {
        jQuery(el).on('autocompleteselect', function (_e) {
          throttledInput();
        });
      });
      // Draggable.
      once('neo.alchemist', '#' + formId + ' .neo-alchemist-draggable-list').forEach(el => {
        function updateWeights(list: HTMLElement) {
          const items = Array.from(list.querySelectorAll<HTMLElement>('.neo-alchemist-draggable-item'));
          items.forEach((item, idx) => {
            // Try select or input inside the .neo-alchemist-draggable-weight element.
            const weightInput = item.querySelector<HTMLElement>('.neo-alchemist-draggable-weight');
            if (weightInput) {
              try {
                const valueStr = String(idx);
                if (weightInput instanceof HTMLInputElement) {
                  // Update property and attribute for inputs, then notify listeners.
                  weightInput.value = valueStr;
                  weightInput.setAttribute('value', valueStr);
                  weightInput.dispatchEvent(new Event('input', { bubbles: true }));
                  weightInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                else if (weightInput instanceof HTMLSelectElement) {
                  // Prefer selecting an option that matches the index; otherwise select closest index.
                  if (Array.from(weightInput.options).some(o => o.value === valueStr)) {
                    weightInput.value = valueStr;
                  }
                  else {
                    const optIndex = Math.max(0, Math.min(idx, weightInput.options.length - 1));
                    weightInput.selectedIndex = optIndex;
                  }
                  weightInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
              }
              catch (e) {
                // ignore exceptions from setting values on unexpected inputs
              }
            }
          });
        }

        // Create a simple draggable implementation using pointer events.
        el.querySelectorAll<HTMLElement>('.neo-alchemist-draggable-handle').forEach(handle => {
          let dragging = false;
          let draggedItem: HTMLElement | null = null;
          let placeholder: HTMLElement | null = null;
          let clone: HTMLElement | null = null;
          let startY = 0;
          let offsetY = 0;
          let hasMoved = false;
          let clickSuppressed = false;

          // Prevent clicks on the handle from toggling the parent <details> when a drag occurred.
          handle.addEventListener('click', (e: MouseEvent) => {
            if (hasMoved || clickSuppressed) {
              e.preventDefault();
              e.stopPropagation();
            }
          }, true);

          handle.addEventListener('pointerdown', (e: PointerEvent) => {
            // Only left click or touch
            if (e.button && e.button !== 0) return;
            const targetItem = handle.closest('.neo-alchemist-draggable-item') as HTMLElement | null;
            if (!targetItem) return;

            e.preventDefault();
            (e.target as Element).setPointerCapture(e.pointerId);
            dragging = true;
            draggedItem = targetItem;
            hasMoved = false;
            clickSuppressed = false;

            const rect = targetItem.getBoundingClientRect();
            startY = e.clientY;
            offsetY = startY - rect.top;

            // Placeholder
            placeholder = document.createElement('div');
            placeholder.className = 'neo-alchemist-draggable-placeholder';
            placeholder.style.height = rect.height + 'px';
            placeholder.style.width = rect.width + 'px';
            placeholder.style.boxSizing = 'border-box';
            placeholder.style.margin = getComputedStyle(targetItem).margin;

            targetItem.parentNode?.insertBefore(placeholder, targetItem);

            // Clone for dragging visuals. Clone keeps appearance but not active form state.
            clone = targetItem.cloneNode(true) as HTMLElement;
            clone.classList.add('neo-alchemist-draggable-dragging');
            clone.style.position = 'fixed';
            clone.style.left = rect.left + 'px';
            clone.style.top = rect.top + 'px';
            clone.style.width = rect.width + 'px';
            clone.style.pointerEvents = 'none';
            clone.style.zIndex = '9999';
            targetItem.closest('form')?.appendChild(clone);

            // Hide original item while dragging so form inputs remain in place but are not visible twice.
            targetItem.style.display = 'none';

            const onPointerMove = (ev: PointerEvent) => {
              if (!dragging || !clone || !placeholder) return;
              ev.preventDefault();

              const clientY = ev.clientY;

              // Mark as moved if we exceed a small threshold so simple clicks still toggle details.
              if (!hasMoved && Math.abs(clientY - startY) > 5) {
                hasMoved = true;
              }

              clone.style.top = (clientY - offsetY) + 'px';

              // Determine insertion point based on midlines of items in list.
              const listItems = Array.from(el.querySelectorAll<HTMLElement>('.neo-alchemist-draggable-item'))
                .filter(i => i !== draggedItem);

              let inserted = false;
              for (const it of listItems) {
                const r = it.getBoundingClientRect();
                const midpoint = r.top + r.height / 2;
                if (clientY < midpoint) {
                  if (placeholder.parentNode !== it.parentNode || placeholder.nextSibling !== it) {
                    it.parentNode?.insertBefore(placeholder, it);
                  }
                  inserted = true;
                  break;
                }
              }
              if (!inserted) {
                // put at end
                el.appendChild(placeholder);
              }

              // Auto-scroll if near edges
              const scrollMargin = 40;
              const listRect = el.getBoundingClientRect();
              if (clientY - listRect.top < scrollMargin) {
                el.scrollTop -= 10;
              }
              else if (listRect.bottom - clientY < scrollMargin) {
                el.scrollTop += 10;
              }
            };

            const onPointerUp = (ev: PointerEvent) => {
              if (!dragging) return;
              ev.preventDefault();
              dragging = false;

              // Place the original item where placeholder is
              if (placeholder && draggedItem) {
                if (placeholder.parentNode) {
                  placeholder.parentNode.insertBefore(draggedItem, placeholder);
                  placeholder.remove();
                }
                // restore display
                draggedItem.style.display = '';

                // Clean up clone
                if (clone && clone.parentNode) {
                  clone.parentNode.removeChild(clone);
                }

                // Update weights and refresh
                updateWeights(el as HTMLElement);
                throttledInput();
              }

              // If we detected movement, suppress the following click that would toggle a parent <details>
              if (hasMoved) {
                clickSuppressed = true;
                // Clear suppression on next tick so real clicks still work.
                setTimeout(() => { clickSuppressed = false; hasMoved = false; }, 0);
              }

              // Remove listeners
              document.removeEventListener('pointermove', onPointerMove);
              document.removeEventListener('pointerup', onPointerUp);
            };

            document.addEventListener('pointermove', onPointerMove);
            document.addEventListener('pointerup', onPointerUp);
          });
        });
      });
      once('neo.alchemist', '#' + formId).forEach(el => {
        if (Drupal.CKEditor5Instances) {
          setTimeout(() => {
            if (Drupal.CKEditor5Instances.size) {
              Drupal.CKEditor5Instances.forEach((editor) => {
                editor.model.document.on( 'change:data', () => {
                  throttledInput();
                });
              });
            }
          });
        }
        el.addEventListener('input', function (e) {
          if (e.target instanceof HTMLElement) {
            if (e.target.dataset.autocompletePath) {
              return;
            }
            if (e.target.dataset.once && e.target.dataset.once.includes('drupal-ajax')) {
              return;
            }
            else {
              throttledInput();
            }
          }
        });
      });
      // Process form on each request.
      //
      // A build id that changed while the form stayed put means the form was
      // rebuilt, and the preview has to catch up — that is what the refresh is
      // for. A build id that changed because this is a different form entirely
      // means nothing of the sort: a newly opened component simply has its own,
      // and comparing it against the last component's fires a refresh for a
      // form nobody has touched. That cost an extra POST plus a reload of all
      // three preview frames on every component opened after the first.
      const form = document.getElementById(formId) as HTMLElement;
      if (form) {
        if (form !== formElement) {
          formElement = form;
          formBuildId = null;
        }
        const el = form.querySelector('input[name="form_build_id"]') as HTMLInputElement;
        if (el && el.value !== formBuildId) {
          if (formBuildId) {
            throttledInput();
          }
          formBuildId = el.value;
        }
      }
    }
  };

})(Drupal, once);
