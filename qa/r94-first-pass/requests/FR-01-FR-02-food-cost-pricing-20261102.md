# FR-01 / FR-02 — Ingredient Cost Preview & All-Products Pricing Report

Status: **SOURCE CANDIDATES IMPLEMENTED / NOT INSTALLED / UAT NOT RUN** (2026-11-02). These are **not** bugs confirmed in production and do not alter the original U-01..U-44 or S-001..S-162 counts.

## Grounded current implementation
- Local canonical `inventory_recipe_versions` / `inventory_recipe_components` already store active ingredient quantities in g, ml or count; recipe versions support reviewed / estimated / incomplete provenance.
- Local `InventoryOrderService::recipeEditorSnapshot()` supplies ingredient IDs, quantities and names to `catalog-workspace.js`, **but not** `inventory_balances.average_unit_cost / cost_status` or a computed sum.
- Local inventory stores weighted-average unit cost and cost-quality; do not treat unknown/partial cost as zero.
- Existing `ReportingService::marginReport()` calculates historical **settled-order contribution margin**, not a current-SKU pricing worksheet, and currently limits item aggregates to the top 50 sold products.

## FR-01 — In-product editor preview
Show per-ingredient quantity × base-unit average cost, total recipe food cost, catalog selling price, material cost ratio and difference while editing an item. The preview must be read-only and never move inventory or alter a sales transaction. Preserve the current recipe versioning, quality and permission contracts. Different size/SKU and bundle/composite components must not be silently combined or double counted. Unknown cost/partial recipe explicitly blocks an authoritative total.

## FR-02 — All catalog pricing report
New management-only view for all current sellable catalog SKUs including those **with no recent sales**. Columns: SKU/name/category/size, current sale price, estimated active-recipe ingredient cost, price–materials difference, food cost %, recipe/cost coverage, cost basis date. Filtering, sorting (highest food-cost %, no recipe, missing prices), link to product editor and export are in scope. Preserve historical settled-order margin reporting separately; current catalog list price minus estimated ingredients is **not accounting net profit**.

## Verification / acceptance criteria
FC-01..FC-14 cover unit normalization, cost provenance, quantity × unit cost, coverage, safe nonmutating preview, variants/bundles, whole catalog including no-sales, comparability columns, filters, cost basis date, permission-gated export, separate historic vs current cost views and optional packaging/wastage without invented estimates. See the updated spreadsheet and Markdown master inventory in Project Library.

Owner groups: **G + E + D**. Priority suggested **P1**. No code, UAT or install has been performed for these FR entries. Existing R94 grouped fixes continue without being superseded.

## Forward-only source delivery — FR-01 + FR-02

Full SHA-fenced managed-source candidate files are staged separately under `qa/r94-first-pass/forward-foodcost/local/` (10 changed files). This separate subtree is intentional: existing root `overlays/local/src/Domain/Reporting/ReportingService.php` and `overlays/local/assets/catalog-workspace.js` were assembled from **different earlier baselines** and may not be overwritten blindly.

The forward ReportingService is composed over the R94 sales/day-coverage candidate, and forward Catalog JS is composed over the B-03 catalog warning candidate. New `CatalogFoodCost.php` is explicitly included from `bootstrap.php` (manual require architecture, not PSR-4 auto-discovery). Report GET is restricted to **both** `shift_supervision` AND `inventory_cost_view`. Catalog recipe snapshot only includes costs for actors with `inventory_cost_view` and existing `inventory_manage` rights; otherwise amounts are masked. No raw ingredient costs reach Guest/Public.

The catalog editor gets a read-only approximate materials subtotal/food cost ratio preview. The Local manager's Reports workspace gets an independent current-catalog pricing section with all active SKUs, including those without sales, filters, search, CSV, and per-item cost-quality coverage. Bundles are deliberately marked for manual review instead of summing potentially duplicated components. Source-only ZIP and Persian ledger are in the project Library under `/SoknaCafeV3-Recovery-20261029/R94-Grouped-Fixes`.

18 calculator unit assertions and PHP/JS syntax checks passed. **No live MariaDB integration, browser end-to-end, budget calculation completeness for composite bundles, Windows host acceptance, or installer/delta has occurred.** Keep the prior U-list open and perform comprehensive QA only after all queued fixes.
