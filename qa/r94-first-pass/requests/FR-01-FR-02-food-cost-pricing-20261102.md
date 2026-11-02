# FR-01 / FR-02 — Ingredient Cost Preview & All-Products Pricing Report

Status: **newly requested capabilities / source review done / implementation NOT_STARTED** (2026-11-02). These are **not** bugs confirmed in production and do not alter the original U-01..U-44 or S-001..S-162 counts.

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
