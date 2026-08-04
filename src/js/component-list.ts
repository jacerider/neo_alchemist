(function (Drupal, once) {

  const HIDDEN = 'hidden';

  Drupal.behaviors.neoAlchemistListFilter = {
    attach: function () {
      once('neo.alchemist.list-filter', '.neo-alchemist-list-search').forEach(element => {
        const input = element as HTMLInputElement;
        const page = input.closest('.neo-alchemist-list-filter')?.parentElement;
        if (!page) {
          return;
        }

        const rows = Array.from(page.querySelectorAll('tr[data-component-search]')) as HTMLElement[];
        const subgroups = Array.from(page.querySelectorAll('.neo-alchemist-list-subgroup')) as HTMLElement[];
        const groups = Array.from(page.querySelectorAll('.neo-alchemist-list-group')) as HTMLElement[];
        const navItems = Array.from(page.querySelectorAll('.neo-alchemist-list-nav-item')) as HTMLElement[];
        const empty = page.querySelector('.neo-alchemist-list-empty') as HTMLElement | null;

        // A group that renders sub-groups is a container, not a table, so
        // "has visible rows" has to look through whatever is nested inside.
        const visibleRowCount = (element: HTMLElement): number => {
          return element.querySelectorAll(`tr[data-component-search]:not(.${HIDDEN})`).length;
        };

        const toggle = (element: HTMLElement, visible: boolean): void => {
          element.classList.toggle(HIDDEN, !visible);
        };

        const apply = (): void => {
          const query = input.value.toLowerCase().trim();

          rows.forEach(row => {
            const haystack = row.getAttribute('data-component-search') || '';
            toggle(row, !query || haystack.includes(query));
          });
          subgroups.forEach(subgroup => toggle(subgroup, visibleRowCount(subgroup) > 0));
          groups.forEach(group => toggle(group, visibleRowCount(group) > 0));

          let total = 0;
          navItems.forEach(item => {
            const target = item.getAttribute('data-nav-target');
            const section = target ? page.querySelector(`#${CSS.escape(target)}`) as HTMLElement | null : null;
            const count = section ? visibleRowCount(section) : 0;
            const counter = item.querySelector('.neo-alchemist-list-nav-count');
            if (counter) {
              counter.textContent = String(count);
            }
            toggle(item, count > 0);
            total += count;
          });

          if (empty) {
            toggle(empty, total === 0);
          }
        };

        input.addEventListener('input', apply);

        // The source-component badge on each row is a shortcut into the
        // filter: it surfaces every component built from the same SDC.
        page.querySelectorAll('[data-component-filter]').forEach(trigger => {
          trigger.addEventListener('click', function (this: HTMLElement, event: Event) {
            event.preventDefault();
            input.value = this.getAttribute('data-component-filter') || '';
            apply();
            input.focus();
          });
        });

        apply();
      });
    }
  };

})(Drupal, once);
