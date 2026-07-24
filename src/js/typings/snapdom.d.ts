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
}

declare var snapdom: {
  (el: HTMLElement, options?: SnapdomOptions): Promise<any>;
  toCanvas(el: HTMLElement, options?: SnapdomOptions): Promise<HTMLCanvasElement>;
  toBlob(el: HTMLElement, options?: SnapdomOptions & { type?: string }): Promise<Blob>;
  toPng(el: HTMLElement, options?: SnapdomOptions): Promise<HTMLImageElement>;
};
