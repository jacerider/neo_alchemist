(function () {

  // const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');
  const components = document.querySelectorAll<HTMLElement>('[data-component]');
  const regions = document.querySelectorAll<HTMLElement>('[data-region]');
  const events: Record<string, HTMLElement> = {};
  const eventsByParent: Record<string, Record<string, HTMLElement>> = {};
  let updateTimeout: ReturnType<typeof setTimeout> | null = null;
  let eventCount = 0;
  let broadcastSkip = false;

  // Register events.
  document.querySelectorAll<HTMLElement>('[data-event]').forEach(el => {
    const component = el.closest('[data-component]') as HTMLElement | null;
    if (!component) return;
    const uuid = component.dataset.componentUuid;
    if (!uuid) return;
    const eventUuid = uuid + '--event-' + eventCount;
    el.dataset.eventUuid = eventUuid;
    eventsByParent[uuid] = eventsByParent[uuid] || {};
    eventsByParent[uuid][eventUuid] = el;
    events[eventUuid] = el;
    const mouseEvents = [
      'mousedown', 'mouseup', 'click',
      'mouseenter', 'mouseleave',
      'mouseover', 'mouseout',
    ] as const;

    mouseEvents.forEach(eventType => {
      el?.addEventListener(eventType, (e) => {
        if (broadcastSkip) {
          broadcastSkip = false;
          return;
        }
        window.parent.postMessage({
          type: 'onEvent',
          size: size,
          uuid: eventUuid,
          eventType: eventType,
        }, '*');
      });
    });
    eventCount++;
  });

  window.addEventListener('message', function(e) {
    const data = e.data;
    if (typeof data.type === 'string') {
      if (typeof onParent[data.type] !== 'function') {
        return;
      }
      onParent[data.type](data);
    }
  });

  const updateCallback = () => {
    if (updateTimeout) {
      clearTimeout(updateTimeout);
    }
    updateTimeout = setTimeout(() => {
      window.parent.postMessage({
        type: 'positionUpdateData',
        size: size,
        data: getPositionData(),
      }, '*');
      updateTimeout = null;
    }, 150);
  }

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
      watchChanges(document.body, updateCallback);
      watchElementSize(document.body, updateCallback);
      // Send initial position data.
      window.parent.postMessage({
        type: 'positionData',
        size: size,
        data: getPositionData(),
      }, '*');
    },
    doEvent: function (data: any) {
      if (!data.uuid || !data.eventType || !events[data.uuid]) {
        return;
      }
      const eventEl = events[data.uuid];
      // Skip broadcasting back to parent.
      broadcastSkip = true;
      // Trigger the event on the element.
      const eventObj = new Event(data.eventType, {
        bubbles: true,
        cancelable: true,
      });
      eventEl.dispatchEvent(eventObj);

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
        events: getEventStructureData(uuid),
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

  function getEventStructureData(uuid: string): Record<string, any> {
    const data: Record<string, any> = {};
    if (!eventsByParent[uuid]) {
      return data;
    }
    Object.keys(eventsByParent[uuid]).forEach(eventUuid => {
      const eventEl = eventsByParent[uuid][eventUuid];
      data[eventUuid] = uuid + ':' +(eventEl.dataset.event || eventUuid);
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

    Object.keys(events).forEach(uuid => {
      const eventEl = events[uuid];
      data[uuid] = eventEl.getBoundingClientRect();
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

  function watchChanges(
    element: HTMLElement,
    callback: (entry: MutationRecord) => void
  ): MutationObserver {
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        callback(mutation);
        // console.log('Target:', mutation.target);

        // if (mutation.type === 'childList') {
        //   console.log('Added nodes:', mutation.addedNodes);
        //   console.log('Removed nodes:', mutation.removedNodes);
        // } else if (mutation.type === 'attributes') {
        //   console.log('Attribute changed:', mutation.attributeName);
        //   console.log('Old value:', mutation.oldValue);
        // } else if (mutation.type === 'characterData') {
        //   console.log('Text content changed');
        // }
      });
    });
    observer.observe(element, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeOldValue: true,
      // characterData: true,
      // characterDataOldValue: true,
    });
    return observer;
  }

})();
