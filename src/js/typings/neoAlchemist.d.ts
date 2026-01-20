declare namespace drupal {

  export interface IDrupalStatic {
    neoAlchemist?: {
      iframes?: Record<'desktop' | 'tablet' | 'mobile', HTMLIFrameElement | null>
    };
  }
}
