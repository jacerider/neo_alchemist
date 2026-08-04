(function (Drupal, once) {

  const HIDDEN = 'hidden';

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
        const subgroups = library.querySelectorAll('.neo-alchemist-library-subgroup') as NodeListOf<HTMLElement>;
        const components = library.querySelectorAll('.neo-alchemist-library-component') as NodeListOf<HTMLElement>;

        const toggle = (element: HTMLElement, visible: boolean): void => {
          element.classList.toggle(HIDDEN, !visible);
        };

        // A group may hold its components directly or nest them under
        // sub-group headings, so count through whatever is inside.
        const hasVisibleComponents = (element: HTMLElement): boolean => {
          return element.querySelectorAll(`.neo-alchemist-library-component:not(.${HIDDEN})`).length > 0;
        };

        (input as HTMLInputElement).addEventListener('input', function () {
          const query = this.value.toLowerCase().trim();

          components.forEach(component => {
            const label = component.getAttribute('data-component-label') || '';
            toggle(component, !query || label.includes(query));
          });
          subgroups.forEach(subgroup => toggle(subgroup, hasVisibleComponents(subgroup)));
          groups.forEach(group => toggle(group, hasVisibleComponents(group)));
        });
      });
    }
  };

})(Drupal, once);
