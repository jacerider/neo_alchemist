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
  let formBuildId = null as string|null;

  Drupal.behaviors.neoAlchemistInstanceComponentAjaxForm = {
    attach: function () {
      // Watch autocomplete.
      once('neo.alchemist', '#' + formId + ' [data-autocomplete-path]').forEach(el => {
        jQuery(el).on('autocompleteselect', function (_e) {
          throttledInput();
        });
      });
      once('neo.alchemist', '#' + formId).forEach(el => {
        if (Drupal.CKEditor5Instances) {
          setTimeout(() => {
            if (Drupal.CKEditor5Instances.size) {
              Drupal.CKEditor5Instances.forEach((editor) => {
                // console.log(editor.ui.view);
                // editor.ui.view.element.style.maxHeight = '200px';
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
      const form = document.getElementById(formId) as HTMLElement;
      if (form) {
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
