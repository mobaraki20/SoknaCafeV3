# SOKNA Windows Runtime 1.0.5 release patch chain

Source of truth: `mobaraki20/SoknaCafeV3`.
Base: `release/windows-services-1.0.16`, commit `fbc2d63910ecb95b790b15b97448feecb113bacd`, Runtime `1.0.3`.

Apply in order:
1. `ARCH05-deadline.patch` — bounded trigger deadline/cancellation and cadence anchoring.
2. `ARCH06-transport-resilience.patch` — HTTP failure classification, Retry-After, bounded exponential backoff/jitter, retry budget and identity-preserving transient retry.

Windows Services `1.0.17` CI verifies the pinned base `Program.cs` blob before applying this chain, builds Runtime `1.0.5`, runs Runtime self-tests and release qualification, builds the installer, and only then publishes the GitHub release.

Print Agent remains `6.2.9`; Local Web/Public Edge/business data are outside this Windows release.
