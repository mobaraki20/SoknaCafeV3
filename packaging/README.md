# Packaging

`packaging` owns composition/build metadata for independently releasable V3 artifacts.

Canonical Local/Public releases are full immutable packages. Delta packages may exist only as optional transport optimization. Update, Repair and Recovery are distinct lifecycle operations.

Packaging must preserve component ownership: the Platform package must not silently become owner of active Local application files, and Local/Public packages must not mutate unrelated Runtime/Platform components unless an explicit compatibility/dependency rule requires it.
