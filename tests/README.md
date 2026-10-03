# Tests

V3 testing follows component ownership and contract boundaries.

Required principles:
- path/component-aware CI;
- contract tests for every cross-component boundary;
- UI contract/browser/visual gates for migrated user-facing scopes;
- explicit UAT for device/IME/printing/Windows behaviors that headless tests cannot prove;
- clean-install/repair/uninstall acceptance only when affected by Platform/Packaging/Windows changes or at release-candidate gates;
- rollback/recovery evidence for lifecycle changes.

A passing isolated page/component test is not sufficient when a shared Design-System or contract owner changed; all declared consumers must be covered.
