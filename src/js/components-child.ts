(function () {

  // const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');
  const components = document.querySelectorAll<HTMLElement>('[data-component]');
  const regions = document.querySelectorAll<HTMLElement>('[data-region]');
  let positionUpdateTimeout: ReturnType<typeof setTimeout> | null = null;

  window.addEventListener('message', function(e) {
    const data = e.data;
    if (typeof data.type === 'string') {
      if (typeof onParent[data.type] !== 'function') {
        return;
      }
      onParent[data.type](data);
    }
  });

  // Events called from parent iframes.
  const onParent:any = {
    getStructureData: function (_data: any) {
      window.parent.postMessage({
        type: 'structureData',
        size: size,
        data: getStructureData(),
      }, '*');
    },
    getPositionData: function (_data: any) {
      // Watch for size changes.
      watchElementSize(document.body, () => {
        if (positionUpdateTimeout) {
          clearTimeout(positionUpdateTimeout);
        }
        positionUpdateTimeout = setTimeout(() => {
          window.parent.postMessage({
            type: 'positionUpdateData',
            size: size,
            data: getPositionData(),
          }, '*');
          positionUpdateTimeout = null;
        }, 150);
      });
      // Send initial position data.
      window.parent.postMessage({
        type: 'positionData',
        size: size,
        data: getPositionData(),
      }, '*');
    }
  };

  /**
   * Get the structure data of components and regions in this iframe.
   */
  function getStructureData(): Record<string, any> {
    const data: Record<string, any> = {};

    components.forEach(el => {
      const uuid = el.dataset.componentUuid;
      if (!uuid) return;
      const component = {
        type: 'component',
        data: JSON.parse(el.dataset.component || '{}'),
        parents: getParentUuids(el),
        children: getChildrenUuids(el),
      };
      data[uuid] = component;
    });

    regions.forEach(el => {
      const uuid = el.dataset.regionUuid;
      if (!uuid) return;
      const region = {
        type: 'region',
        data: JSON.parse(el.dataset.region || '{}'),
        parents: getParentUuids(el),
        children: getChildrenUuids(el),
      };
      data[uuid] = region;
    });

    return data;
  }

  /**
   * Get the structure data of components and regions in this iframe.
   */
  function getPositionData(): Record<string, DOMRect> {
    const data: Record<string, DOMRect> = {};

    components.forEach(el => {
      const uuid = el.dataset.componentUuid;
      if (!uuid) return;
      const component = el.getBoundingClientRect();
      data[uuid] = component;
    });

    regions.forEach(el => {
      const uuid = el.dataset.regionUuid;
      if (!uuid) return;
      const component = el.getBoundingClientRect();
      data[uuid] = component;
    });

    return data;
  }

  function getParentUuids(el: HTMLElement): string[] {
    const parents: string[] = [];
    const parentComponent = el.parentElement?.closest('[data-component]') as HTMLElement | null;
    if (parentComponent) {
      const parentComponentUuid = parentComponent.dataset.componentUuid;
      if (parentComponentUuid) {
        parents.push(parentComponentUuid);
      }
    }
    const parentRegion = el.parentElement?.closest('[data-region]') as HTMLElement | null;
    if (parentRegion) {
      const parentRegionUuid = parentRegion.dataset.regionUuid;
      if (parentRegionUuid) {
        parents.push(parentRegionUuid);
      }
    }
    return parents;
  }

  function getChildrenUuids(el: HTMLElement): string[] {
    const children: string[] = [];
    el.querySelectorAll<HTMLElement>('[data-component]').forEach(childComponent => {
      const childComponentUuid = childComponent.dataset.componentUuid;
      if (childComponentUuid) {
        children.push(childComponentUuid);
      }
    });
    el.querySelectorAll<HTMLElement>('[data-region]').forEach(childRegion => {
      const childRegionUuid = childRegion.dataset.regionUuid;
      if (childRegionUuid) {
        children.push(childRegionUuid);
      }
    });
    return children;
  }

  function watchElementSize(
    element: HTMLElement,
    callback: (entry: ResizeObserverEntry) => void
  ): ResizeObserver {
    const observer = new ResizeObserver((entries) => {
      for (const entry of entries) {
        callback(entry);
      }
    });

    observer.observe(element);
    return observer; // Return so you can disconnect later
  }

})();
