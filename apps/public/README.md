# Public

`apps/public` is the independently deployable Public Edge component.

It may own guest/public surfaces, remote staff gateway surfaces, durable relay state, safe projections/snapshots, Public-owned storage/migrations and limited emergency/recovery tooling for Public itself.

It is never the primary Business Authority and must not become a full clone of Local business data. Canonical business mutations must be revalidated/committed by Local through versioned contracts.
