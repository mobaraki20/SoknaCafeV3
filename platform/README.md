# Platform

M9 Platform owns supported machine prerequisites and provisioning only: PHP/runtime extensions, Apache binding/TLS integration, MariaDB connectivity requirements and machine health checks.

Platform never owns active Local/Public business files. Setup may invoke Platform provisioning internally; component activation remains Packaging-owned. Shared prerequisites are either already installed or supplied by a release-verified offline prerequisite bundle. End-user runtime downloading is not an implicit lifecycle path.
