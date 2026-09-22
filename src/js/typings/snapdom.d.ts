// A snapdom lifecycle plugin. Only the hook neo_alchemist actually uses is
// declared; snapdom dispatches several more (beforeSnap, beforeClone,
// beforeRender, beforeExport) against the same context object.
interface SnapdomPlugin {
  // Required: snapdom dedupes registered plugins by name.
  name: string;
  // Runs once the subtree has been cloned and before its computed styles are
  // copied across or it is serialized, with the detached clone to mutate.
  afterClone?: (context: { clone?: Element }) => void | Promise<void>;
}

interface SnapdomOptions {
  scale?: number;
  dpr?: number;
  width?: number;
  height?: number;
  embedFonts?: boolean;
  compress?: boolean;
  fast?: boolean;
  backgroundColor?: string;
  useProxy?: string;
  exclude?: string[];
  filter?: (el: Element) => boolean;
  plugins?: SnapdomPlugin[];
}

declare var snapdom: {
  (el: HTMLElement, options?: SnapdomOptions): Promise<any>;
  toCanvas(el: HTMLElement, options?: SnapdomOptions): Promise<HTMLCanvasElement>;
  toBlob(el: HTMLElement, options?: SnapdomOptions & { type?: string }): Promise<Blob>;
  toPng(el: HTMLElement, options?: SnapdomOptions): Promise<HTMLImageElement>;
};
