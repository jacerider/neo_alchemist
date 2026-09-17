(function (Drupal, once, drupalSettings) {

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

  // The two DOM ids this behavior matches on. The server owns both — see
  // ComponentValuePanelBuilder::attachClient(), which publishes them with the
  // library this file ships in — so there is no literal here to drift from it.
  // They are read on every attach rather than once at load because an AJAX
  // rebuild can bring settings with it.
  let formId = '';
  let refreshId = '';

  /**
   * Renumbers an array prop's rows to the order they are sitting in.
   *
   * The server does not restate the rows on a drop: ArrayShape sorts on submit
   * ("sorting only changes the order of elements in the dom" —
   * ArrayShape::massageFinalValues()), so until the next full rebuild the
   * panes keep the deltas they were built with while the preview, which
   * re-renders from the sorted values, renumbers from zero. Preview and form
   * address a field by the same shape id, so the moment those two numbers
   * disagree a click in the preview opens whichever row happens to hold that
   * delta in the form — drag the second card to the top and its text opens the
   * first card.
   *
   * The visual order is the one both sides agree on, and the client already
   * writes it into the row weights. Writing it into the ids as well keeps the
   * two vocabularies in step until the server restates them.
   *
   * Idempotent, and run on every attach rather than only on a drop, because a
   * partial rebuild re-stamps the server's build-time delta into whatever
   * subtree it replaces: toggling Hide on a field of a row that has been
   * dragged would otherwise hand that one row a stale id again, colliding with
   * whichever row genuinely holds that delta.
   */
  function renumberDraggableList(list: HTMLElement) {
    // A shape id is its parent's id, its own name, then its delta if it has
    // one (ComponentShapePluginBase::id()), so a row's children read
    // `<array>~<child>~<delta>` and anything below them keeps that delta
    // mid-path — `items~heading~1~title`. The delta therefore always lands one
    // segment past the array's own id, whatever the array is nested in, and a
    // list nested inside a row has its own delta further along that this
    // leaves alone. With no wrapper to measure from, the ids are left as they
    // are rather than guessed at.
    const arrayId = list.closest<HTMLElement>('[data-neo-prop]')?.dataset.neoProp;
    const deltaIndex = arrayId ? arrayId.split('~').length + 1 : null;
    const items = Array.from(list.querySelectorAll<HTMLElement>('.neo-alchemist-draggable-item'));
    items.forEach((item, idx) => {
      if (deltaIndex !== null) {
        item.querySelectorAll<HTMLElement>('[data-neo-prop]').forEach(shape => {
          const parts = (shape.dataset.neoProp || '').split('~');
          // Only a segment that is already a delta is rewritten: a shape that
          // carries none has some other name in that seat.
          if (/^\d+$/.test(parts[deltaIndex] ?? '')) {
            parts[deltaIndex] = String(idx);
            shape.dataset.neoProp = parts.join('~');
          }
        });
      }
      // The row's label is `@label @delta` counted from one — positional, not
      // a name — so it is stale the moment the row moves. Replacing the
      // trailing number leaves a label of any wording or length alone.
      const label = item.querySelector<HTMLElement>('.details--title');
      if (label) {
        label.textContent = (label.textContent || '').replace(/\d+(?!.*\d)/, String(idx + 1));
      }
    });
  }

  function handleRefresh() {
    const form = jQuery('#' + formId) as any;
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
        selector: '#' + refreshId,
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
  // The form instance the build id below belongs to. Both are per-form state,
  // but this module outlives any one form: every component opens its own edit
  // form into the same modal, so without tracking which form the id came from,
  // the previous component's id is still sitting here when the next one mounts.
  let formElement = null as HTMLElement|null;
  let formBuildId = null as string|null;

  Drupal.behaviors.neoAlchemistInstanceComponentAjaxForm = {
    attach: function () {
      const contract = drupalSettings.neoAlchemist?.valueEditor;
      if (!contract?.formId || !contract?.refreshId) {
        // No value editor on this page. The server publishes both ids with
        // this behavior's library, so their absence is that and not a fault.
        return;
      }
      formId = contract.formId;
      refreshId = contract.refreshId;

      // Watch autocomplete.
      once('neo.alchemist', '#' + formId + ' [data-autocomplete-path]').forEach(el => {
        jQuery(el).on('autocompleteselect', function (_e) {
          throttledInput();
        });
      });
      // Outside the `once` below, and not scoped to `context`: a partial
      // rebuild replaces a subtree *inside* a list and attaches behaviors to
      // that subtree alone, so the list itself is never in context and the
      // `once` never fires again. Re-asserting every list here is what heals
      // the stale delta such a replace stamps back in.
      document.querySelectorAll<HTMLElement>('#' + formId + ' .neo-alchemist-draggable-list')
        .forEach(list => renumberDraggableList(list));

      // Draggable.
      once('neo.alchemist', '#' + formId + ' .neo-alchemist-draggable-list').forEach(el => {
        function updateWeights(list: HTMLElement) {
          renumberDraggableList(list);
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

})(Drupal, once, drupalSettings);
