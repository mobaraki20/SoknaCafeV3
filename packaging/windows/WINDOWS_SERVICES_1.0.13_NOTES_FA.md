# SOKNA Windows Services 1.0.13 â€” H186 Receipt Renderer

- Windows Services: 1.0.13
- Print Agent: 6.2.9
- Runtime: 1.0.2 (unchanged)
- Base qualified lineage: Windows Services 1.0.12 / Print Agent 6.2.8, commit 6d8941b958373deefe0560125e823a8c0ec88684.
- Customer receipt item separators now honor separator_style. The normal solid style uses a thermal-safe effective two-logical-pixel rule instead of the old dotted 1px hairline.
- Table display text normalizes Latin digits to Persian digits.
- No Runtime behavior/source change is included in this release.
- Physical thermal-printer verification remains an installed-environment UAT check and is not fabricated by CI.
