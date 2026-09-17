interface IdiomorphCallbacks {
  // Returning false from a `before` callback cancels that operation, which is
  // how `data-once` survives a morph — see refreshPreview() in
  // component-child.ts.
  beforeNodeAdded?: (node: Node) => boolean | void;
  afterNodeAdded?: (node: Node) => void;
  beforeNodeMorphed?: (oldNode: Node, newNode: Node) => boolean | void;
  afterNodeMorphed?: (oldNode: Node, newNode: Node) => void;
  beforeNodeRemoved?: (node: Node) => boolean | void;
  afterNodeRemoved?: (node: Node) => void;
  beforeAttributeUpdated?: (
    attributeName: string,
    node: Element,
    mutationType: 'update' | 'remove',
  ) => boolean | void;
}

interface IdiomorphOptions {
  morphStyle?: 'outerHTML' | 'innerHTML';
  ignoreActive?: boolean;
  ignoreActiveValue?: boolean;
  restoreFocus?: boolean;
  head?: { style?: 'merge' | 'append' | 'morph' | 'none' };
  callbacks?: IdiomorphCallbacks;
}

declare var Idiomorph: {
  morph(
    oldNode: Element,
    newContent: Element | Node | string,
    options?: IdiomorphOptions,
  ): Node[] | undefined;
  defaults: IdiomorphOptions;
};
