(function (Drupal, once) {

  Drupal.behaviors.neoAlchemistLibrarySearch = {
    attach: function () {
      once('neo.alchemist.library-search', '.neo-alchemist-library-search').forEach(input => {
        const container = input.closest('form, .neo-alchemist-library-form')?.parentElement;
        if (!container) {
          return;
        }
        const library = container.querySelector('.neo-alchemist-library') as HTMLElement;
        if (!library) {
          return;
        }
        const groups = library.querySelectorAll('.neo-alchemist-library-group') as NodeListOf<HTMLElement>;
        const components = library.querySelectorAll('.neo-alchemist-library-component') as NodeListOf<HTMLElement>;

        (input as HTMLInputElement).addEventListener('input', function () {
          const query = this.value.toLowerCase().trim();

          if (!query) {
            components.forEach(component => {
              component.style.display = '';
            });
            groups.forEach(group => {
              group.style.display = '';
            });
            return;
          }

          components.forEach(component => {
            const label = component.getAttribute('data-component-label') || '';
            component.style.display = label.includes(query) ? '' : 'none';
          });

          groups.forEach(group => {
            const visibleComponents = group.querySelectorAll('.neo-alchemist-library-component:not([style*="display: none"])');
            group.style.display = visibleComponents.length ? '' : 'none';
          });
        });
      });
    }
  };

})(Drupal, once);
