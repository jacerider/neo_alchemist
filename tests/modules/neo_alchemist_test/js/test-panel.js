/**
 * @file
 * The client half of the TestLabelPanel fixture.
 */

((Drupal) => {
  Drupal.neoAlchemist.panels.register('na_test_label', {
    mount(element, api) {
      element.innerHTML =
        '<form class="na-test-label-form flex flex-col gap-2 p-3 text-xs">' +
        '<label>Label <input name="label" class="form-element w-full"></label>' +
        '<button type="submit" class="btn btn-xs btn-primary">Draft label</button>' +
        '<output class="na-test-label-status"></output>' +
        '</form>';
      const form = element.querySelector('form');
      const input = form.querySelector('input');
      const status = form.querySelector('output');
      const load = () =>
        api.get().then((state) => {
          input.value = (state.data && state.data.label) || '';
          status.textContent = `Draft v${state.version}`;
        });
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        api
          .set({ label: input.value })
          .then((result) => {
            status.textContent = `Drafted v${result.version}`;
          })
          .catch((error) => {
            status.textContent = error.message;
          });
      });
      api.onDraftChange((change) => {
        status.textContent = `Draft changed (${change.reason}) v${change.version}`;
      });
      load();
    },
    badge(element, api, dropdown) {
      let heard = 0;
      dropdown.element.innerHTML = '<p class="na-test-label-heard p-3">0</p>' +
        '<button type="button" class="na-test-label-focus btn btn-xs">Focus first component</button>';
      // Any caller may use the editor's public focus call.
      dropdown.element.querySelector('.na-test-label-focus').addEventListener('click', () => {
        const frame = Drupal.neoAlchemist.iframes.desktop;
        const first = frame && frame.contentDocument.querySelector('[data-component-uuid]');
        const focused = first ? Drupal.neoAlchemist.editor.focus({ uuid: first.dataset.componentUuid }) : false;
        element.dataset.focused = String(focused);
      });
      api.onDraftChange((change) => {
        heard += 1;
        dropdown.element.querySelector('p').textContent = `${heard} (${change.reason})`;
      });
      element.dataset.badgeReady = 'true';
    },
  });
})(Drupal);
