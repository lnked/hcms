/** Narrow Testing Library queries to HTMLInputElement without type assertions. */
export function requireInput(el: HTMLElement): HTMLInputElement {
  if (!(el instanceof HTMLInputElement)) {
    throw new Error(`expected HTMLInputElement, got ${el.tagName}`)
  }

  return el
}
