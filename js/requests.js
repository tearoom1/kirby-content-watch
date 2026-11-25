// Small helpers against late answers in the Panel field.

// Runs requests where only the latest one may apply its answer.
// Kirby 6 can reuse a field instance for the next page of the same
// blueprint, so an answer for the previous page must not land there.
export function latestRequest () {
  let current = 0;

  return {
    // runs `request` and hands its answer to `apply`, unless
    // a newer run or `invalidate()` came in meanwhile
    async run (request, apply) {
      const token = ++current;
      const result = await request();

      if (token !== current) {
        return false;
      }

      apply(result);
      return true;
    },

    // drops the answers of all pending runs
    invalidate () {
      current++;
    }
  };
}

// Remembers the dialog open right now, so it can be closed later
// without closing a dialog that was opened in the meantime
export function ownDialog (dialog) {
  const id = dialog?.id ?? null;

  return {
    close () {
      // without an id, any open dialog could be hit
      if (id !== null) {
        dialog.close(id);
      }
    }
  };
}
