(function () {

  // const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');
  // Components and regions are the two node kinds the editor tracks; ancestor
  // and child lookups have to consider both at once to stay in document order.
  const nodeSelector = '[data-component],[data-region]';
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
    const eventUuid = uuid + ':event:' + eventCount;
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
      el?.addEventListener(eventType, (_e) => {
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

  // Empty customizable regions render a "[+] Add a component" placeholder.
  // Focusing an empty region to use the toolbar Add is unreliable, so let the
  // placeholder open the library for its region directly: post the region id
  // (uuid--shape) up to the parent editor, which scopes the library to it.
  document.querySelectorAll<HTMLElement>('[data-region-add]').forEach(el => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const region = el.closest('[data-region-uuid]') as HTMLElement | null;
      const uuid = region?.dataset.regionUuid;
      if (uuid) {
        window.parent.postMessage({ type: 'regionAdd', uuid: uuid }, '*');
      }
    });
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
      const eventInfo = eventEl.dataset.event ? JSON.parse(eventEl.dataset.event) : {};
      data[eventUuid] = {
        type: eventInfo.event || 'click',
        group: eventInfo.group ? uuid + ':event:' + eventInfo.group : eventUuid,
        action: eventInfo.action || '',
      };
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

  /**
   * The structure uuid of a component or region element.
   */
  function getNodeUuid(el: HTMLElement): string | null {
    return el.dataset.componentUuid || el.dataset.regionUuid || null;
  }

  /**
   * The full ancestor chain of a node, outermost first.
   *
   * The parent editor treats `parents.length` as the node's depth and renders
   * the chain as the breadcrumb, so this has to be every enclosing component
   * and region in document order. Recording only the nearest of each kind
   * capped depth at 2 and silently truncated the breadcrumb.
   */
  function getParentUuids(el: HTMLElement): string[] {
    const parents: string[] = [];
    let ancestor = el.parentElement?.closest<HTMLElement>(nodeSelector) || null;
    while (ancestor) {
      const uuid = getNodeUuid(ancestor);
      if (uuid) {
        parents.unshift(uuid);
      }
      ancestor = ancestor.parentElement?.closest<HTMLElement>(nodeSelector) || null;
    }
    return parents;
  }

  /**
   * The direct children of a node, in document order.
   *
   * Anything deeper belongs to those children, not to this node. The parent
   * editor counts this list (Sort visibility, child-count badges) and walks it
   * to order insertion points, both of which are wrong if grandchildren are
   * folded in.
   */
  function getChildrenUuids(el: HTMLElement): string[] {
    const children: string[] = [];
    el.querySelectorAll<HTMLElement>(nodeSelector).forEach(child => {
      if (child.parentElement?.closest<HTMLElement>(nodeSelector) !== el) {
        return;
      }
      const uuid = getNodeUuid(child);
      if (uuid) {
        children.push(uuid);
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
