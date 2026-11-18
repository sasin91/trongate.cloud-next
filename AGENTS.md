# Code conventions

- Follow Trongate module conventions. Keep application code in its owning module,
  use module models for persistence and traits for shared model logic.
- Use K&R PHP style: opening braces on the same line, four-space indentation,
  and readable multiline method and control-flow bodies.
- Preserve engine/Core.php routing conventions. Supporting service classes are
  not public controllers; keep them in module services directories.
