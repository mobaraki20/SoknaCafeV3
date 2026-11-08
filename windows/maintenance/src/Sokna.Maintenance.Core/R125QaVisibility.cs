using System.Runtime.CompilerServices;
// Only the isolated Windows QA binary can inject a synthetic host qualification.
// The production Supervisor always invokes the real read-only Local/DB inspector.
[assembly: InternalsVisibleTo("Sokna.R125ArchiveIntegrationProbe")]
