/**
 * Merge a freshly fetched collection into the one already on screen.
 *
 * The dashboard refreshes every few seconds. Replacing the array wholesale
 * hands Vue a new object for every row, and anything a component keyed on that
 * object — an expanded error, a checked box, the row the mouse is over — is
 * rebuilt from scratch. Merging keeps the identity of the rows that were
 * already there: the same object, the same DOM node, patched in place with
 * whatever changed.
 *
 * Rows that disappeared server-side leave; new ones arrive in the server's
 * order, which is the order the operator is reading.
 *
 * @param {Array<object>} current  The array on screen; mutated in place.
 * @param {Array<object>} incoming The freshly fetched rows.
 * @param {string} key             The identity attribute, 'id' unless said otherwise.
 * @returns {Array<object>} `current`, for chaining.
 */
export function reconcileById(current, incoming, key = 'id') {
  const rows = Array.isArray(incoming) ? incoming : []
  const known = new Map(current.map((row) => [row[key], row]))

  const merged = rows.map((next) => {
    const existing = known.get(next[key])

    if (!existing) return next

    // Keys the server stopped sending are removed rather than left behind:
    // a stale `error` on a row that has since succeeded would keep the red
    // panel open under a green badge.
    for (const attribute of Object.keys(existing)) {
      if (!(attribute in next)) delete existing[attribute]
    }

    Object.assign(existing, next)

    return existing
  })

  current.splice(0, current.length, ...merged)

  return current
}

/**
 * Same idea for a single object: keep the object, move its contents.
 *
 * `arrays` names the attributes that are collections of identified rows, so
 * they are reconciled rather than replaced.
 *
 * @param {object} current
 * @param {object} incoming
 * @param {string[]} arrays
 * @returns {object} `current`
 */
export function reconcileObject(current, incoming, arrays = []) {
  for (const attribute of Object.keys(current)) {
    if (!(attribute in incoming)) delete current[attribute]
  }

  for (const [attribute, value] of Object.entries(incoming)) {
    if (arrays.includes(attribute) && Array.isArray(current[attribute])) {
      reconcileById(current[attribute], value)

      continue
    }

    current[attribute] = value
  }

  return current
}
