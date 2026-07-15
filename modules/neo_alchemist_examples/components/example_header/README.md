# Example | Header

Reference implementation of the Alchemist **mega menu** pattern:

- The `menu` prop is the Alchemist `menu` shape. In a real placement, bind it
  to the **"menu" value provider** with depth 3 so a Drupal menu populates it:
  level 1 → nav items, level 2 → panel columns, level 3 → column links.
- Items with children open a full-width panel **on click** (Alpine via
  `libraryOverrides: [neo/library.alpine]`), with `aria-expanded` on the
  trigger and outside-click/Escape to close. The component `.css` ships the
  `[x-cloak]` pre-init guard.
- With the **neo_alchemist_menu** submodule enabled, an editor can add a
  *component region* menu item under a nav item; it arrives here as runtime
  item keys `region: true` + `content` (a render array of the item's component
  tree) and is printed inside the panel instead of a link. Without the
  submodule the component still works — regions simply never appear.

See the `neo-alchemist-menu` skill for the full feature guide.
