(function (Drupal) {

  // Define interfaces for better type safety
  interface ElementData {
    type: string;
    data: any;
    parents: string[];
    children: string[];
    events?: Record<string, any>;
  }

  /**
   * Interface for component operations
   */
  interface Actions {
    library: (uuid: string | null) => void;
    sort: (uuid: string | null) => void;
  }

  /**
   * Interface for component operations
   */
  interface Operations {
    edit: (uuid: string) => void;
    sort: (uuid: string) => void;
    delete: (uuid: string) => void;
    clone: (uuid: string) => void;
    add: (uuid: string, position: string) => void;
    move: (uuid: string, direction: string) => void;
  }

  // Elements
  const container = document.querySelector('.neo-alchemist-manage') as HTMLElement;
  if (!container) return;
  const wrapper = container.querySelector('.neo-alchemist-manage--wrapper') as HTMLElement;
  if (!wrapper) return;
  const iframes = {} as Record<'desktop' | 'tablet' | 'mobile', HTMLIFrameElement>;
  const panelTop = container.querySelector('.neo-alchemist--panel-top') as HTMLElement;
  const panelBottom = container.querySelector('.neo-alchemist--panel-bottom') as HTMLElement;
  const panelTitle = container.querySelector('.neo-alchemist--panel-title') as HTMLElement;
  container.querySelectorAll('iframe').forEach(iframe => {
    if (!iframe.dataset.size) return;
    const size = iframe.dataset.size as 'desktop' | 'tablet' | 'mobile';
    iframes[size] = iframe;
  });
  Drupal.neoAlchemist = Drupal.neoAlchemist || {};
  Drupal.neoAlchemist.iframes = iframes;

  // Data
  const structureElements: Record<string, Record<'desktop' | 'tablet' | 'mobile', HTMLElement>> = {};
  const shadeElements = {} as Record<'desktop' | 'tablet' | 'mobile', Record<0 | 1 | 2, HTMLElement>>;
  const overlayElements = {} as Record<'desktop' | 'tablet' | 'mobile', Record<0 | 1 | 2, HTMLElement>>;
  const transitionSpeed = 200; // in ms
  const sizes = ['desktop', 'tablet', 'mobile'] as const;
  const levels = [0, 1, 2] as const;
  const baseModalOptions: any = {
    width: '100%',
    height: '100%',
    neo: {
      displaceTop: '0px',
      displaceBottom: '0px',
    },
  };
  const debugElements = false;
  const actionButtons = container.querySelectorAll<HTMLElement>('.neo-alchemist--action');
  let opButtons:NodeListOf<HTMLElement> | null = null;
  let scale: string = localStorage.getItem('neo-alchemist-scale') || '1';
  let structureData: Record<string, ElementData> = {};
  let positionData = {} as Record<'desktop' | 'tablet' | 'mobile', Record<string, DOMRect>>;
  let overlayTimeouts = {} as Record<0 | 1 | 2, ReturnType<typeof setTimeout> | null>;
  let overlayState = {} as Record<string, null | 'hover' | 'active' | 'focus'>;
  let layerUuid: string | null = null;
  let regionUuid: string | null = null;
  let layerLevel: -1 | 0 | 1 | 2 = -1;

  // Hybrid mode: the field layout is locked, but one or more regions are
  // entity-customizable. Inherited components (the locked owner/header/footer)
  // become inert and customizable regions are directly clickable at the top
  // level, so the creator interacts only with the editable regions. All of
  // this is gated by `hybrid` — normal editing is unaffected.
  const hybrid = !!(drupalSettings.neoAlchemist && drupalSettings.neoAlchemist.hybrid);
  function isInheritedComponent(uuid: string | null): boolean {
    return !!uuid && structureData[uuid]?.data?.inherited === true;
  }
  function isCustomRegion(uuid: string | null): boolean {
    return !!uuid && structureData[uuid]?.type === 'region' && structureData[uuid]?.data?.custom === true;
  }
  // A layer that can be focused directly (without drilling through a parent).
  function isEditableTop(uuid: string | null): boolean {
    return hybrid && isCustomRegion(uuid);
  }
  // A first-level customizable region in an entity layout. Its inherited owner
  // is inert, so the region shades as if it were the page's top level: light
  // page shade clipped around the region, no dark shade inside the owner.
  // Nested regions deeper in the tree keep the normal owner-scoped shading.
  function isHybridRootRegion(uuid: string | null): boolean {
    if (!uuid || !isEditableTop(uuid)) {
      return false;
    }
    const parents = structureData[uuid].parents;
    return parents.length === 1 && isInheritedComponent(parents[0]);
  }
  let layerInteractSize: 'desktop' | 'tablet' | 'mobile' = 'desktop';
  let passthroughTimeout: ReturnType<typeof setTimeout> | null = null;
  let eventPendingStatus = false;
  let eventPendingHistory: Array<string> = [];
  const eventHistory: Record<string, {
    uuid: string;
    events: Array<string>;
  }> = {};

  // Iframe load handling
  let iframeProcessing = 0;
  Object.entries(iframes).forEach(([size, iframe]) => {
    iframe.addEventListener('load', () => {
      iframeProcessing++;
      if (!iframe.contentWindow) {
        return;
      }

      if (size === 'desktop') {
        // Request structure data from desktop iframe.
        iframe.contentWindow.postMessage({
          type: 'getStructureData',
        }, "*");
      }

      if (iframeProcessing === 3) {
        iframeProcessing = 0;
        // All frames loaded.
        Object.values(iframes).forEach(iframe => {
          if (!iframe.contentWindow) {
            return;
          }
          iframe.contentWindow.postMessage({
            type: 'getPositionData',
          }, "*");
        });
      }
    });
  });

  // Watch for scale changes
  const scaleCallback = (event: CustomEvent<any>) => {
    const detail = event.detail as { scale: string };
    scale = detail.scale as string;
  }
  container.addEventListener('alchemistManageScale', scaleCallback as EventListener);
  container.addEventListener('alchemistManageScaleEnd', () => {
    elementsPosition();
    layerShow(layerUuid, true, true);
  });

  // Handle postMessage communication
  window.addEventListener('message', (e) => {
    const data = e.data;
    if (typeof data.type === 'string') {
      const handler = onChild[data.type];
      if (typeof handler === 'function') {
        handler(data);
      }
    }
  });

  // Events called from child iframes.
  let iframeFinished = 0;
  const onChild:any = {
    structureData: function (data: any) {
      structureData = data.data;
    },
    regionAdd: function (data: any) {
      // The empty-region placeholder was clicked in a preview iframe. Open the
      // library scoped to that region (uuid--shape) regardless of focus state.
      if (data.uuid) {
        actions.library(data.uuid);
      }
    },
    positionData: function (data: any) {
      iframeFinished++;
      const size = data.size as 'desktop' | 'tablet' | 'mobile';
      positionData[size] = data.data;
      if (iframeFinished === 3) {
        iframeFinished = 0;
        ready();
      }
    },
    positionUpdateData: function (data: any) {
      const size = data.size as 'desktop' | 'tablet' | 'mobile';
      positionData[size] = data.data;
      elementsPosition();
      layerShow(layerUuid, true, true);
    },
    onEvent: function (data: any) {
      const eventType = data.eventType as string;
      const eventUuid = data.uuid as string;
      const eventData = getEventData(eventUuid);

      sizes.forEach(size => {
        if (size !== data.size) {
          const iframe = iframes[size];
          if (!iframe.contentWindow) {
            return;
          }
          iframe.contentWindow.postMessage({
            type: 'doEvent',
            uuid: eventUuid,
            eventType: eventType,
          }, "*");
        }
      });

      switch (eventType) {
        case 'mouseover':
          eventPendingHistory = [];
          eventPendingStatus = false;
          break;
        case 'mouseenter':
          clearTimeout(passthroughTimeout || undefined);
          container.classList.add('neo-alchemist--passthrough');
          break;
        case 'mouseleave':
          passthroughTimeout = setTimeout(() => {
            container.classList.remove('neo-alchemist--passthrough');
          }, 50);
          if (eventPendingStatus) {
            if (eventData.action === 'toggle' && eventHistory[eventData.group]) {
              delete eventHistory[eventData.group];
            }
            else {
              eventHistory[eventData.group] = {
                uuid: eventUuid,
                events: eventPendingHistory,
              };
            }
          }
          break;
      }

      eventPendingHistory.push(eventType);

      if (eventType === eventData.type) {
        // Only record the event if it matches the defined type.
        eventPendingStatus = true;
      }
    }
  };

  initialize();

  /**
   * We have the component data.
   *
   * This is called only once.
   */
  function initialize() {
    const shadeBase = container.querySelector('.neo-alchemist--shade-base') as HTMLElement;
    const overlayBase = container.querySelector('.neo-alchemist--overlay-base') as HTMLElement;
    sizes.forEach(size => {
      shadeElements[size] = {} as Record<0 | 1 | 2, HTMLElement>;
      overlayElements[size] = {} as Record<0 | 1 | 2, HTMLElement>;
      levels.forEach(level => {
        const shade = shadeBase.cloneNode(true) as HTMLElement;
        shade.classList.remove('neo-alchemist--shade-base');
        shade.classList.add('neo-alchemist--shade');
        shade.setAttribute('data-size', size);
        shade.setAttribute('data-level', level.toString());
        shade.style.transition = `opacity ${transitionSpeed}ms ease-in-out`;
        shade.style.opacity = '0';
        shade.style.zIndex = (10 + level).toString();
        wrapper.appendChild(shade);
        shade.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          layerBack();
        });
        shadeElements[size][level] = shade;

        const overlay = overlayBase.cloneNode(true) as HTMLElement;
        const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
        const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
        overlay.classList.remove('neo-alchemist--overlay-base');
        overlay.classList.add('neo-alchemist--overlay');
        overlay.setAttribute('data-size', size);
        overlay.setAttribute('data-level', level.toString());
        overlay.style.zIndex = (15 + level).toString();
        overlay.style.opacity = '0';
        // Allow editing with a double-click
        overlay.addEventListener('dblclick', (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (layerUuid) {
            operationExecute('edit');
          }
        });
        overlay.style.transition = `all ${transitionSpeed}ms ease-in-out`;
        overlayLabel.style.opacity = '0';
        overlayActions.forEach(action => action.style.opacity = '0');
        wrapper.appendChild(overlay);
        overlayElements[size][level] = overlay;
      });
    });
    shadeBase.remove();
    overlayBase.remove();

    // Close button handling
    wrapper.querySelectorAll<HTMLElement>('.neo-alchemist--close').forEach(closeButton => {
      closeButton.addEventListener('click', (e) => {
        e.preventDefault();
        layerShow();
      });
    });

    // Action button handling
    actionButtons.forEach(actionButton => {
      actionButton.addEventListener('click', (e) => {
        e.preventDefault();
        const actionKey = actionButton.dataset.action as keyof Actions;
        if (actionKey) {
          actionExecute(actionKey);
        }
      });
    });

    // Operation button handling
    opButtons = wrapper.querySelectorAll('.neo-alchemist--op') as NodeListOf<HTMLElement>;
    opButtons.forEach(opButton => {
      opButton.addEventListener('click', (e) => {
        e.preventDefault();
        const opKey = opButton.dataset.op as keyof Operations;
        if (opKey) {
          operationExecute(opKey);
        }
      });
    });

    // Wrapper click handling
    let startX:number;
    let startY:number;
    wrapper.addEventListener('mousedown', e => {
      startX = e.clientX;
      startY = e.clientY;
    });
    wrapper.addEventListener('mouseup', e => {
      if (layerUuid === null) {
        return;
      }
      // Check if target has data-alchemist-ignore
      if (e.target instanceof HTMLElement && (e.target.dataset.alchemistIgnore !== undefined || e.target.closest('[data-alchemist-ignore]'))) {
        return;
      }
      // If mouse hasn't moved, we blur the component.
      if (startX === e.clientX && startY === e.clientY) {
        layerBack();
      }
    });

    // Watch for component focus event
    const focusCallback = (event: CustomEvent<any>) => {
      const detail = event.detail as { uuid: string };
      layerUuid = detail.uuid;
    }
    container.addEventListener('alchemistManageComponentFocus', focusCallback as EventListener);

    // Build panel top positioning
    const rect = wrapper.getBoundingClientRect();
    panelTop.style.display = '';
    panelTop.style.top = `${rect.top}px`;
    panelTop.style.left = '0';
    panelTop.style.right = '0';
    panelTop.style.transform = `translate(0, -${rect.top}px)`;
    panelTop.style.opacity = '0';
    panelTop.style.zIndex = '30';
    panelTop.style.cursor = 'default';
    panelTop.style.transition = `opacity ${transitionSpeed}ms ease-in-out, transform ${transitionSpeed}ms ease-in-out`;
    // Build panel bottom positioning
    panelBottom.style.display = '';
    panelBottom.style.bottom = `${window.innerHeight - rect.top - rect.height}px`;
    panelBottom.style.left = '0';
    panelBottom.style.right = '0';
    panelBottom.style.transform = `translate(0, ${rect.top}px)`;
    panelBottom.style.opacity = '0';
    panelBottom.style.zIndex = '30';
    panelBottom.style.cursor = 'default';
    panelBottom.style.transition = `opacity ${transitionSpeed}ms ease-in-out, transform ${transitionSpeed}ms ease-in-out`;
  }

  /**
   * We have all the elements and positions, we are ready to go.
   *
   * This will be called once all iframes have sent position data. It can be
   * called multiple times if the iframes are reloaded.
   */
  function ready(): void {
    // Reset overlay state
    overlayState = {};
    // Clear existing elements
    container.querySelectorAll<HTMLElement>('.neo-alchemist--element, .neo-alchemist--event').forEach(element => {
      element.remove();
    });

    // Build structure elements
    const elementBase = container.querySelector('.neo-alchemist--element-base') as HTMLElement;
    const eventBase = container.querySelector('.neo-alchemist--event-base') as HTMLElement;
    Object.entries(structureData).forEach(([uuid, data]) => {
      structureElements[uuid] = {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
      sizes.forEach(size => {
        const level = data.parents.length;
        const element = elementBase.cloneNode(true) as HTMLElement;
        element.classList.remove('neo-alchemist--element-base');
        element.classList.add('neo-alchemist--element');
        element.setAttribute('data-uuid', uuid);
        element.setAttribute('data-size', size);
        element.setAttribute('data-level', level.toString());
        if (debugElements) {
          switch (level) {
            case 0:
              element.style.border = '1px solid red';
              break;
            case 1:
              element.style.border = '1px solid green';
              break;
            case 2:
              element.style.border = '1px solid blue';
              break;
          }
        }
        element.style.cursor = 'pointer';
        element.style.zIndex = (20 + level).toString();
        element.addEventListener('mouseenter', () => {
          layerInteractSize = size;
          if (hybrid && isInheritedComponent(uuid)) {
            return;
          }
          // A customizable region is directly interactive at the top level.
          if (layerLevel + 1 === level || (isEditableTop(uuid) && layerLevel === -1)) {
            elementHover(uuid);
          }
        });
        element.addEventListener('mouseleave', () => {
          if (hybrid && isInheritedComponent(uuid)) {
            return;
          }
          if (layerLevel + 1 === level || (isEditableTop(uuid) && layerLevel === -1)) {
            elementBlur(uuid);
          }
        });
        element.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (hybrid && isInheritedComponent(uuid)) {
            return;
          }
          elementFocus(uuid);
        });
        wrapper.appendChild(element);
        structureElements[uuid][size] = element;
        if (data.events) {
          Object.keys(data.events).forEach(eventId => {
            const eventTrigger = eventBase.cloneNode(true) as HTMLElement;
            eventTrigger.classList.remove('neo-alchemist--event-base');
            eventTrigger.classList.add('neo-alchemist--event');
            eventTrigger.setAttribute('data-event-id', eventId);
            eventTrigger.setAttribute('data-size', size);
            eventTrigger.style.display = 'none';
            eventTrigger.style.zIndex = '40';
            if (debugElements) {
              eventTrigger.style.border = '1px solid orange';
              eventTrigger.style.display = '';
            }
            eventTrigger.addEventListener('mouseenter', () => {
              layerInteractSize = size;
              clearTimeout(passthroughTimeout || undefined);
              container.classList.add('neo-alchemist--passthrough');
            });
            wrapper.appendChild(eventTrigger);
            structureElements[eventId] = structureElements[eventId] || {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
            structureElements[eventId][size] = eventTrigger;
          });
        }
      });
    });

    elementsPosition();
    elementsToggle();
    // Hide the root Add/Sort in hybrid mode until a region is focused. When a
    // layer is restored below, layerShow() re-runs this.
    actionsToggle();

    if (layerUuid) {
      if (eventHistory && Object.keys(eventHistory).length > 0) {
        Object.entries(eventHistory).forEach(([_eventGroup, eventInfo]) => {
          eventInfo.events.forEach(eventType => {
            Object.entries(iframes).forEach(([size, iframe]) => {
              iframe.contentWindow?.postMessage({
                type: 'doEvent',
                size: size,
                uuid: eventInfo.uuid,
                eventType: eventType,
              });
            });
          });
        });
      }
      layerShow(layerUuid, true, true);
    }
  }

  function elementHover(uuid: string): void {
    const data = structureData[uuid];
    const level = data.parents.length as 0 | 1 | 2;
    clearTimeout(overlayTimeouts[level] || undefined);
    overlayShow(uuid);
  }

  function elementFocus(uuid: string): void {
    // Inherited components are inert in hybrid mode.
    if (hybrid && isInheritedComponent(uuid)) {
      return;
    }
    layerShow(uuid);
  }

  function elementBlur(uuid: string): void {
    const data = structureData[uuid];
    const level = data.parents.length as 0 | 1 | 2;
    overlayState[uuid] = null;
    overlayTimeouts[level] = setTimeout(() => {
      overlayHide(uuid);
    }, 200);
  }

  function getEventData(eventUuid: string): any {
    for (const uuid in structureData) {
      const data = structureData[uuid];
      if (data.events && data.events[eventUuid]) {
        return data.events[eventUuid];
      }
    }
    return null;
  }

  /**
   * Show overlay for the given component/region UUID.
   */
  function overlayShow(uuid: string, state: 'hover' | 'active' | 'focus' = 'hover', force: boolean = false): void {
    if (!force && overlayState[uuid] && overlayState[uuid] === state) {
      return;
    }
    sizes.forEach(size => {
      overlayShowLevel(uuid, size, state);
    });
    overlayState[uuid] = state;
  }

  function overlayShowLevel(uuid: string, size: 'desktop' | 'tablet' | 'mobile', state: 'hover' | 'active' | 'focus' = 'hover'): void {
    const data = structureData[uuid];
    const level = data.parents.length as 0 | 1 | 2;
    const overlay = overlayElements[size][level];
    const heightOffset = level >= 1 ? 0 : 10;
    const absoluteRect = calculateIframeRect(size, positionData[size][uuid], heightOffset);
    overlay.style.left = `${absoluteRect.left}px`;
    overlay.style.top = `${absoluteRect.top}px`;
    overlay.style.width = `${absoluteRect.width}px`;
    overlay.style.height = `${absoluteRect.height}px`;
    overlay.style.opacity = '1';
    overlay.style.pointerEvents = 'none';
    switch (state) {
      case 'hover':
        overlayHover(uuid, size, overlay, data);
        break;
      case 'active':
        overlayActive(uuid, size, overlay, data);
        break;
      case 'focus':
        overlayFocus(uuid, size, overlay, data);
        break;
    }
  }

  function overlayHover(_uuid: string, _size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    overlayLabel.style.transition = 'none';
    overlayLabel.style.opacity = '1';
    overlayLabel.innerHTML = `<span class="bg-base-500 text-base-500-content rounded py-0.5 px-1">${data.data.label}</span>`;
    if (data.data.warnings && data.data.warnings.length > 0) {
      data.data.warnings.forEach((warning:string) => {
        overlayLabel.innerHTML += ` <span class="badge rounded-sm bg-warning-500 text-warning-500-content">${warning}</span>`;
      });
    }
    if (data.data.alerts && data.data.alerts.length > 0) {
      data.data.alerts.forEach((alert:string) => {
        overlayLabel.innerHTML += ` <span class="badge rounded-sm bg-alert-500 text-alert-500-content">${alert}</span>`;
      });
    }
    if (data.type === 'component') {
      overlayActions.forEach(action => {
        action.style.transition = 'none';
        action.style.opacity = '0';
      });
    }
  }

  function overlayActive(uuid: string, size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    overlayLabel.style.transition = '';
    overlayLabel.style.opacity = '0';
    overlayLabel.textContent = data.data.label;
    if (data.type === 'component') {
      overlayActions.forEach(action => {
        action.style.transition = 'none';
        action.style.opacity = '0';
      });
    }
    setTimeout(() => {
      shadeShowLevel(uuid, size);
    });
  }

  function overlayFocus(uuid: string, size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    overlayLabel.style.transition = '';
    overlayLabel.style.opacity = '0';
    overlayLabel.textContent = data.data.label;
    if (data.type === 'component') {
      overlayActions.forEach(action => {
        action.style.transition = '';
        action.style.opacity = '1';
      });
    }
    // An empty region renders only its "Add a component" placeholder, and this
    // overlay covers it exactly — an interactive overlay swallows that click and
    // the placeholder silently becomes a selection target. There is nothing in
    // an empty region to select, so leave the overlay inert and let the pointer
    // through to the placeholder, which already posts `regionAdd`.
    if (data.type !== 'region' || data.children.length) {
      overlay.style.pointerEvents = 'auto';
    }
    setTimeout(() => {
      shadeShowLevel(uuid, size);
    });
  }

  function overlayHide(uuid: string): void {
    overlayState[uuid] = null;
    const data = structureData[uuid];
    const level = data.parents.length as 0 | 1 | 2;
    overlayHideLevel(level);
    shadeHideLevel(level);
  }

  function overlayHideLevel(level: 0 | 1 | 2): void {
    sizes.forEach(size => {
      const overlay = overlayElements[size][level];
      overlay.style.opacity = '0';
    });
    overlayTimeouts[level] = setTimeout(() => {
      sizes.forEach(size => {
        const overlay = overlayElements[size][level];
        const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
        const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
        overlay.style.left = '';
        overlay.style.top = '';
        overlay.style.width = '';
        overlay.style.height = '';
        overlayLabel.style.opacity = '0';
        overlayActions.forEach(action => action.style.opacity = '0');
      });
    }, transitionSpeed);
  }

  function shadeShowLevel(uuid: string, size: 'desktop' | 'tablet' | 'mobile'): void {
    const data = structureData[uuid];
    const level = data.parents.length as 0 | 1 | 2;
    const shade = shadeElements[size][level];
    const rootRegion = isHybridRootRegion(uuid);
    const container = getShadeContainer(uuid, size);
    const heightOffset = level === 1 && !rootRegion ? 0 : 10;
    const absoluteRect = calculateRect(container.getBoundingClientRect());
    shade.style.left = `${absoluteRect.left}px`;
    shade.style.top = `${absoluteRect.top}px`;
    shade.style.width = `${absoluteRect.width}px`;
    shade.style.height = `${absoluteRect.height}px`;
    shade.style.opacity = '1';
    if (data.type === 'component' || rootRegion) {
      shade.classList.remove('bg-base-950/60');
      shade.classList.add('bg-base-0/60');
    }
    else {
      shade.classList.remove('bg-base-0/60');
      shade.classList.add('bg-base-950/60');
    }
    createClipPath(shade, structureElements[uuid][size], container, heightOffset);
  }

  function shadeHideLevel(level: 0 | 1 | 2): void {
    sizes.forEach(size => {
      const shade = shadeElements[size][level];
      shade.style.opacity = '0';
    });
  }

  function getShadeContainer(uuid: string, size: 'desktop' | 'tablet' | 'mobile'): HTMLElement {
    const data = structureData[uuid];
    const level = data.parents.length as 0 | 1 | 2;
    if (level === 0 || isHybridRootRegion(uuid)) {
      return getIframe(size);
    }
    const lastParent = data.parents[data.parents.length - 1];
    return structureElements[lastParent][size];
  }

  function titleSet(uuid: string | null): void {
    let title = [];
    if (uuid) {
      const data = structureData[uuid];
      data.parents.forEach(parentUuid => {
        const parentData = structureData[parentUuid];
        title.push({
          label: parentData.data.label,
          uuid: parentUuid,
        });
      });
      title.push({
        label: data.data.label,
        uuid: uuid,
        warnings: data.data.warnings || [],
        alerts: data.data.alerts || [],
      });
    }
    panelTitle.innerHTML = '';
    if (!title.length) {
      panelTop.style.opacity = '0';
      panelTop.style.transform = `translate(0, -${panelTop.getBoundingClientRect().height}px)`;
    }
    else {
      title = [{ label: 'Root', uuid: null }, ...title];
      title.forEach((part, index) => {
        // The current layer, and inherited (locked) ancestors in hybrid mode,
        // are shown for context but are not clickable.
        const inert = hybrid && isInheritedComponent(part.uuid);
        const span = document.createElement((part.uuid === uuid || inert) ? 'div' : 'a');
        span.classList.add('flex', 'items-center', 'gap-2', 'whitespace-nowrap');
        if (span instanceof HTMLAnchorElement) {
          span.href = '#';
          span.classList.add('hover:underline');
          span.style.fontWeight = '300';
        }
        else {
          span.style.fontWeight = '500';
        }
        span.classList.add('text-base-900-content');
        if (part.uuid) {
          span.innerHTML = `<span>${part.label}</span>`;
          if (part.warnings && part.warnings.length > 0) {
            part.warnings.forEach((warning:string) => {
              span.innerHTML += ` <span class="badge rounded-sm bg-warning-500 text-warning-500-content">${warning}</span>`;
            });
          }
          if (part.alerts && part.alerts.length > 0) {
            part.alerts.forEach((alert:string) => {
              span.innerHTML += ` <span class="badge rounded-sm bg-alert-500 text-alert-500-content">${alert}</span>`;
            });
          }
        }
        else {
          span.innerHTML = '<i class="neo-icon neo-icon-font icon-regular-home"></i>';
        }
        span.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          layerShow(part.uuid);
        });
        panelTitle.appendChild(span);
        if (index < title.length - 1) {
          const sep = document.createElement('div');
          sep.textContent = '»';
          sep.style.opacity = '0.5';
          panelTitle.appendChild(sep);
        }
      });
      panelTop.style.opacity = '1';
      panelTop.style.transform = 'translate(0, 0)';
    }
  }

  let opTimeout: ReturnType<typeof setTimeout> | null = null;
  function opsSet(uuid: string | null): void {
    if (!opButtons) return;
    if (opTimeout) {
      clearTimeout(opTimeout);
      opTimeout = null;
    }

    // Find enabled buttons
    const enabledButtons: HTMLElement[] = [];
    if (uuid) {
      const data = structureData[uuid];
      const level = data.parents.length as 0 | 1 | 2;
      const ops = data?.data?.ops;
      if (ops) {
        for (const [opKey, status] of Object.entries(ops)) {
          if (status) {
            let button = panelBottom.querySelector<HTMLElement>(`[data-op="${opKey}"]`);
            if (button) {
              enabledButtons.push(button);
            }
            else {
              sizes.forEach(size => {
                button = overlayElements[size][level].querySelector<HTMLElement>(`[data-op="${opKey}"]`);
                if (button) enabledButtons.push(button);
              });
            }
          }
        }
      }
    }

    // Show/hide buttons and animate panel
    if (enabledButtons.length > 0) {
      const enabledSet = new Set(enabledButtons);
      opButtons.forEach(button => {
        button.style.display = enabledSet.has(button) ? '' : 'none';
      });
      panelBottom.style.opacity = '1';
      panelBottom.style.transform = 'translate(0, 0)';
    } else {
      const height = panelBottom.getBoundingClientRect().height;
      panelBottom.style.opacity = '0';
      panelBottom.style.transform = `translate(0, ${height}px)`;
      opTimeout = setTimeout(() => {
        if (!opButtons) return;
        opButtons.forEach(button => {
          button.style.display = 'none';
        });
      }, transitionSpeed);
    }
  }

  // let layerTimeout: ReturnType<typeof setTimeout> | null = null;
  function layerShow(uuid: string | null = null, force: boolean = false, instant: boolean = false): void {
    if (!uuid && !layerUuid) {
      return;
    }
    // Inherited components are inert in hybrid mode. Reachable here only via a
    // breadcrumb parent link; treat it as a deselect rather than focusing the
    // locked component.
    if (hybrid && isInheritedComponent(uuid)) {
      layerShow(null, force, instant);
      return;
    }

    let level : -1 | 0 | 1 | 2 = -1;
    let parents: string[] = [];
    if (uuid === layerUuid) {
      layerUuid = null;
    }
    if (uuid && !structureData[uuid]) {
      if (regionUuid && structureData[regionUuid]) {
        // Reset to region.
        uuid = regionUuid;
        overlayHideLevel(2);
        shadeHideLevel(2);
      }
      else {
        uuid = null;
      }
    }

    if (instant) {
      container.classList.add('neo-alchemist--instant');
      setTimeout(() => {
        container.classList.remove('neo-alchemist--instant');
      });
    }

    if (uuid) {
      let lastTree: string[] = [];
      let currentTree: string[] = [];
      if (layerUuid && structureData[layerUuid]) {
        const lastData = structureData[layerUuid];
        lastTree = [ ...lastData.parents, layerUuid ];
      }
      const data = structureData[uuid];
      layerUuid = uuid;
      currentTree = [ ...data.parents, uuid ];
      parents = data.parents;
      level = parents.length as 0 | 1 | 2;
      parents.forEach(parentUuid => {
        // Inert inherited owners never render as an active layer.
        if (hybrid && isInheritedComponent(parentUuid)) {
          return;
        }
        overlayShow(parentUuid, 'active', force);
      });
      overlayShow(uuid, 'focus', force);

      // Cleanup overlays that are not in current tree.
      const diff = lastTree.filter(item => !currentTree.includes(item));
      diff.forEach(removedUuid => {
        overlayHide(removedUuid);
      });
    }
    else {
      hideAll();
    }

    elementsToggle(uuid);
    titleSet(uuid);
    opsSet(uuid);

    if (uuid) {
      sizes.forEach(size => {
        if (size === layerInteractSize) {
          Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(structureElements[uuid][size], wrapper, 100);
        }
      });
    }

    layerLevel = level;
    if (level === 1) {
      regionUuid = uuid;
    }
    else if (level === 0) {
      regionUuid = null;
    }
    layerUuid = uuid;
    actionsToggle();
  }

  /**
   * The customizable region the toolbar actions apply to for the focused layer.
   *
   * Either the focused region itself, or the custom-region ancestor of a
   * focused component inside it (region-in-region is disallowed, so a focused
   * component has exactly one custom-region ancestor). Null when the focus is
   * outside any customizable region.
   */
  function focusedCustomRegion(uuid: string | null): string | null {
    if (!uuid) return null;
    if (isCustomRegion(uuid)) return uuid;
    const data = structureData[uuid];
    if (!data) return null;
    return data.parents.find(p => isCustomRegion(p)) || null;
  }

  /**
   * Toggle the bottom toolbar Add/Sort in hybrid mode.
   *
   * Root add/sort is locked in hybrid mode, so these only make sense scoped to
   * a customizable region — but, as in non-hybrid, the bar stays up while any
   * layer *inside* that region is focused (a focused component's add/sort still
   * targets its region). Show Add whenever the focus is inside a region, and
   * Sort only when that region has something to reorder. Non-hybrid layouts
   * keep the buttons always visible (untouched).
   */
  function actionsToggle(): void {
    if (!hybrid || !actionButtons) return;
    const region = focusedCustomRegion(layerUuid);
    const childCount = region ? (structureData[region]?.children?.length || 0) : 0;
    actionButtons.forEach(button => {
      const action = button.dataset.action;
      const visible = action === 'sort' ? (!!region && childCount >= 2) : !!region;
      button.style.display = visible ? '' : 'none';
    });
  }

  function layerBack(): void {
    if (!layerUuid) return;
    const data = structureData[layerUuid];
    const lastParent = data.parents[data.parents.length - 1] || null;
    // In hybrid mode the inherited owner is inert, so backing out of a
    // top-level customizable region deselects rather than focusing the owner.
    if (hybrid && (!lastParent || isInheritedComponent(lastParent))) {
      layerShow(null);
      return;
    }
    layerShow(lastParent);
  }

  function hideAll(): void {
    overlayState = {};
    levels.forEach(level => {
      overlayHideLevel(level);
      shadeHideLevel(level);
    });
    if (layerUuid && structureData[layerUuid]) {
      const data = structureData[layerUuid];
      if (data.events) {
        Object.keys(data.events).forEach(eventId => {
          sizes.forEach(size => {
            const eventTrigger = structureElements[eventId][size];
            eventTrigger.style.display = 'none';
          });
        });
      }
    }
  }

  /**
   * Position all elements according to the position data.
   */
  function elementsPosition() {
    sizes.forEach(size => {
      // The preview iframes load deferred and report their position data one at
      // a time, so a scale transition can finish — firing alchemistManageScaleEnd
      // — before a given size has reported in. Skip it until it has, rather than
      // let Object.entries() throw on undefined.
      const positions = positionData[size];
      if (!positions) {
        return;
      }
      Object.entries(positions).forEach(([uuid, rect]) => {
        const element = structureElements[uuid][size];
        if (element) {
          const absoluteRect = calculateIframeRect(size, rect);
          element.style.left = `${absoluteRect.left}px`;
          element.style.top = `${absoluteRect.top}px`;
          element.style.width = `${absoluteRect.width}px`;
          element.style.height = `${absoluteRect.height}px`;
        }
      });
    });
  }

  /**
   * Toggle visibility of elements (triggers) based on the active layer UUID.
   */
  function elementsToggle(uuid?: string | null): void {
    if (debugElements) {
      return
    }
    let level = 0;
    let parents: string[] = [];
    let children: string[] = [];
    if (uuid) {
      const data = structureData[uuid];
      parents = data.parents;
      children = data.children;
      level = parents.length as 0 | 1 | 2;
      // We want to show up to level + 1
      level++;
    }

    Object.entries(structureData).forEach(([elementUuid, data]) => {
      const dataLevel = data.parents.length as 0 | 1 | 2;
      sizes.forEach(size => {
        // Hybrid, nothing focused: only customizable regions are directly
        // interactive; inherited components (and everything else) are inert,
        // so the creator can click straight into a region. The focused branch
        // below already inerts non-child ancestors and hides siblings, which
        // is correct for hybrid too (the inherited owner shows inert; other
        // inherited components hide).
        if (hybrid && !uuid) {
          if (isCustomRegion(elementUuid)) {
            showLayer(elementUuid, size);
          }
          else {
            hideLayer(elementUuid, size);
          }
          return;
        }
        // const element = structureElements[elementUuid][size];
        if (dataLevel <= level) {
          if (uuid) {
            if (children.includes(elementUuid)) {
              showLayer(elementUuid, size);
            }
            else if (uuid === elementUuid || parents.includes(elementUuid)) {
              showLayer(elementUuid, size, false);
            }
            else {
              hideLayer(elementUuid, size);
            }
            return;
          }
          else {
            showLayer(elementUuid, size);
          }
        }
        else {
          hideLayer(elementUuid, size);
        }
      });
    });
  }

  function showLayer(uuid: string, size: 'desktop' | 'tablet' | 'mobile', interact: boolean = true): void {
    const element = structureElements[uuid][size];
    const data = structureData[uuid];
    element.style.display = 'block';
    element.style.pointerEvents = interact ? 'auto' : 'none';
    if (interact) {
      const lastParent = data.parents[data.parents.length - 1];
      if (lastParent) {
        const parentData = structureData[lastParent];
        if (parentData.events) {
          Object.keys(parentData.events).forEach(eventId => {
            const eventTrigger = structureElements[eventId][size];
            eventTrigger.style.display = 'block';
          });
        }
      }
    }
    else if (data.events) {
      Object.keys(data.events).forEach(eventId => {
        const eventTrigger = structureElements[eventId][size];
        eventTrigger.style.display = 'none';
      });
    }
  }

  function hideLayer(uuid: string, size: 'desktop' | 'tablet' | 'mobile'): void {
    const element = structureElements[uuid][size];
    const data = structureData[uuid];
    element.style.display = 'none';
    element.style.pointerEvents = 'none';
    if (data.events) {
      Object.keys(data.events).forEach(eventId => {
        const eventTrigger = structureElements[eventId][size];
        eventTrigger.style.display = 'none';
      });
    }
  }

  function actionExecute(
    opKey: keyof Actions
  ): void {
    if (actions[opKey as keyof Actions]) {
      let parent = null;
      if (layerUuid) {
        const data = structureData[layerUuid];
        if (data.type === 'region') {
          parent = layerUuid;
        }
        else {
          parent = data.parents[data.parents.length - 1] || null;
        }
      }
      actions[opKey as keyof Actions](parent);
    }
  }

  const actions: Actions = {
    library: (uuid: string | null): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/library${uuid ? `?parent=${uuid}` : ''}`,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    sort: (uuid: string | null): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/sort${uuid ? `?parent=${uuid}` : ''}`,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },
  };

  function operationExecute(
    opKey: keyof Operations
  ): void {
    if (!layerUuid) return;
    const data = structureData[layerUuid];
    const [op, spec] = opKey.includes('-') ? opKey.split('-', 2) : [opKey, undefined];
    if (data.data.ops?.[opKey] && operations[op as keyof Operations]) {
      operations[op as keyof Operations](layerUuid, spec as string);
    }
  }

  /**
   * Operations available for components
   */
  const operations: Operations = {
    edit: (uuid: string): void => {
      const modalOptionsEdit: any = {
        ...baseModalOptions,
        neo: {
          ...baseModalOptions.neo,
          contentPadding: '0px',
        },
      };
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/edit/${uuid}`,
        dialogType: 'modal',
        dialog: modalOptionsEdit,
      }).execute();
    },

    sort: (uuid: string): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/sort?uuid=${uuid}${regionUuid ? `&parent=${regionUuid}` : ''}`,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    delete: (uuid: string): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/delete/${uuid}`,
        dialogType: 'modal',
        dialog: {
          ...baseModalOptions,
          width: 'auto',
          height: 'auto',
        },
      }).execute();
    },

    clone: (uuid: string): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/clone/${uuid}`,
      }).execute();
    },

    add: (uuid: string, position: string): void => {
      let url = `${drupalSettings.neoAlchemist.baseUrl}/library?${position}=${uuid}`;
      if (layerUuid) {
        const data = structureData[layerUuid];
        const parent = data.parents[data.parents.length - 1] || null;
        if (parent) {
          url += `&parent=${parent}`;
        }
      }
      Drupal.ajax({
        url: url,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    move: (uuid: string, direction: string): void => {
      let url = `${drupalSettings.neoAlchemist.baseUrl}/move/${uuid}/${direction}`;
      if (layerUuid) {
        const data = structureData[layerUuid];
        const parent = data.parents[data.parents.length - 1] || null;
        if (parent) {
          url += `?parent=${parent}`;
        }
      }
      Drupal.ajax({
        url: url,
      }).execute();
    },
  };

  /**
   * Calculate the relative position and size of a rect within the wrapper.
   */
  function calculateIframeRect(size: 'desktop' | 'tablet' | 'mobile', rect: DOMRect, heightOffset: number = 0): DOMRect {
    const scaleInt = parseFloat(scale);
    const containerRect = getIframe(size).getBoundingClientRect();
    const wrapperRect = wrapper.getBoundingClientRect();
    return new DOMRect(
      wrapper.scrollLeft + containerRect.left + (rect.left * scaleInt) + window.scrollX - wrapperRect.left,
      wrapper.scrollTop + containerRect.top + (rect.top * scaleInt) + window.scrollY - wrapperRect.top - heightOffset,
      rect.width * scaleInt,
      (rect.height * scaleInt) + (heightOffset * 2)
    );
  }

  /**
   * Calculate the absolute position and size of a rect within the wrapper.
   */
  function calculateRect(rect: DOMRect, heightOffset: number = 0): DOMRect {
    const wrapperRect = wrapper.getBoundingClientRect();
    return new DOMRect(
      wrapper.scrollLeft + rect.left + window.scrollX - wrapperRect.left,
      wrapper.scrollTop + rect.top + window.scrollY - wrapperRect.top - heightOffset,
      rect.width,
      rect.height + (heightOffset * 2)
    );
  }

  /**
   * Create clip path for the shade
   */
  function createClipPath(el: HTMLElement, target: HTMLElement, container: HTMLElement, heightOffset: number = 0): void {
    const rect = target.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();
    const top = rect.top - containerRect.top - heightOffset;
    const left = rect.left - containerRect.left;
    const right = left + rect.width;
    const bottom = top + rect.height + (heightOffset * 2);
    el.style.clipPath = `polygon(0% 0%, 0% 100%, ${left}px 100%, ${left}px ${top}px, ${right}px ${top}px, ${right}px ${bottom}px, ${left}px ${bottom}px, ${left}px 100%, 100% 100%, 100% 0%)`;
  }

  function getIframe(size: 'desktop' | 'tablet' | 'mobile'): HTMLIFrameElement {
    return iframes[size];
  }

})(Drupal);
