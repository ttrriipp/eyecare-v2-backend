# Simple Inventory JSON Handoff

## Problem Statement
How might we build a minimal, architecture-free PHP web app that proves out file-based data interchange — using JSON as both an input to processing and the output of processing — while satisfying a course requirement that data move between two separate applications?

## Recommended Direction
Two standalone, single-file PHP scripts sharing one JSON file:

- **`shop.php`** — the inventory owner. A plain HTML form posts to itself, appends a product (name, quantity, price) to `inventory.json` via `json_encode`, and lists current stock by reading the same file back with `json_decode`. No database, no framework, no classes required.
- **`warehouse.php`** — a genuinely separate "application" that never writes to `inventory.json`. It reads that file, computes real output (total inventory value, a low-stock list for items under a threshold), and writes the result to its own file, `stock_report.json`, with a timestamp. The report is also rendered on the page.

This is the same amount of code as a single self-contained script, but it removes the main grading risk: instead of hoping a grader accepts "one script reading its own export" as satisfying "another application," the two-script split makes that requirement literal. It also cleanly covers "JSON used in processing" (warehouse.php consumes inventory.json) and "JSON as a result of a process" (warehouse.php produces stock_report.json) — both halves of the rubric line, not just one.

## Key Assumptions to Validate
- [ ] Two standalone PHP scripts in the same repo count as "another application" for grading — if an actual rubric/spec exists beyond the pasted brief, confirm this reading before building.
- [ ] JSON alone (vs. XML, or both) satisfies "either XML or JSON" — the brief phrases it as a choice, not a requirement to do both.
- [ ] File-based handoff (no HTTP call between the two scripts) satisfies "or from another application" — the brief's wording ("a file **or** another application") suggests file-based is already sufficient on its own, and this design does both simultaneously as a hedge.

## MVP Scope
**In:**
- `shop.php`: add-product form, writes/reads `inventory.json`
- `warehouse.php`: reads `inventory.json`, computes total value + low-stock list, writes `stock_report.json`, displays result
- Bare-bones HTML, no CSS framework
- Lives in a new standalone folder at the repo root (plain PHP, not part of the Laravel `app/`), e.g. `simple-inventory-app/`

**Out (see Not Doing below)**

## Not Doing (and Why)
- **Database** — the assignment explicitly wants file-based data sharing, not persistence infrastructure.
- **Authentication / authorization** — out of scope for a "doesn't need proper architecture" exercise.
- **Input validation beyond not crashing** — not what's being graded; adds code with no rubric payoff.
- **Both XML and JSON** — the brief offers a choice; picking JSON avoids extra parsing code (`SimpleXMLElement`) for no rubric benefit, unless the instructor specifically requires XML.
- **Concurrent-write locking on `inventory.json`** — a single-grader, single-user demo doesn't need it; would be over-engineering for this context.
- **CSS/styling polish** — time is tight; functionality is what's graded.

## Open Questions
- Is there an actual rubric/spec beyond the pasted brief that specifies exact filenames, formats, or a required use of XML specifically?
- What's the actual due date/time?
- Does the folder need a specific name or location the instructor expects (e.g. matching a submission template)?
