# Contracts

`contracts` owns versioned interfaces between independently releasable SOKNA V3 components.

At minimum, contracts cover Local↔Public and Local↔Windows Runtime compatibility. Each contract change must declare version/compatibility requirements, consumer impact and regression tests.

Rolling upgrades must not rely on undocumented coupling. If a component requires a newer contract, its package metadata must declare that dependency before activation.
